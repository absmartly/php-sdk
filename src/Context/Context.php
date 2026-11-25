<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Assignment;
use ABSmartly\SDK\AudienceMatcher;
use ABSmartly\SDK\Exception\InvalidArgumentException;
use ABSmartly\SDK\Exception\LogicException;
use ABSmartly\SDK\Exception\RuntimeException;
use ABSmartly\SDK\Experiment;
use ABSmartly\SDK\ExperimentVariables;
use ABSmartly\SDK\Exposure;
use ABSmartly\SDK\GoalAchievement;
use ABSmartly\SDK\PublishEvent;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Utils;
use ABSmartly\SDK\VariableParser;
use ABSmartly\SDK\VariantAssigner;

use Exception;
use JsonException;
use Throwable;

use function array_intersect_key;
use function array_replace;
use function array_slice;
use function array_splice;
use function array_values;
use function count;
use function get_object_vars;
use function gettype;
use function hash;
use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function microtime;
use function min;
use function property_exists;
use function spl_object_id;
use function sprintf;
use function strpos;
use function trim;

use const JSON_THROW_ON_ERROR;

class Context {

	private SDK $sdk;

	private ContextEventHandler $eventHandler;
	private ContextEventLogger $eventLogger;
	private ContextDataProvider $dataProvider;
	private VariableParser $variableParser;
	private AudienceMatcher $audienceMatcher;

	private array $units = [];
	private bool $failed = false;

	private ?ContextData $data = null;

	private array $index = [];
	private array $indexVariables = [];


	private array $hashedUnits;
	private array $assigners = [];
	private array $assignmentCache;

	private array $exposures = [];
	/**
	 * Assignment that produced each queued exposure, aligned by index with $exposures. Used to clear the
	 * assignment's cached `exposed` flag if its exposure is dropped by queue-cap eviction, so a later read can
	 * re-queue it. Kept separate from the Exposure so it never reaches the published payload.
	 */
	private array $exposureAssignments = [];
	private array $achievements = [];

	private array $attributes = [];
	private array $overrides = [];
	private array $cassignments = [];

	private int $pendingCount = 0;
	private bool $closed = false;
	private bool $finalizing = false;
	private bool $ready;
	private int $attrsSeq = 0;
	private ?array $evaluationAttributes = null;
	private int $evaluationAttributesSeq = -1;
	/** @var array<string, string> */
	private array $ruleKeys = [];
	/** @var array<string, array|null> null when the rules JSON is invalid. */
	private array $parsedRules = [];
	/** @var array<string, object[]> Each covered experiment's holdouts, unique and sorted by id. */
	private array $experimentHoldouts = [];
	/** Whether any assignment may carry holdouts: set once some installed data covered an experiment. */
	private bool $hasHoldouts = false;
	/**
	 * @var array<int, Assignment> Holdout arms, by spl_object_id(), whose exposure the retained-event cap dropped while
	 * they were the cached arm, and that are not queued again yet. Exposed assignments re-check their holdout arms only
	 * while one is outstanding, not on every read.
	 */
	private array $droppedHoldoutArms = [];
	/**
	 * @var array<string, Assignment> Holdout arms by "<holdout id>:<unit type>", kept across refreshes and reused
	 * while the holdout's iteration is unchanged, so a same-iteration seed or split edit never re-randomizes a unit.
	 */
	private array $holdoutAssignmentCache = [];
	/**
	 * @var array<string, true> Experiments whose decision is being made. A nested read from a callback (an audience
	 * getter) builds its own decision rather than serving or exposing the unfinished one.
	 */
	private array $deciding = [];
	/**
	 * @var array<string, true> Experiments whose decision is already being made again because a callback installed new
	 * data during it. A further install during that one serves the nested decision rather than revalidating again, so a
	 * callback that refreshes on every read can't recurse without bound.
	 */
	private array $revalidating = [];
	/** Counts data installs, so a decision can tell whether a callback installed new data after another was made. */
	private int $installs = 0;
	/**
	 * @var array<string, array{0: Assignment, 1: int}> Latest completed decision and the install count it was read on,
	 * by experiment. A nested read followed by a refresh must be distinguished from a refresh followed by that read.
	 */
	private array $decidedOn = [];
	/** Set while the first load installs experiments, so a callback's refresh() can't be overwritten by the rest of it. */
	private bool $firstLoadInProgress = false;
	private ?Throwable $readyError = null;

	/**
	 * Upper bound on the number of exposures and goals retained after a failed publish. When a long-lived worker
	 * holds one context through a collector outage, this stops the retry queue (and each published batch) from
	 * growing without limit: the oldest events are dropped and an Error event is logged. 0 means no cap.
	 */
	public int $maxRetainedEvents = 10000;

	public function isReady(): bool {
		return $this->ready;
	}

	public function isFailed(): bool {
		return $this->failed;
	}

	public function isClosed(): bool {
		return $this->closed;
	}

	public function isFinalizing(): bool {
		return $this->finalizing && !$this->closed;
	}

	public function isFinalized(): bool {
		return $this->isClosed();
	}

	public function finalize(): void {
		$this->close();
	}

	public function readyError(): ?Throwable {
		return $this->readyError;
	}

	public function getCustomFieldKeys(): array {
		$this->checkReady();
		$keys = [];
		foreach ($this->index as $experimentVariables) {
			foreach ($experimentVariables->data->customFieldValues ?? [] as $field) {
				if (isset($field->name)) {
					$keys[$field->name] = true;
				}
			}
		}
		return array_keys($keys);
	}

	public function getCustomFieldValueType(string $experimentName, string $key): ?string {
		$this->checkReady();
		$experiment = $this->getExperiment($experimentName);
		if ($experiment === null || empty($experiment->data->customFieldValues)) {
			return null;
		}

		foreach ($experiment->data->customFieldValues as $field) {
			if (isset($field->name) && $field->name === $key) {
				return $field->type ?? null;
			}
		}

		return null;
	}

	public function pending(): int {
		return $this->pendingCount;
	}

	public static function getTime(): int {
		return (int) (microtime(true) * 1000);
	}

	private function __construct(SDK $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ?ContextData $contextData = null, bool $pending = false) {
		$this->sdk = $sdk;
		$this->dataProvider = $dataProvider;
		$this->setUnits($contextConfig->getUnits());
		$this->setOverrides($contextConfig->getOverrides());
		$this->setCustomAssignments($contextConfig->getCustomAssignments());
		$this->setAttributes($contextConfig->getAttributes());

		if ($logger = $contextConfig->getEventLogger()) {
			$this->setEventLogger($logger);
		}

		$this->audienceMatcher = new AudienceMatcher();
		$this->variableParser = new VariableParser();

		if ($pending) {
			$this->ready = false;
			return;
		}

		try {
			$this->ready = true;
			if (!$contextData) {
				$data = $this->dataProvider->getContextData();
			}
			else {
				$data = $contextData;
			}

			$this->setData($data, true);
			$this->logEvent(ContextEventLoggerEvent::Ready, $data);
		}
		catch (Exception $exception) {
			$this->setDataFailed($exception);
			$this->logError($exception);
		}
	}

	private function setEventLogger(ContextEventLogger $eventLogger): Context {
		$this->eventLogger = $eventLogger;
		return $this;
	}

	private function setEventHandler(ContextEventHandler $eventHandler): Context {
		$this->eventHandler = $eventHandler;
		return $this;
	}

	/**
	 * Indexes $data. The initial load ($initial) installs each experiment as it goes, as in 1.1.0, so an Error callback
	 * for a malformed experiment reads the experiments loaded before it. A refresh builds the indexes aside and installs
	 * them only once all of the data is processed, so that callback still reads the previous data (or none, on a pending
	 * context), and a failure (or a logger that throws) leaves the previous data and its decisions in use.
	 */
	private function setData(ContextData $data, bool $initial = false): void {
		// Restored, not cleared, so a nested initial load from a callback leaves the outer one's flag set.
		$wasInProgress = $this->firstLoadInProgress;
		$this->firstLoadInProgress = $wasInProgress || $initial;
		try {
			$this->indexData($data, $initial);
		}
		finally {
			$this->firstLoadInProgress = $wasInProgress;
		}
	}

	private function indexData(ContextData $data, bool $initial): void {
		$index = [];
		$indexVariables = [];
		$ruleKeys = [];
		$experimentHoldouts = [];
		$hasHoldouts = $this->hasHoldouts;
		if ($initial) {
			$this->data = $data;
			$this->index = &$index;
			$this->indexVariables = &$indexVariables;
			$this->ruleKeys = &$ruleKeys;
			$this->parsedRules = [];
			$this->experimentHoldouts = &$experimentHoldouts;
			$this->hasHoldouts = &$hasHoldouts;
		}
		$holdoutsById = [];
		$environment = $this->sdk->getClient()->getEnvironment();

		$holdouts = property_exists($data, 'holdouts') && is_array($data->holdouts) ? $data->holdouts : [];
		foreach ($holdouts as $holdout) {
			if (($servable = self::servableHoldout($holdout)) !== null) {
				$holdoutsById[$servable->id] = $servable;
			}
		}

		foreach ($data->experiments as $experiment) {
			$experimentVariables = new ExperimentVariables();
			$variableKeys = [];
			try {
				$experimentVariables->data = new Experiment($experiment);
				$experimentVariables->variables = [];

				foreach ($experiment->variants as $variant) {
					$parsed = empty($variant->config) ? null : $this->variableParser->parse($experiment->name, $variant->config);
					if (!$parsed) {
						$experimentVariables->variables[] = (object) [];
						continue;
					}

					foreach (get_object_vars($parsed) as $key => $val) {
						$variableKeys[] = $key;
					}
					$experimentVariables->variables[] = $parsed;
				}
			}
			catch (RuntimeException | JsonException | \TypeError $exception) {
				// Logged unchanged: v1.0.3 reported a malformed audience as this same JsonException.
				$this->logError($exception);
				continue;
			}

			foreach ($variableKeys as $key) {
				self::addVariableExperiment($indexVariables, $key, $experimentVariables);
			}
			$index[$experiment->name] = $experimentVariables;
			if ($experimentVariables->data->assignmentRules !== null) {
				$ruleKeys[$experiment->name] = $experimentVariables->data->assignmentRules . ':' . $environment;
			}
			$holdoutIds = null;
			try {
				$holdoutIds = $experimentVariables->data->sdkHoldoutIds();
			}
			catch (\Error $exception) {
			}
			if (is_array($holdoutIds) && $holdoutIds !== [] && $holdoutsById !== []) {
				$holdouts = [];
				foreach ($holdoutIds as $holdoutId) {
					if (isset($holdoutsById[$holdoutId])) {
						$holdouts[$holdoutId] = $holdoutsById[$holdoutId];
					}
				}
				if ($holdouts !== []) {
					ksort($holdouts);
					$experimentHoldouts[$experiment->name] = array_values($holdouts);
					$hasHoldouts = true;
				}
			}
		}

		$hasHoldouts = $hasHoldouts || $this->hasHoldouts;
		++$this->installs;
		unset($this->index, $this->indexVariables, $this->ruleKeys, $this->experimentHoldouts, $this->hasHoldouts);
		$this->data = $data;
		$this->index = $index;
		$this->indexVariables = $indexVariables;
		$this->ruleKeys = $ruleKeys;
		$this->parsedRules = [];
		$this->experimentHoldouts = $experimentHoldouts;
		$this->hasHoldouts = $hasHoldouts;

		// Only an exposed cached assignment can queue a dropped arm again (a new assignment queues its arms anyway), so a
		// mark whose arm no cached assignment pins any more is forgotten.
		if ($this->droppedHoldoutArms !== []) {
			$pinned = [];
			foreach ($this->assignmentCache ?? [] as $assignment) {
				foreach ($assignment->getSDKState('holdoutAssignments') ?? [] as $arm) {
					if ($arm !== null) {
						$pinned[spl_object_id($arm)] = true;
					}
				}
			}
			$this->droppedHoldoutArms = array_intersect_key($this->droppedHoldoutArms, $pinned);
		}
	}

	/**
	 * A holdout entry is used only when it has the fields its arm is drawn from and a non-empty numeric split, as in
	 * JS (`holdout.split && holdout.split.length > 0`); anything else gives null, so experiments it would have
	 * covered assign normally. Integral floats (`11.0`) count as integers, since JS can't tell them apart. The result
	 * is a plain copy the SDK owns, so a provider's typed or readonly properties never constrain it and the caller's
	 * object is never changed. Unknown fields are ignored.
	 */
	private static function servableHoldout($holdout): ?object {
		if (!is_object($holdout)) {
			return null;
		}

		$servable = [];
		foreach (['id', 'iteration', 'seedHi', 'seedLo'] as $field) {
			$value = isset($holdout->{$field}) ? Utils::integralValue($holdout->{$field}) : null;
			if ($value === null) {
				return null;
			}
			$servable[$field] = $value;
		}

		if (!isset($holdout->name, $holdout->split) || !is_string($holdout->name) || !is_array($holdout->split)
			|| $holdout->split === []) {
			return null;
		}

		foreach ($holdout->split as $value) {
			if (!is_int($value) && !is_float($value)) {
				return null;
			}
		}

		$servable['name'] = $holdout->name;
		$servable['split'] = $holdout->split;
		return (object) $servable;
	}

	/**
	 * Indexes $experimentVariables under $key, keeping the per-key list sorted and unique by experiment id, as the
	 * JS SDK does (insertUniqueSorted by data->id), so resolveVariableValue visits the candidates by ascending id.
	 */
	private static function addVariableExperiment(array &$indexVariables, string $key, ExperimentVariables $experimentVariables): void {
		if (!isset($indexVariables[$key])) {
			$indexVariables[$key] = [$experimentVariables];
			return;
		}

		$list = $indexVariables[$key];
		$id = $experimentVariables->data->id;
		$insertAt = count($list);
		foreach ($list as $i => $existing) {
			if ($existing->data->id === $id) {
				return;
			}
			if ($id < $existing->data->id) {
				$insertAt = $i;
				break;
			}
		}

		array_splice($list, $insertAt, 0, [$experimentVariables]);
		$indexVariables[$key] = $list;
	}

	private function setDataFailed(?Throwable $exception = null): void {
		$this->indexVariables = [];
		$this->index = [];
		$this->experimentHoldouts = [];
		$this->data = new ContextData();
		$this->failed = true;
		$this->ready = true;
		$this->readyError = $exception;
	}

	public static function createFromContextConfig(SDK $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ContextEventHandler $handler, ?ContextData $contextData = null): Context {
		$context = new Context($sdk, $contextConfig, $dataProvider, $contextData);
		$context->setEventHandler($handler);

		return $context;
	}

	public static function createPending(SDK $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ContextEventHandler $handler): Context {
		$context = new Context($sdk, $contextConfig, $dataProvider, null, true);
		$context->setEventHandler($handler);

		return $context;
	}

	public function setContextDataFailed(Throwable $error): void {
		if ($this->ready) {
			return;
		}

		$this->setDataFailed($error);
		$this->logError($error);
	}

	public function setContextData(ContextData $contextData): void {
		if ($this->ready) {
			return;
		}

		try {
			$this->setData($contextData, true);
			$this->failed = false;
			$this->ready = true;
			$this->logEvent(ContextEventLoggerEvent::Ready, $contextData);
		} catch (Throwable $exception) {
			$this->setDataFailed($exception);
			$this->logError($exception);
		}
	}

	/**
	 * Mirrors the JS SDK `_checkReady(expectNotFinalized)`: reads throw before
	 * the context is ready and, unless $expectNotFinalized is false, after it
	 * has been finalized.
	 */
	private function checkReady(bool $expectNotFinalized = true): void {
		if (!$this->isReady()) {
			throw new LogicException('ABSmartly Context is not yet ready');
		}

		if ($expectNotFinalized) {
			$this->checkNotClosed();
		}
	}

	public function getExperiment(string $experimentName): ?ExperimentVariables {
		return $this->index[$experimentName] ?? null;
	}

	public function getExperiments(): array {
		$this->checkReady(false);
		$return = [];
		foreach ($this->index as $experimentVariables) {
			$return[] = $experimentVariables->data->name;
		}

		return $return;
	}

	public function customFieldValue(string $experimentName, string $fieldName) {
		$this->checkReady(false);
		$experiment = $this->getExperiment($experimentName);
		if ($experiment === null || empty($experiment->data->customFieldValues)) {
			return null;
		}

		$field = null;
		foreach ($experiment->data->customFieldValues as $candidate) {
			if (isset($candidate->name) && $candidate->name === $fieldName) {
				$field = $candidate;
				break;
			}
		}

		if ($field === null || !isset($field->value)) {
			return null;
		}

		$value = $field->value;
		$type = $field->type ?? null;

		if ($type === 'json' && is_string($value)) {
			return $this->decodeCustomFieldJson($value, $experimentName, $fieldName);
		}

		if ($type === 'number' && is_string($value)) {
			if (strpos($value, '.') !== false) {
				return (float) $value;
			}
			return (int) $value;
		}

		if (strpos($type ?? '', 'boolean') === 0 && is_string($value)) {
			return $value === 'true' || $value === '1';
		}

		return $value;
	}

	private function decodeCustomFieldJson(string $json, string $experimentName, string $fieldName) {
		try {
			return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception) {
			$this->logError(new InvalidArgumentException(
				sprintf('Failed to parse JSON custom field value "%s" for experiment "%s": %s', $fieldName, $experimentName, $exception->getMessage()),
				0,
				$exception
			));
			return null;
		}
	}

	private function audienceMatches(Experiment $experiment, Assignment $assignment): bool {
		if ($experiment->audience === null && $experiment->assignmentRules === null && $assignment->ruleKey === '') {
			// Nothing here depends on attributes.
			return true;
		}

		$ruleKey = $this->ruleKey($experiment);

		$ruleKeyChanged = $ruleKey !== $assignment->ruleKey;

		if ($ruleKeyChanged && $ruleKey === '' && ($assignment->ruleVariant !== null || $assignment->ruleOverride)) {
			return false;
		}

		if ($this->attrsSeq > $assignment->attrsSeq || $ruleKeyChanged) {
			if ($ruleKey !== '') {
				// An exposed split resolved without its unit keeps resolving as if the unit were still missing, so a
				// unit set afterwards cannot switch the variant the user already saw.
				$rule = $this->resolveRule($experiment, $assignment->isUnitPinnedMissing);
				$ruleVariant = $rule['variant'] ?? null;
				if ($ruleVariant !== $assignment->ruleVariant) {
					return false;
				}

				$assignment->isRuleMissingUnit = $rule['isMissingUnit'] ?? false;
				$assignment->isUnitPinnedMissing = $assignment->isUnitPinnedMissing
					|| ($assignment->exposed && $assignment->isRuleMissingUnit);
			}

			// A suppressed assignment never evaluated its audience, so an attribute change cannot make it stale.
			if (!$assignment->ruleOverride && !$assignment->getSDKState('suppressed', false)
				&& !empty($experiment->audience) && !empty((array) $experiment->audience)) {
				$result = $this->audienceMatcher->evaluate($experiment->audience, $this->getEvaluationAttributes());
				$newAudienceMismatch = $result !== null ? !$result : $assignment->audienceMismatch;

				if ($newAudienceMismatch !== $assignment->audienceMismatch) {
					return false;
				}
			}

			$assignment->ruleKey = $ruleKey;
			$assignment->attrsSeq = $this->attrsSeq;
		}
		return true;
	}

	/**
	 * Identifies the assignment rules a cached assignment was resolved against: the rules JSON and the environment
	 * they were matched in, or '' when the experiment has none. Built once per data install, so an unchanged read
	 * compares the same string rather than building and comparing a copy of the rules.
	 */
	private function ruleKey(Experiment $experiment): string {
		return $this->ruleKeys[$experiment->name] ?? '';
	}

	/**
	 * Resolves the first matching assignment rule to a variant, as the JavaScript SDK's _resolveRule. A split rule
	 * matched without its unit gives the full-on variant and is flagged for re-resolution once the unit is set.
	 *
	 * @return array{variant: int, isMissingUnit: bool}|null
	 */
	private function resolveRule(Experiment $experiment, bool $isUnitPinnedMissing): ?array {
		if ($experiment->assignmentRules === null) {
			return null;
		}

		if (!array_key_exists($experiment->name, $this->parsedRules)) {
			$this->parsedRules[$experiment->name] = $this->audienceMatcher->parseRules($experiment->assignmentRules);
		}

		$rules = $this->parsedRules[$experiment->name];
		if (empty($rules)) {
			return null;
		}

		$environment = $this->sdk->getClient()->getEnvironment();
		$action = $this->audienceMatcher->evaluateParsedRules(
			$rules,
			$environment !== '' ? $environment : null,
			$this->getEvaluationAttributes()
		);
		if ($action === null) {
			return null;
		}

		$variantCount = count($experiment->variants);
		if (isset($action['variant'])) {
			$isInBounds = $action['variant'] >= 0 && $action['variant'] < $variantCount;
			return $isInBounds ? ['variant' => $action['variant'], 'isMissingUnit' => false] : null;
		}

		if (count($action['split']) !== $variantCount) {
			return null;
		}

		$uid = $isUnitPinnedMissing ? null : ($this->units[$experiment->unitType] ?? null);
		if ($uid === null) {
			return ['variant' => $experiment->fullOnVariant, 'isMissingUnit' => true];
		}

		$assigner = $this->getVariantAssigner($experiment->unitType, $uid);
		return [
			'variant' => $assigner->assign($action['split'], $experiment->seedHi, $experiment->seedLo),
			'isMissingUnit' => false,
		];
	}

	/**
	 * Whether the cached assignment pins variant 0 independently of the custom variant, so an unchanged read
	 * can reuse it (and its exposure dedup) until a trigger rebuilds it: a strict audience exclusion (rebuilt
	 * by audienceMatches() when attributes change) or a missing unit (rebuilt once the unit is set). Full-on
	 * and not-eligible assignments are NOT pinned here: like JS, they are only reused when the custom variant
	 * equals the assigned variant, so a differing custom variant is detected as stale and re-exposed.
	 */
	private function customAssignmentIgnored(Assignment $assignment, $experiment): bool {
		// A strict audience exclusion is never assigned; attribute changes are still re-checked by audienceMatches().
		if ($assignment->audienceMismatch && !empty($experiment->data->audienceStrict)) {
			return true;
		}
		if (!$assignment->assigned) {
			// Missing unit: nothing changes until the unit is set, then the custom assignment must be evaluated.
			return !isset($this->units[$experiment->data->unitType]);
		}
		return false;
	}

	private function getAssignment(string $experimentName): Assignment {
		$experiment = $this->getExperiment($experimentName);

		if (isset($this->assignmentCache[$experimentName]) && !isset($this->deciding[$experimentName])) {
			$assignment = $this->assignmentCache[$experimentName];
			if (array_key_exists($experimentName, $this->overrides)) {
				$override = $this->overrides[$experimentName];
				if ($assignment->overridden && $assignment->variant === $override
					&& ($experiment === null || !$this->hasHoldouts
						// A covered override is rebuilt on a unit type change so its holdout arms are drawn for the new
						// unit; an uncovered one is reused as before.
						|| (($assignment->getSDKState('holdouts') === null || $assignment->unitType === $experiment->data->unitType)
							&& $this->holdoutSetMatches($experimentName, $assignment)))) {
					// override up-to-date
					return $assignment;
				}
			}
			else if ($experiment === null) {
				// A rule-won assignment is not "assigned" either, but its variant came from the
				// experiment's rules, so it must be rebuilt once the experiment is gone.
				if (!$assignment->assigned && !$assignment->ruleOverride
					&& (!$this->hasHoldouts || $assignment->id === 0 || $assignment->getSDKState('served', false))) {
					// previously not-running experiment
					return $assignment;
				}
			} else if (!array_key_exists($experimentName, $this->cassignments)
				|| $this->cassignments[$experimentName] === $assignment->variant
				|| $assignment->ruleOverride
				|| $assignment->getSDKState('suppressed', false)
				|| $this->customAssignmentIgnored($assignment, $experiment)) {
				// A matching rule wins over a custom assignment and a suppressed one is forced to control, so a
				// custom mismatch is not staleness.
				$experimentData = $experiment->data;
				if ($experimentData->id === $assignment->id
					&& $experimentData->unitType === $assignment->unitType
					&& $experimentData->iteration === $assignment->iteration
					&& $experimentData->fullOnVariant === $assignment->fullOnVariant
					&& $experimentData->trafficSplit === $assignment->trafficSplit
					&& $this->audienceMatches($experimentData, $assignment)
					&& (!$this->hasHoldouts || $this->holdoutSetMatches($experimentName, $assignment))
					// An audience getter that set the unit evicted this assignment while it was being checked (only an
					// assignment with holdout arms is evicted for a late unit, see invalidateAssignmentsMissingUnit).
					&& (!$this->hasHoldouts || ($this->assignmentCache[$experimentName] ?? null) === $assignment)) {
					// assignment up-to-date
					return $assignment;
				}
			}

			// A nested read during the check above (an audience getter) may have cached its own decision; validate that
			// one instead of replacing it, so its exposure isn't lost or queued twice.
			if (isset($this->assignmentCache[$experimentName]) && $this->assignmentCache[$experimentName] !== $assignment) {
				return $this->revalidateNested($experimentName, $this->assignmentCache[$experimentName]);
			}
		}

		// With holdouts, a callback during the cache check above (an audience getter) may have refreshed: build from the
		// installed experiment, so it and the holdouts read below come from the same data.
		if ($this->hasHoldouts) {
			$experiment = $this->getExperiment($experimentName);
		}
		$this->assignmentCache[$experimentName] = $assignment = new Assignment();
		$nested = isset($this->deciding[$experimentName]);
		$this->deciding[$experimentName] = true;
		try {
			$decided = $this->decide($experimentName, $experiment, $assignment);
			if ($nested) {
				$this->decidedOn[$experimentName] = [$decided, $this->installs];
			}
			return $decided;
		}
		catch (Throwable $exception) {
			// An aborted decision (a throwing audience getter) must not be served, or supply holdout arms, later.
			if (($this->assignmentCache[$experimentName] ?? null) === $assignment) {
				unset($this->assignmentCache[$experimentName]);
			}
			throw $exception;
		}
		finally {
			if (!$nested) {
				unset($this->deciding[$experimentName]);
			}
		}
	}

	private function revalidateNested(string $experimentName, Assignment $cached): Assignment {
		$stamp = $this->decidedOn[$experimentName] ?? null;
		$cachedOn = $stamp !== null && $stamp[0] === $cached ? $stamp[1] : -1;
		if ($cachedOn === $this->installs || isset($this->revalidating[$experimentName])) {
			return $cached;
		}
		$this->revalidating[$experimentName] = true;
		try {
			return $this->getAssignment($experimentName);
		}
		finally {
			unset($this->revalidating[$experimentName]);
		}
	}

	private function decide(string $experimentName, ?ExperimentVariables $experiment, Assignment $assignment): Assignment {

		$assignment->name = $experimentName;
		$assignment->eligible = true;

		// Resolved for overrides too, since their holdouts still fire exposures (JS _assign).
		if ($experiment !== null && isset($this->experimentHoldouts[$experimentName])) {
			$this->resolveHoldouts($assignment, $experiment->data, $this->experimentHoldouts[$experimentName]);
		}

		if (isset($this->overrides[$experimentName])) {
			if ($experiment) {
				$experimentVariables = $experiment;
				$assignment->id = $experiment->data->id;
				$assignment->unitType = $experimentVariables->data->unitType;
			}

			$assignment->overridden = true;
			$assignment->variant = $this->overrides[$experimentName];
		}
		elseif ($experiment) {
			$unitType = $experiment->data->unitType;
			$assignment->ruleKey = $this->ruleKey($experiment->data);
			$rule = $this->resolveRule($experiment->data, false);
			$assignment->ruleVariant = $rule['variant'] ?? null;
			$assignment->isRuleMissingUnit = $rule['isMissingUnit'] ?? false;

			if ($rule !== null) {
				// A matching rule is an explicit, author-specified assignment (flagged ruleOverride so it is excluded
				// from analysis): it wins over holdout suppression and the audience, traffic, full-on and custom
				// assignments.
				$assignment->variant = $rule['variant'];
				$assignment->ruleOverride = true;
			}
			elseif (!$assignment->getSDKState('suppressed', false)) {
				if (!empty($experiment->data->audience) && !empty((array) $experiment->data->audience)) {
					$result = $this->audienceMatcher->evaluate($experiment->data->audience, $this->getEvaluationAttributes());
					if (is_bool($result)) {
						$assignment->audienceMismatch = !$result;
					}
				}

				if (!empty($experiment->data->audienceStrict) && !empty($assignment->audienceMismatch)) {
					$assignment->variant = 0;
				}
				else if (empty($experiment->data->fullOnVariant)) {
					// Not a full-on experiment: assign via the traffic split only when the unit is present.
					// A missing unit leaves the assignment unassigned (control), so it never wins a variable key.
					if (($uid = $this->units[$experiment->data->unitType] ?? null) !== null) {
						$assigner = $this->getVariantAssigner($unitType, $uid);

						$eligible = $assigner->assign(
							$experiment->data->trafficSplit,
							$experiment->data->trafficSeedHi,
							$experiment->data->trafficSeedLo
						);
						if ($eligible === 1) {
							$custom = $this->cassignments[$experimentName] ?? null;
							if ($custom !== null) {
								$assignment->variant = $custom;
								$assignment->custom = true;
							}
							else {
								$assignment->variant = $assigner->assign(
									$experiment->data->split,
									$experiment->data->seedHi,
									$experiment->data->seedLo
								);
							}
						}
						else {
							$assignment->eligible = false;
							$assignment->variant = 0;
						}

						$assignment->assigned = true;
					}
				}
				else {
					$assignment->assigned = true;
					$assignment->variant = $experiment->data->fullOnVariant;
					$assignment->fullOn = true;
				}
			}

			$assignment->unitType = $unitType;
			$assignment->id = $experiment->data->id;
			$assignment->iteration = $experiment->data->iteration;
			$assignment->trafficSplit = $experiment->data->trafficSplit;
			$assignment->fullOnVariant = $experiment->data->fullOnVariant;
		}

		if (($experiment !== null) && $assignment->variant >= 0 && ($assignment->variant < count($experiment->data->variants))) {
			$assignment->variables = $experiment->variables[$assignment->variant];
		}

		// A callback during the decision (an audience getter) may have read this experiment itself: use its decision
		// (and its exposure), unless the callback installed new data after that decision was made, when it is validated
		// like any cached one, once: if a callback installs new data again during that, the nested decision is used and
		// the next read decides again. The callback may also have evicted this one, or set the unit after the holdout arms
		// were drawn without it (all arms share the experiment's unit type, and this assignment had no unit type yet for
		// setUnit() to match): decide again with the unit.
		$cached = $this->assignmentCache[$experimentName] ?? null;
		if ($cached !== null && $cached !== $assignment) {
			return $this->revalidateNested($experimentName, $cached);
		}
		if ($cached === null || ($assignment->getSDKState('holdoutAssignments') !== null && $assignment->getSDKState('holdoutAssignments')[0] === null
			&& isset($this->units[$experiment->data->unitType]))) {
			unset($this->assignmentCache[$experimentName]);
			return $this->getAssignment($experimentName);
		}

		$assignment->attrsSeq = $this->attrsSeq;

		return $assignment;
	}

	/**
	 * Pins the arm of each of the experiment's holdouts on $assignment and flags it suppressed when any of them holds
	 * the unit out. A holdout whose unit is missing gets a null arm, which never suppresses and is re-resolved once
	 * the unit is set (invalidateAssignmentsMissingUnit).
	 *
	 * @param object[] $holdouts
	 */
	private function resolveHoldouts(Assignment $assignment, Experiment $experiment, array $holdouts): void {
		$holdoutAssignments = [];
		foreach ($holdouts as $holdout) {
			$holdoutAssignment = $this->getHoldoutAssignment($holdout, $experiment->unitType);
			$holdoutAssignments[] = $holdoutAssignment;

			if ($holdoutAssignment !== null
				&& self::isHeldOutBy($holdoutAssignment->variant, $holdoutAssignment->holdoutArmCount, $experiment->fullOnVariant)) {
				$assignment->setSDKState('suppressed', true);
			}
		}

		$assignment->setSDKState('holdouts', $holdouts);
		$assignment->setSDKState('holdoutAssignments', $holdoutAssignments);
	}

	/**
	 * Arm 0 always holds the unit out. In a 3-arm holdout, arm 1 holds out only experiments that are not full-on.
	 * The arm count comes from the split, never from holdoutType.
	 */
	private static function isHeldOutBy(int $holdoutVariant, int $holdoutArmCount, int $fullOnVariant): bool {
		return $holdoutVariant === 0
			|| ($holdoutArmCount === 3 && $holdoutVariant === 1 && $fullOnVariant === 0);
	}

	/**
	 * The unit's arm of $holdout, hashed with the covered experiment's unit type (not the holdout's own), or null when
	 * that unit is not set. Requires an installed definition from experimentHoldouts. One arm is kept per holdout and
	 * unit type until the holdout's iteration changes.
	 */
	private function getHoldoutAssignment(object $holdout, string $unitType): ?Assignment {
		$cacheKey = $holdout->id . ':' . $unitType;
		$cached = $this->holdoutAssignmentCache[$cacheKey] ?? null;
		if ($cached !== null && $cached->iteration === $holdout->iteration) {
			return $cached;
		}

		$uid = $this->units[$unitType] ?? null;
		if ($uid === null) {
			return null;
		}

		$assignment = new Assignment();
		$assignment->id = $holdout->id;
		$assignment->name = $holdout->name;
		$assignment->iteration = $holdout->iteration;
		$assignment->unitType = $unitType;
		$assignment->variant = $this->getVariantAssigner($unitType, $uid)->assign($holdout->split, $holdout->seedHi, $holdout->seedLo);
		$assignment->assigned = true;
		$assignment->eligible = true;
		$assignment->setSDKState('holdoutArmCount', count($holdout->split));

		return $this->holdoutAssignmentCache[$cacheKey] = $assignment;
	}

	/**
	 * Whether $assignment was resolved against the experiment's current holdouts. Only each holdout's id and
	 * iteration count: a same-iteration seed or split edit must not force a duplicate exposure.
	 */
	private function holdoutSetMatches(string $experimentName, Assignment $assignment): bool {
		$pinned = $assignment->getSDKState('holdouts');
		$fresh = $this->experimentHoldouts[$experimentName] ?? null;
		if ($fresh === $pinned) {
			return true;
		}
		if ($fresh === null || $pinned === null || count($fresh) !== count($pinned)) {
			return false;
		}

		foreach ($fresh as $i => $holdout) {
			if ($holdout->id !== $pinned[$i]->id || $holdout->iteration !== $pinned[$i]->iteration) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Queues the experiment's own exposure, unless a holdout suppressed it or it is already exposed, and then each of
	 * its holdouts' exposures not yet queued, so an exposed assignment re-queues only arms the retained-event cap
	 * dropped. Callers skip exposed assignments while nothing is dropped, as in JS.
	 */
	private function triggerExposures(Assignment $assignment): void {
		if ($assignment->getSDKState('holdoutAssignments') === null) {
			$this->queueExposure($assignment);
			return;
		}

		// A throwing event logger must not strand the remaining exposures: each is queued (and marked exposed) before
		// it is logged, so all of them are queued first and the first logger error is re-thrown afterwards.
		$error = null;
		if (!$assignment->getSDKState('suppressed', false) || $assignment->overridden || $assignment->ruleOverride) {
			try {
				$this->queueExposure($assignment);
			}
			catch (Throwable $exception) {
				$error = $exception;
			}
		}
		else {
			$assignment->exposed = true;
			$assignment->setSDKState('served', true);
		}

		// Use the pinned arms, never a live re-resolution: an exposure callback may install a different decision epoch.
		foreach ($assignment->getSDKState('holdoutAssignments') as $i => $holdoutAssignment) {
			if ($holdoutAssignment !== null) {
				$holdoutAssignment->name = $assignment->getSDKState('holdouts')[$i]->name;
				try {
					$this->queueExposure($holdoutAssignment);
				}
				catch (Throwable $exception) {
					$error = $error ?? $exception;
				}
			}
		}

		if ($error !== null) {
			throw $error;
		}
	}

	public function getVariableValue(string $key, $defaultValue = null) {
		$this->checkReady();
		return $this->resolveVariableValue($key, $defaultValue, true);
	}

	public function peekVariableValue(string $key, $default) {
		$this->checkReady(false);
		return $this->resolveVariableValue($key, $default, false);
	}

	/**
	 * Resolves $key as the Java SDK's Context.getVariableAssignment does. The candidates are the experiments whose
	 * variants define $key, in ascending id order; the winner is the first one the unit is assigned, overridden or
	 * rule-assigned to. Only the winner is exposed (by getVariableValue, not peek), and when its variant lacks $key the
	 * default is returned: resolution never falls through to a later experiment because of the arm the unit got.
	 * With no winner, the first candidate a holdout held out supplies its control value (JS); a held-out candidate
	 * that does not win still queues its holdouts' exposures.
	 */
	private function resolveVariableValue(string $key, $default, bool $exposeAssignment) {
		$candidates = $this->indexVariables[$key] ?? [];
		$error = null;
		$selectedVariables = null;
		$liveCandidates = null;
		foreach ($candidates as $experimentVariables) {
			// Once a callback closes the context, a read already committed to throwing evaluates nothing more.
			if ($error !== null && $this->isClosed()) {
				throw $error;
			}
			$name = $experimentVariables->data->name;
			// With holdouts, a refresh by a callback earlier in the walk drops the candidates it removed or that no longer
			// define $key (an overridden one stays); without them the walk keeps main's snapshot of the candidates.
			if ($this->hasHoldouts) {
				// Unchanged unless a callback refreshed: the same array compares identical without a scan.
				if (($this->indexVariables[$key] ?? []) !== $candidates) {
					$candidates = $this->indexVariables[$key] ?? [];
					$liveCandidates = self::candidateNames($candidates);
				}
				if ($liveCandidates !== null && !isset($liveCandidates[$name]) && !array_key_exists($name, $this->overrides)) {
					continue;
				}
			}
			try {
				$assignment = $this->getAssignment($name);
			}
			catch (Throwable $exception) {
				throw $error ?? $exception;
			}
			// An audience getter may have refreshed while this candidate was decided: skip it, with no exposures, if the
			// installed data no longer has it define $key (unless overridden).
			if ($this->hasHoldouts && ($this->indexVariables[$key] ?? []) !== $candidates) {
				$candidates = $this->indexVariables[$key] ?? [];
				$liveCandidates = self::candidateNames($candidates);
				if (!isset($liveCandidates[$name]) && !array_key_exists($name, $this->overrides)) {
					continue;
				}
			}

			$wins = $assignment->assigned || $assignment->overridden || $assignment->ruleOverride;
			// A held-out candidate that does not win queues only its holdouts' exposures (never its own), so they count
			// the unit even when a later candidate wins. A logger error is deferred until the walk ends or reaches the
			// winner, so later held-out candidates are still queued.
			if ($exposeAssignment && ($wins || $assignment->getSDKState('suppressed', false))
				&& (!$assignment->exposed || ($this->hasHoldouts && $this->droppedHoldoutArms !== []))) {
				try {
					$this->triggerExposures($assignment);
				}
				catch (Throwable $exception) {
					$error = $error ?? $exception;
				}
			}

			if ($wins) {
				$selectedVariables = $assignment->variables ?? null;
				break;
			}
			// Passed over by an exposing read: as without holdouts, a later unit doesn't switch the key's winner.
			if ($exposeAssignment) {
				$assignment->setSDKState('served', true);
			}

			if ($assignment->getSDKState('suppressed', false) && $assignment->variables !== null && $selectedVariables === null) {
				$selectedVariables = $assignment->variables;
			}
		}

		if ($error !== null) {
			throw $error;
		}

		if ($selectedVariables !== null && property_exists($selectedVariables, $key)) {
			return $selectedVariables->{$key};
		}

		return $default;
	}

	/**
	 * @param ExperimentVariables[] $candidates
	 * @return array<string, true>
	 */
	private static function candidateNames(array $candidates): array {
		$names = [];
		foreach ($candidates as $candidate) {
			$names[$candidate->data->name] = true;
		}
		return $names;
	}

	public function getVariableKeys(): array {
		$this->checkReady(false);
		$return = [];
		foreach ($this->indexVariables as $variable => $experiments) {
			// Keep the single-name shape for compatibility: the lowest-id experiment (first in the sorted list).
			$return[$variable] = $experiments[0]->data->name;
		}

		return $return;
	}

	public function setAttribute(string $name, string $value): Context {
		return $this->setAttributeValue($name, $value);
	}

	/** Sets an attribute without converting its value to a string. */
	public function setAttributeValue(string $name, $value): Context {
		// Every attribute is published, so a value the payload cannot hold (INF, NaN, a resource) would fail every
		// later publish. Strings are accepted unchecked, as in v1.0.3, including invalid UTF-8.
		if (!is_string($value)) {
			self::assertPublishable(sprintf('Attribute "%s" value', $name), $value);
		}

		$this->attributes[] = (object) [
			'name' => $name,
			'value' => $value,
			'setAt' => self::getTime(),
		];
		++$this->attrsSeq;

		return $this;
	}

	/**
	 * @param mixed $value
	 */
	private static function assertPublishable(string $what, $value): void {
		try {
			// A publish adds three levels (event, list, record) above the value, within json_encode's default 512.
			json_encode($value, JSON_THROW_ON_ERROR, 509);
		}
		catch (Throwable $exception) {
			throw new InvalidArgumentException(sprintf('%s cannot be published: %s', $what, $exception->getMessage()), 0, $exception);
		}
	}

	public function setAttributes(array $attributes): Context {
		foreach ($attributes as $key => $value) {
			$this->setAttribute($key, $value);
		}

		return $this;
	}

	/** Sets typed attributes, routing strings through the legacy extension hook. */
	public function setAttributeValues(array $attributes): Context {
		foreach ($attributes as $key => $value) {
			if (is_string($value)) {
				$this->setAttribute($key, $value);
			} else {
				$this->setAttributeValue($key, $value);
			}
		}
		return $this;
	}

	public function getAttribute(string $name) {
		foreach (array_reverse($this->attributes) as $attribute) {
			if ($attribute->name === $name) {
				return $attribute->value;
			}
		}

		return null;
	}

	public function getAttributes(): array {
		$result = [];
		foreach ($this->attributes as $attribute) {
			$result[$attribute->name] = $attribute->value;
		}

		return $result;
	}

	/**
	 * The client's non-empty `application` and `environment` names, for audience and rule conditions.
	 *
	 * @return array<string, string>
	 */
	private function getSystemAttributes(): array {
		$systemAttributes = [];

		$client = $this->sdk->getClient();

		$application = $client->getApplication();
		if ($application !== '') {
			$systemAttributes['application'] = $application;
		}

		$environment = $client->getEnvironment();
		if ($environment !== '') {
			$systemAttributes['environment'] = $environment;
		}

		return $systemAttributes;
	}

	/**
	 * System attributes overlaid by user attributes, for evaluation only; the publish payload uses user attributes.
	 *
	 * @return array<string, mixed>
	 */
	private function getEvaluationAttributes(): array {
		if ($this->evaluationAttributesSeq !== $this->attrsSeq) {
			$this->evaluationAttributes = array_replace($this->getSystemAttributes(), $this->getAttributes());
			$this->evaluationAttributesSeq = $this->attrsSeq;
		}

		return $this->evaluationAttributes;
	}

	private function getVariantAssigner(string $unitType, string $unitHash): VariantAssigner {
		return $this->assigners[$unitType] ?? ($this->assigners[$unitType] = new VariantAssigner($unitHash));
	}

	public function getTreatment(string $experimentName): int {
		$this->checkReady();
		$assignment = $this->getAssignment($experimentName);
		if (!$assignment->exposed || ($this->hasHoldouts && $this->droppedHoldoutArms !== [])) {
			$this->triggerExposures($assignment);
		}

		return $assignment->variant;
	}

	private function queueExposure(Assignment $assignment): void {
		// With holdouts, a logger can close the context between an experiment's exposure and its holdouts', or between a
		// held-out variable candidate and the winner; nothing queued after that could be published. Without holdouts this
		// keeps main's (and JS's) queueing.
		if (!empty($assignment->exposed) || ($this->closed && $this->hasHoldouts)) {
			return;
		}

		if ($this->droppedHoldoutArms !== []) {
			unset($this->droppedHoldoutArms[spl_object_id($assignment)]);
		}

		$exposure = new Exposure();
		$assignment->exposed = true;
		$assignment->setSDKState('served', true);
		$assignment->isUnitPinnedMissing = $assignment->isUnitPinnedMissing || $assignment->isRuleMissingUnit;
		$exposure->ingestAssignment($assignment);

		$this->exposures[] = $exposure;
		$this->exposureAssignments[] = $assignment;
		++$this->pendingCount;

		$this->logEvent(ContextEventLoggerEvent::Exposure, $exposure);
	}

	private function logEvent(string $event, ?object $data): void {
		if (!isset($this->eventLogger)) {
			return;
		}

		$this->eventLogger->handleEvent($this, new ContextEventLoggerEvent($event, $data));
	}

	/**
	 * Reports an error to the event logger as an Error event. Without an event logger nothing is written, as in
	 * v1.0.3.
	 */
	private function logError(Throwable $throwable): void {
		if (!isset($this->eventLogger)) {
			return;
		}

		$this->eventLogger->handleEvent($this, new ContextEventLoggerEvent(ContextEventLoggerEvent::Error, $throwable));
	}


	public function getContextData(): ContextData {
		return $this->data;
	}

	public function peekTreatment(string $experimentName): int {
		$this->checkReady();
		return $this->getAssignment($experimentName)->variant;
	}


	public function setUnits(array $units): Context {
		foreach ($units as $unitType => $uid) {
			if (is_int($uid) || is_float($uid)) {
				// Numbers are stored as their string form; other non-strings keep the v1.0.3 error.
				$uid = (string) $uid;
			}
			if (!is_string($uid)) {
				throw new InvalidArgumentException(
					sprintf('Unit set value with key "%s" must be of type string, %s passed', $unitType, gettype($uid)));
			}
			$this->setUnit((string) $unitType, $uid);
		}

		return $this;
	}

	public function getUnit(string $unitType): ?string {
		return $this->units[$unitType] ?? null;
	}

	public function getUnits(): array {
		return $this->units;
	}

	public function setOverrides(array $overrides): Context {
		// See note in ContextConfig::setUnits
		foreach ($overrides as $experimentName => $variant) {
			if (!is_int($variant)) {
				throw new InvalidArgumentException(
					sprintf('Override set value with key "%s" must be of type integer, %s passed', $experimentName, gettype($variant)));
			}
			$this->setOverride($experimentName, $variant);
		}

		return $this;
	}

	public function setOverride(string $experimentName, int $variant): Context {
		$this->overrides[$experimentName] = $variant;
		return $this;
	}

	public function setCustomAssignments(array $customAssignments): Context {
		// See note in ContextConfig::setUnits
		foreach ($customAssignments as $experimentName => $variant) {
			if (!is_int($variant)) {
				throw new InvalidArgumentException(
					sprintf('Custom assignment set value with key "%s" must be of type integer, %s passed', $experimentName, gettype($variant)));
			}

			$this->setCustomAssignment($experimentName, $variant);
		}

		return $this;
	}

	public function setCustomAssignment(string $experimentName, int $variant): Context {
		$this->cassignments[$experimentName] = $variant;
		return $this;
	}

	public function getOverride(string $experimentName): ?int {
		return $this->overrides[$experimentName] ?? null;
	}

	public function getCustomAssignment(string $experimentName) {
		return $this->cassignments[$experimentName] ?? null;
	}

	public function setUnit(string $unitType, string $uid): Context {
		$uid = self::validateUnit($unitType, $uid);

		if (array_key_exists($unitType, $this->units)) {
			if ($this->units[$unitType] !== $uid) {
				throw new InvalidArgumentException(sprintf('Unit "%s" UID is already set', $unitType));
			}
			return $this;
		}

		$this->units[$unitType] = $uid;
		$this->invalidateAssignmentsMissingUnit($unitType);
		return $this;
	}

	/**
	 * Evicts cached assignments resolved while $unitType was missing (a split rule, or a holdout arm), so they are
	 * re-resolved with the unit. Exposed ones are kept, since re-resolving would queue a second, conflicting exposure.
	 */
	private function invalidateAssignmentsMissingUnit(string $unitType): void {
		foreach ($this->assignmentCache ?? [] as $experimentName => $assignment) {
			if ($assignment->unitType !== $unitType) {
				continue;
			}

			// Holdout arms are drawn with the assignment's own unit type, so while it was missing every arm is null. A served
			// decision is kept even if the cap dropped its exposure, which only makes the exposure retryable.
			if (($assignment->isRuleMissingUnit && !$assignment->isUnitPinnedMissing)
				|| (!$assignment->getSDKState('served', false) && $assignment->getSDKState('holdoutAssignments') !== null)) {
				unset($this->assignmentCache[$experimentName]);
			}
		}
	}

	private static function validateUnit(string $unitType, string $uid): string {
		if (trim($uid) === '') {
			throw new InvalidArgumentException(sprintf('Unit "%s" UID must not be blank', $unitType));
		}

		return $uid;
	}

	public function getPendingCount(): int {
		return $this->pendingCount;
	}

	private function checkNotClosed(): void {
		if ($this->isClosed()) {
			throw new LogicException('ABSmartly Context is closed');
		}
	}

	public function flush(): void {
		$this->flushEvents(false);
	}

	/**
	 * Publishes the queued exposures and goals. On a collector failure the events stay queued so a later
	 * publish()/close() can retry them; when $rethrow is true (close()) the failure is also re-thrown to the
	 * caller, when false (publish()/flush()) it is only logged, as in v1.0.3.
	 */
	private function flushEvents(bool $rethrow): void {
		if ($this->getPendingCount() === 0) {
			return;
		}

		if ($this->isFailed()) {
			// A failed load remains marked failed even if refresh later applies data; drop events as in v1.0.3.
			$this->exposures = [];
			$this->exposureAssignments = [];
			$this->achievements = [];
			$this->pendingCount = 0;

			return;
		}

		$event = $this->buildPublishEvent();

		try {
			$this->eventHandler->publish($event);
		}
		catch (Exception $exception) {
			$this->logError($exception);
			$this->capRetainedEvents();

			if ($rethrow) {
				throw $exception;
			}

			return;
		}

		// The collector has accepted the batch: clear it before notifying the event logger so a throwing logger
		// can never cause these events to be retained and sent again.
		$this->exposures = [];
		$this->exposureAssignments = [];
		$this->achievements = [];
		$this->pendingCount = 0;

		try {
			$this->logEvent(ContextEventLoggerEvent::Publish, $event);
		}
		catch (Throwable $exception) {
			// Not a publish failure, and re-entering the logger that just threw could loop: ignore it.
		}
	}

	/**
	 * Drops the oldest retained exposures and goals so the queue never exceeds $maxRetainedEvents, logging an Error
	 * event for each batch dropped. Exposures are dropped before goals.
	 */
	private function capRetainedEvents(): void {
		if ($this->maxRetainedEvents <= 0) {
			return;
		}

		$total = count($this->exposures) + count($this->achievements);
		if ($total <= $this->maxRetainedEvents) {
			return;
		}

		$toDrop = $total - $this->maxRetainedEvents;

		$droppedExposures = min($toDrop, count($this->exposures));
		if ($droppedExposures > 0) {
			$droppedAssignments = array_slice($this->exposureAssignments, 0, $droppedExposures);
			$this->exposures = array_slice($this->exposures, $droppedExposures);
			$this->exposureAssignments = array_slice($this->exposureAssignments, $droppedExposures);
			$toDrop -= $droppedExposures;

			// Clearing an unsent exposure means a later read must be able to re-queue it, so reset the matching
			// cached assignment's `exposed` flag, unless the cache has since been replaced with a newer
			// assignment (which carries its own un-exposed state).
			foreach ($droppedAssignments as $assignment) {
				$cached = $this->assignmentCache[$assignment->name] ?? null;
				if ($cached === $assignment) {
					$assignment->exposed = false;
				}
				elseif (($this->holdoutAssignmentCache[$assignment->id . ':' . $assignment->unitType] ?? null) === $assignment) {
					$assignment->exposed = false;
					$this->droppedHoldoutArms[spl_object_id($assignment)] = $assignment;
				}
			}
		}

		$droppedGoals = 0;
		if ($toDrop > 0) {
			$droppedGoals = min($toDrop, count($this->achievements));
			$this->achievements = array_slice($this->achievements, $droppedGoals);
		}

		$dropped = $droppedExposures + $droppedGoals;
		$this->pendingCount -= $dropped;

		$errorMsg = sprintf(
			'ABsmartly SDK Error: Retained event queue exceeded %d; dropped the oldest %d events (%d exposures, %d goals)',
			$this->maxRetainedEvents,
			$dropped,
			$droppedExposures,
			$droppedGoals
		);
		$this->logError(new \RuntimeException($errorMsg));
	}

	private function buildPublishEvent(): PublishEvent {
		$event = new PublishEvent();
		$event->setUnits($this->units);
		$event->setAttributes($this->attributes);
		$event->exposures = $this->exposures;
		$event->goals = $this->achievements;

		return $event;
	}

	public function track(string $goalName, ?object $properties = null): void {
		$this->checkNotClosed();
		$achievement = new GoalAchievement($goalName, static::getTime(), $properties);
		$this->achievements[] = $achievement;
		++$this->pendingCount;

		$this->logEvent(ContextEventLoggerEvent::Goal, $achievement);
	}

	/** @param object|array|null $properties an object or associative array */
	public function trackWithProperties(string $goalName, $properties = null): void {
		$this->checkNotClosed();
		$properties = $this->validateGoal($goalName, $properties);
		if ($properties !== null) {
			self::assertPublishable(sprintf("Goal '%s' properties", $goalName), $properties);
		}
		$this->track($goalName, $properties);
	}

	/**
	 * @param mixed $properties
	 */
	private function validateGoal(string $goalName, $properties): ?object {
		if ($properties === null) {
			return null;
		}

		if (Utils::isObject($properties)) {
			return clone $properties;
		}

		// Other objects (DTOs, ArrayObject, JsonSerializable) are kept as given so
		// they serialize exactly as in earlier releases.
		if (is_object($properties)) {
			return $properties;
		}

		if (is_array($properties) && $properties !== [] && array_keys($properties) !== range(0, count($properties) - 1)) {
			return (object) $properties;
		}

		throw new InvalidArgumentException(sprintf("Goal '%s' properties must be of type object.", $goalName));
	}

	public function publish(): void {
		$this->checkNotClosed();
		$this->flush();
	}

	public function refresh(): void {
		$this->checkNotClosed();
		if ($this->firstLoadInProgress) {
			return;
		}

		// setData() installs nothing until all of the data is processed, so a failure, including a TypeError from a
		// mistyped field or an Error logger that throws, keeps the previous data and its decisions in use.
		try {
			$data = $this->dataProvider->getContextData();
			$this->setData($data);
		}
		catch (Throwable $exception) {
			$this->logError($exception);
			return;
		}

		// The data is installed, so a throwing logger no longer rolls it back: assignments a Refresh handler built from
		// it must stay consistent with the data in use. Re-entering the logger that just threw could loop, so the error
		// is ignored, as for Publish (the JS SDK swallows logger errors the same way).
		try {
			$this->logEvent(ContextEventLoggerEvent::Refresh, $data);
		}
		catch (Throwable $exception) {
		}
	}

	/**
	 * Publishes any queued events and closes the context. A failed final publish is re-thrown and the context is
	 * left open (isClosed() stays false) with its events still queued, so calling close() again retries publishing
	 * them. The Close event is emitted only once the close succeeds.
	 */
	public function close(): void {
		if ($this->isClosed()) {
			return;
		}

		$this->finalizing = true;
		try {
			if ($this->getPendingCount() > 0) {
				$this->flushEvents(true);
			}
		}
		finally {
			$this->finalizing = false;
		}

		$this->logEvent(ContextEventLoggerEvent::Close, null);
		$this->closed = true;
	}

}
