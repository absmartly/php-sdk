<?php

namespace ABSmartly\SDK\Context;

use ABSmartly\SDK\Assignment;
use ABSmartly\SDK\AudienceMatcher;
use ABSmartly\SDK\Exception\InvalidArgumentException;
use ABSmartly\SDK\Exception\LogicException;
use ABSmartly\SDK\Experiment;
use ABSmartly\SDK\ExperimentVariables;
use ABSmartly\SDK\Exposure;
use ABSmartly\SDK\GoalAchievement;
use ABSmartly\SDK\PublishEvent;
use ABSmartly\SDK;
use ABSmartly\SDK\Switchback\SwitchbackAssigner;
use ABSmartly\SDK\Switchback\SwitchbackAttributePath;
use ABSmartly\SDK\Switchback\SwitchbackServing;
use ABSmartly\SDK\Switchback\SwitchbackSlot;
use ABSmartly\SDK\Switchback\SwitchbackSources;
use ABSmartly\SDK\Utils;
use ABSmartly\SDK\VariableParser;
use ABSmartly\SDK\VariantAssigner;

use Closure;
use Exception;
use JsonException;
use Throwable;

use function array_intersect_key;
use function array_replace;
use function array_slice;
use function array_splice;
use function array_values;
use function count;
use function floor;
use function get_object_vars;
use function gettype;
use function hash;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function ksort;
use function sort;
use function microtime;
use function min;
use function property_exists;
use function spl_object_id;
use function sprintf;
use function strpos;
use function trim;

class Context {

	private SDK $sdk;

	private ContextEventHandler $eventHandler;
	private ContextEventLogger $eventLogger;
	private ContextDataProvider $dataProvider;
	private VariableParser $variableParser;
	private AudienceMatcher $audienceMatcher;

	private array $units = [];
	private bool $failed = false;

	private ?ContextData $data;

	private array $index;
	private array $indexVariables;


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
	/** @var array<int, object> Servable holdouts of the installed data, by id. */
	private array $holdoutsById = [];
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
	private ?Throwable $readyError = null;
	/** @var array<string, SwitchbackSources> The installed switchback experiments, by name. Empty without switchbacks. */
	private array $switchbackSources = [];
	/**
	 * @var array<string, array{generation: int, periods: int[]}> Each switchback experiment's cached slots: the
	 * generation they were resolved under and their period indices (at most SWITCHBACK_RETAINED_PERIODS), besides the
	 * bare-name slot shared by pre-start, unservable and legacy resolutions.
	 */
	private array $switchbackEpochs = [];
	/**
	 * Whether any installed data had a switchback: until then no read consults the switchback state. A performance guard
	 * only (two isset() calls per cached unit read, ~4% with opcache): both branches it skips find nothing without it.
	 */
	private bool $hasSwitchbacks = false;
	/** Prevents callbacks from draining the queue while an outer flush is processing its batch indexes. */
	private bool $flushing = false;
	/** Source of SwitchbackSources::$generation. */
	private int $switchbackGenerationCounter = 0;
	/** @var Closure(): int|null Test seam for getTime(); null reads the system clock. */
	private static ?Closure $clock = null;

	private const SWITCHBACK_RETAINED_PERIODS = 2;
	private const SWITCHBACK_KEY_SEPARATOR = "\x1f";

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
		if (!empty($this->data->experiments)) {
			foreach ($this->data->experiments as $experiment) {
				if (empty($experiment->customFieldValues)) {
					continue;
				}

				foreach ($experiment->customFieldValues as $field) {
					if (isset($field->name)) {
						$keys[$field->name] = true;
					}
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
		if (self::$clock !== null) {
			return (self::$clock)();
		}
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
			$this->data = null;
			$this->index = [];
			$this->indexVariables = [];
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

			$this->setData($data);
			$this->logEvent(ContextEventLoggerEvent::Ready, $data);
		}
		catch (Exception $exception) {
			$this->setDataFailed($exception);
			error_log(sprintf(
				'ABsmartly SDK CRITICAL: Context initialization failed: %s in %s:%d. Context is in failed state.',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
			$this->logError($exception, false);
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

	private function setData(ContextData $data): void {
		$this->data = $data;
		$this->index = [];
		$this->indexVariables = [];
		$this->ruleKeys = [];
		$this->parsedRules = [];
		$this->holdoutsById = [];
		$this->experimentHoldouts = [];
		$previousSwitchbacks = $this->switchbackSources;
		$this->switchbackSources = [];
		$environment = $this->sdk->getClient()->getEnvironment();

		foreach ($data->holdouts as $holdout) {
			if (($servable = self::servableHoldout($holdout)) !== null) {
				$this->holdoutsById[$servable->id] = $servable;
			}
		}

		foreach ($data->experiments as $experiment) {
			$experimentVariables = new ExperimentVariables();
			$experimentVariables->data = new Experiment($experiment);
			$experimentVariables->variables = [];

			foreach ($experiment->variants as $variant) {
				if (empty($variant->config)) {
					$experimentVariables->variables[] = (object) [];
					continue;
				}

				$parsed = $this->variableParser->parse($experiment->name, $variant->config);
				if (!$parsed) {
					$experimentVariables->variables[] = (object) [];
					continue;
				}

				foreach (get_object_vars($parsed) as $key => $val) {
					$this->addVariableExperiment($key, $experimentVariables);
				}
				$experimentVariables->variables[] = $parsed;
			}

			$this->index[$experiment->name] = $experimentVariables;
			if ($experimentVariables->data->assignmentRules !== null) {
				$this->ruleKeys[$experiment->name] = $experimentVariables->data->assignmentRules . ':' . $environment;
			}
			if ($experimentVariables->data->randomizationType === 'switchback') {
				$this->installSwitchback($experiment->name, $experimentVariables->data, $previousSwitchbacks[$experiment->name] ?? null);
			}
			elseif (!empty($experimentVariables->data->holdoutIds) && $this->holdoutsById !== []) {
				$holdouts = [];
				foreach ($experimentVariables->data->holdoutIds as $holdoutId) {
					if (isset($this->holdoutsById[$holdoutId])) {
						$holdouts[$holdoutId] = $this->holdoutsById[$holdoutId];
					}
				}
				if ($holdouts !== []) {
					ksort($holdouts);
					$this->experimentHoldouts[$experiment->name] = array_values($holdouts);
					$this->hasHoldouts = true;
				}
			}
		}

		// Last, so a refresh that throws above leaves the marks as they were. Only an exposed cached assignment can queue
		// a dropped arm again (a new assignment queues its arms anyway), so a mark whose arm no cached assignment pins
		// any more is forgotten.
		if ($this->droppedHoldoutArms !== []) {
			$pinned = [];
			foreach ($this->assignmentCache ?? [] as $assignment) {
				foreach ($assignment->holdoutAssignments ?? [] as $arm) {
					if ($arm !== null) {
						$pinned[spl_object_id($arm)] = true;
					}
				}
			}
			$this->droppedHoldoutArms = array_intersect_key($this->droppedHoldoutArms, $pinned);
		}
	}

	/**
	 * Indexes a switchback's cluster sources and gives it a generation: the previous one while its switchback identity
	 * is unchanged, otherwise a fresh one, so the next resolution drops every slot of the old epoch. The identity is the
	 * id, the iteration, the ordered cluster map, the allow-list, startAt, periodMs and the design, plus unitType when
	 * either side takes the legacy path. Seed, split and the analysis-only fields are left out.
	 */
	private function installSwitchback(string $name, Experiment $experiment, ?SwitchbackSources $previous): void {
		$sources = new SwitchbackSources($experiment);
		$sources->generation = $previous !== null
			&& $previous->id === $experiment->id
			&& $previous->iteration === $experiment->iteration
			&& $previous->config === $sources->config
			&& (($previous->paths !== null && $sources->paths !== null) || $previous->unitType === $experiment->unitType)
			? $previous->generation
			: ++$this->switchbackGenerationCounter;
		$this->switchbackSources[$name] = $sources;
		$this->hasSwitchbacks = true;
	}

	/**
	 * A holdout entry is used only when it has the fields its arm is drawn from and a non-empty numeric split, as in
	 * JS (`holdout.split && holdout.split.length > 0`); anything else gives null, so experiments it would have
	 * covered assign normally. Integral floats (`11.0`) count as integers, since JS can't tell them apart; such an
	 * entry is copied with integer fields rather than changed in place. Unknown fields are ignored.
	 */
	private static function servableHoldout($holdout): ?object {
		if (!is_object($holdout)) {
			return null;
		}

		$integers = [];
		foreach (['id', 'iteration', 'seedHi', 'seedLo'] as $field) {
			$value = property_exists($holdout, $field) ? self::integralValue($holdout->{$field}) : null;
			if ($value === null) {
				return null;
			}
			if (!is_int($holdout->{$field})) {
				$integers[$field] = $value;
			}
		}

		if (!property_exists($holdout, 'name') || !is_string($holdout->name)
			|| !property_exists($holdout, 'split') || !is_array($holdout->split) || $holdout->split === []) {
			return null;
		}

		foreach ($holdout->split as $value) {
			if (!is_int($value) && !is_float($value)) {
				return null;
			}
		}

		if ($integers === []) {
			return $holdout;
		}

		$copy = clone $holdout;
		foreach ($integers as $field => $value) {
			$copy->{$field} = $value;
		}
		return $copy;
	}

	/**
	 * $value as an int when it is an integer or an integral float within the int range (JSON `11.0`), else null.
	 *
	 * @param mixed $value
	 */
	private static function integralValue($value): ?int {
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value) && floor($value) === $value && $value >= -9.2233720368547758E18 && $value < 9.2233720368547758E18) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Indexes $experimentVariables under $key, keeping the per-key list sorted and unique by experiment id, as the
	 * JS SDK's _resolveVariableValue does (insertUniqueSorted by data->id).
	 */
	private function addVariableExperiment(string $key, ExperimentVariables $experimentVariables): void {
		if (!isset($this->indexVariables[$key])) {
			$this->indexVariables[$key] = [$experimentVariables];
			return;
		}

		$list = $this->indexVariables[$key];
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
		$this->indexVariables[$key] = $list;
	}

	private function setDataFailed(?Throwable $exception = null): void {
		$this->indexVariables = [];
		$this->index = [];
		$this->holdoutsById = [];
		$this->experimentHoldouts = [];
		$this->switchbackSources = [];
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
			$this->setData($contextData);
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

		if (!empty($this->data->experiments)) {
			foreach ($this->data->experiments as $experiment) {
				$return[] = $experiment->name;
			}
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

	private function experimentMatches(Experiment $experiment, Assignment $assignment): bool {
		return $experiment->id === $assignment->id &&
			$experiment->unitType === $assignment->unitType &&
			$experiment->iteration === $assignment->iteration &&
			$experiment->fullOnVariant === $assignment->fullOnVariant &&
			$experiment->trafficSplit === $assignment->trafficSplit;
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
			if (!$assignment->ruleOverride && !$assignment->suppressed
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
	private function resolveRule(Experiment $experiment, bool $isUnitPinnedMissing, ?string $unitType = null): ?array {
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

		// A switchback's split rule randomizes over its serving unit.
		$unitType = $unitType ?? $experiment->unitType;
		$uid = $isUnitPinnedMissing ? null : ($this->units[$unitType] ?? null);
		if ($uid === null) {
			return ['variant' => $experiment->fullOnVariant, 'isMissingUnit' => true];
		}

		$variant = $experiment->randomizationType === 'switchback'
			? SwitchbackAssigner::assignUnit($uid, $action['split'], $experiment->seedHi, $experiment->seedLo)
			: $this->getVariantAssigner($unitType, $uid)->assign($action['split'], $experiment->seedHi, $experiment->seedLo);
		return [
			'variant' => $variant,
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

		// Short-circuits for data that never had a switchback, so unit reads stay as cheap as before.
		if ($this->hasSwitchbacks) {
			if ($experiment !== null && isset($this->switchbackSources[$experimentName])) {
				return $this->getSwitchbackAssignment($experimentName, $experiment, $this->switchbackSources[$experimentName]);
			}
			if (isset($this->switchbackEpochs[$experimentName])) {
				// No longer a switchback (removed, or now a unit experiment): its slots are from another epoch.
				$this->dropSwitchbackEpoch($experimentName);
			}
		}

		if (isset($this->assignmentCache[$experimentName])) {
			$assignment = $this->assignmentCache[$experimentName];
			if (array_key_exists($experimentName, $this->overrides)) {
				$override = $this->overrides[$experimentName];
				if ($assignment->overridden && $assignment->variant === $override
					&& ($experiment === null || !$this->hasHoldouts
						// A covered override is rebuilt on a unit type change so its holdout arms are drawn for the new
						// unit; an uncovered one is reused as before.
						|| (($assignment->holdouts === null || $assignment->unitType === $experiment->data->unitType)
							&& $this->holdoutSetMatches($experimentName, $assignment)))) {
					// override up-to-date
					return $assignment;
				}
			}
			else if ($experiment === null) {
				// A rule-won assignment is not "assigned" either, but its variant came from the
				// experiment's rules, so it must be rebuilt once the experiment is gone.
				if (!$assignment->assigned && !$assignment->ruleOverride) {
					// previously not-running experiment
					return $assignment;
				}
			} else if (!array_key_exists($experimentName, $this->cassignments)
				|| $this->cassignments[$experimentName] === $assignment->variant
				|| $assignment->ruleOverride
				|| $assignment->suppressed
				|| $this->customAssignmentIgnored($assignment, $experiment)) {
				// A matching rule wins over a custom assignment and a suppressed one is forced to control, so a
				// custom mismatch is not staleness.
				if ($this->experimentMatches($experiment->data, $assignment)
					&& $this->audienceMatches($experiment->data, $assignment)
					// Short-circuits for data without holdouts, so their cached reads stay as cheap as before.
					&& (!$this->hasHoldouts || $this->holdoutSetMatches($experimentName, $assignment))) {
					// assignment up-to-date
					return $assignment;
				}
			}
		}

		$this->assignmentCache[$experimentName] = $assignment = new Assignment();

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
			elseif ($assignment->suppressed) {
				// Held out: control and not assigned; the audience, full-on, traffic and custom assignment are skipped.
				$assignment->variant = 0;
			}
			else {
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
					// A missing unit leaves the assignment unassigned (control) so it falls through, as in JS.
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

		$assignment->attrsSeq = $this->attrsSeq;

		return $assignment;
	}

	/**
	 * Port of java-sdk's switchback resolution (the Collector's R33 serving). The slot is the experiment's current
	 * period, or one bare-name slot before the anchor, for an unservable schedule and on the legacy path. A slot is
	 * reused while it is current; otherwise it is resolved again, keeping the serving unit and cluster key it read, so
	 * they are read once per slot. Callers must keep cluster attributes unchanged from a period's first read through its
	 * exposure and any re-queue: a change within a period is not supported, and takes effect from the next period.
	 */
	private function getSwitchbackAssignment(string $experimentName, ExperimentVariables $experiment, SwitchbackSources $sources): Assignment {
		$now = self::getTime();
		$data = $experiment->data;
		$period = null;
		if ($sources->servable && $sources->paths !== null && $now >= $data->switchbackStartAt) {
			$period = SwitchbackAssigner::floorDiv($now - $data->switchbackStartAt, $data->switchbackPeriodMs);
		}
		$cacheKey = $period === null ? $experimentName : $experimentName . self::SWITCHBACK_KEY_SEPARATOR . $period;

		$epoch = $this->switchbackEpochs[$experimentName] ?? null;
		if ($epoch !== null && $epoch['generation'] !== $sources->generation) {
			$this->dropSwitchbackEpoch($experimentName);
		}

		$cached = $this->assignmentCache[$cacheKey] ?? null;
		if ($cached !== null && $cached->switchback === null) {
			// A unit experiment's assignment left on the bare-name slot by a refresh.
			$cached = null;
		}
		if ($cached !== null && $this->switchbackAssignmentCurrent($experimentName, $cached, $experiment)) {
			$cached->switchback->readAt = $now;
			return $cached;
		}

		$assignment = new Assignment();
		$assignment->name = $experimentName;
		$assignment->eligible = true;
		$assignment->id = $data->id;
		$assignment->switchback = $slot = new SwitchbackSlot($period);
		$slot->custom = $this->cassignments[$experimentName] ?? null;

		$slot->readAt = $now;
		$cachedServing = $cached !== null ? $cached->switchback->serving : null;
		if ($cachedServing !== null && $cachedServing->status !== SwitchbackServing::ABSENT) {
			$serving = $cachedServing;
			$slot->units = $cached->switchback->units;
		}
		else {
			$serving = $this->resolveSwitchbackServing($data, $sources, $now);
			$slot->units = $this->units;
		}
		$slot->serving = $serving;

		if (isset($this->overrides[$experimentName])) {
			// The override variant, even without a cluster; recorded under the serving unit, else the first linked type.
			$assignment->unitType = $serving->unitType ?? ($sources->paths !== null ? $sources->types[0] : $data->unitType);
			$assignment->overridden = true;
			$assignment->variant = $this->overrides[$experimentName];
		}
		else {
			$this->resolveSwitchbackVariant($assignment, $experiment, $sources, $period);
		}

		if ($assignment->variant >= 0 && $assignment->variant < count($data->variants)) {
			$assignment->variables = $experiment->variables[$assignment->variant];
		}

		$assignment->attrsSeq = $this->attrsSeq;
		$this->assignmentCache[$cacheKey] = $assignment;
		$this->retainSwitchbackPeriod($experimentName, $sources->generation, $period);

		return $assignment;
	}

	/**
	 * The precedence for an eligible cluster, in the Collector's assignExperiment order: assignment rules (a split rule
	 * randomizes over the serving unit), the audience, full-on, then, only when the schedule can be drawn now, traffic
	 * (sticky on the serving unit's hash), custom assignment and the draw over the raw cluster key. No linked unit, an
	 * ineligible cluster and a pre-start or unservable draw are silent controls that record nothing.
	 */
	private function resolveSwitchbackVariant(Assignment $assignment, ExperimentVariables $experiment, SwitchbackSources $sources, ?int $period): void {
		$data = $experiment->data;
		$slot = $assignment->switchback;
		$serving = $slot->serving;
		$assignment->ruleKey = $this->ruleKey($data);
		$assignment->iteration = $data->iteration;
		$assignment->trafficSplit = $data->trafficSplit;
		$assignment->fullOnVariant = $data->fullOnVariant;
		$assignment->variant = 0;

		if ($serving->status === SwitchbackServing::ABSENT) {
			$assignment->unitType = $sources->paths !== null ? $sources->types[0] : $data->unitType;
			$assignment->eligible = false;
			$slot->silent = true;
			return;
		}

		$unitType = $serving->unitType;
		$assignment->unitType = $unitType;
		$assignment->assigned = true;
		if ($serving->status === SwitchbackServing::INELIGIBLE) {
			$assignment->eligible = false;
			$slot->silent = true;
			return;
		}

		$rule = $this->resolveRule($data, false, $unitType);
		$assignment->ruleVariant = $rule['variant'] ?? null;
		if ($rule !== null) {
			$assignment->assigned = false;
			$assignment->ruleOverride = true;
			$assignment->variant = $rule['variant'];
			return;
		}

		if (!empty($data->audience) && !empty((array) $data->audience)) {
			$result = $this->audienceMatcher->evaluate($data->audience, $this->getEvaluationAttributes());
			if (is_bool($result)) {
				$assignment->audienceMismatch = !$result;
			}
		}
		if (!empty($data->audienceStrict) && $assignment->audienceMismatch) {
			$assignment->assigned = false;
			return;
		}
		if (!empty($data->fullOnVariant)) {
			$assignment->variant = $data->fullOnVariant;
			$assignment->fullOn = true;
			return;
		}
		if ($period === null) {
			// Pre-start or an unservable schedule: no draw, so control and no exposure.
			$assignment->eligible = false;
			$slot->silent = true;
			return;
		}

		if (SwitchbackAssigner::assignUnit($this->units[$unitType], $data->trafficSplit, $data->trafficSeedHi, $data->trafficSeedLo) !== 1) {
			// A traffic miss on an eligible cluster is recorded, as for a unit experiment.
			$assignment->eligible = false;
			return;
		}
		if ($slot->custom !== null) {
			$assignment->variant = $slot->custom;
			$assignment->custom = true;
			return;
		}

		$seed = ($data->seedHi << 32) | ($data->seedLo & 0xFFFFFFFF);
		$assignment->variant = SwitchbackAssigner::assign($serving->clusterKey, $period, $seed, count($data->split));
	}

	/**
	 * Whether a cached switchback slot still holds: the same override, or the same run, custom assignment, rule outcome
	 * and audience outcome. A grid change starts a new generation, which drops the slot before this is asked. Seed and
	 * split edits keep it, as for unit experiments.
	 */
	private function switchbackAssignmentCurrent(string $experimentName, Assignment $assignment, ExperimentVariables $experiment): bool {
		if (array_key_exists($experimentName, $this->overrides)) {
			return $assignment->overridden && $assignment->variant === $this->overrides[$experimentName];
		}
		if ($assignment->overridden) {
			return false;
		}

		$data = $experiment->data;
		if ($assignment->iteration !== $data->iteration
			|| $assignment->fullOnVariant !== $data->fullOnVariant
			|| $assignment->trafficSplit !== $data->trafficSplit) {
			return false;
		}
		// A rule-won slot never read the custom assignment.
		if (!$assignment->ruleOverride && ($this->cassignments[$experimentName] ?? null) !== $assignment->switchback->custom) {
			return false;
		}

		// A slot without an eligible cluster never consulted its rules or audience.
		if ($assignment->switchback->serving->status !== SwitchbackServing::ELIGIBLE) {
			return true;
		}

		$ruleKey = $this->ruleKey($data);
		if ($this->attrsSeq > $assignment->attrsSeq || $ruleKey !== $assignment->ruleKey) {
			if ($ruleKey !== $assignment->ruleKey || $data->assignmentRules !== null) {
				$rule = $this->resolveRule($data, false, $assignment->unitType);
				if (($rule['variant'] ?? null) !== $assignment->ruleVariant) {
					return false;
				}
			}

			if (!$assignment->ruleOverride && !empty($data->audience) && !empty((array) $data->audience)) {
				$result = $this->audienceMatcher->evaluate($data->audience, $this->getEvaluationAttributes());
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
	 * The Collector's ContextAPI.switchbackServing at $now. The cluster is read whether or not the schedule can be drawn
	 * now, so rules, the audience and full-on still see the serving unit. A missing, empty or unreadable cluster map
	 * takes the legacy path: an ineligible control under a unit of the declared unitType, if set.
	 */
	private function resolveSwitchbackServing(Experiment $experiment, SwitchbackSources $sources, int $now): SwitchbackServing {
		if ($sources->paths === null) {
			return SwitchbackServing::legacy($experiment->unitType, $this->units);
		}
		return SwitchbackServing::resolve($sources->types, $sources->paths, $experiment->switchbackClusterValues, $this->units,
			function (SwitchbackAttributePath $path) use ($now) {
				$attribute = $this->latestAttribute($path->rootKey(), $now);
				return $attribute !== null ? $path->resolveAttribute($attribute->value) : null;
			});
	}

	/**
	 * The attribute the Collector records with an exposure at $now (ContextAPI.extractAttributes): among the attributes
	 * set at or before $now whose trimmed name matches, the latest setAt wins and a tie goes to the one set last.
	 * System attributes are never read.
	 */
	private function latestAttribute(string $name, int $now): ?object {
		$selected = null;
		foreach ($this->attributes as $attribute) {
			if ($attribute->setAt <= $now && trim($attribute->name, "\x00..\x20") === $name
				&& ($selected === null || $attribute->setAt >= $selected->setAt)) {
				$selected = $attribute;
			}
		}
		return $selected;
	}

	/**
	 * Records a switchback resolution under the current epoch. At most SWITCHBACK_RETAINED_PERIODS period indices are
	 * kept; the lowest index other than $period is evicted, so the slot just installed always survives. Exposure dedup
	 * therefore holds only within the retained window: re-entering an evicted period resolves it once more.
	 */
	private function retainSwitchbackPeriod(string $experimentName, int $generation, ?int $period): void {
		if (!isset($this->switchbackEpochs[$experimentName])) {
			$this->switchbackEpochs[$experimentName] = ['generation' => $generation, 'periods' => []];
		}
		if ($period === null) {
			return;
		}

		$periods = &$this->switchbackEpochs[$experimentName]['periods'];
		if (!in_array($period, $periods, true)) {
			$periods[] = $period;
			sort($periods);
		}
		while (count($periods) > self::SWITCHBACK_RETAINED_PERIODS) {
			$evict = $periods[0] === $period ? 1 : 0;
			unset($this->assignmentCache[$experimentName . self::SWITCHBACK_KEY_SEPARATOR . $periods[$evict]]);
			array_splice($periods, $evict, 1);
		}
	}

	/** Drops every slot of the experiment's switchback epoch, the bare-name slot included. */
	private function dropSwitchbackEpoch(string $experimentName): void {
		foreach ($this->switchbackEpochs[$experimentName]['periods'] as $period) {
			unset($this->assignmentCache[$experimentName . self::SWITCHBACK_KEY_SEPARATOR . $period]);
		}
		unset($this->assignmentCache[$experimentName], $this->switchbackEpochs[$experimentName]);
	}

	/** The assignmentCache key $assignment is cached under. */
	private static function assignmentCacheKey(Assignment $assignment): string {
		$period = $assignment->switchback !== null ? $assignment->switchback->period : null;
		return $period === null ? $assignment->name : $assignment->name . self::SWITCHBACK_KEY_SEPARATOR . $period;
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
				$assignment->suppressed = true;
			}
		}

		$assignment->holdouts = $holdouts;
		$assignment->holdoutAssignments = $holdoutAssignments;
	}

	/**
	 * Arm 0 always holds the unit out. In a 3-arm holdout, arm 1 holds out only experiments that are not full-on.
	 * The arm count comes from the split, never from holdoutType.
	 */
	private static function isHeldOutBy(int $holdoutVariant, int $holdoutArmCount, int $fullOnVariant): bool {
		if ($holdoutVariant === 0) {
			return true;
		}

		if ($holdoutArmCount === 3 && $holdoutVariant === 1) {
			return $fullOnVariant === 0;
		}

		return false;
	}

	/**
	 * The unit's arm of $holdout, hashed with the covered experiment's unit type (not the holdout's own), or null when
	 * that unit is not set. One arm is kept per holdout and unit type until the holdout's iteration changes.
	 */
	private function getHoldoutAssignment(object $holdout, string $unitType): ?Assignment {
		// $holdout may predate a refresh; prefer the live definition.
		$live = $this->holdoutsById[$holdout->id] ?? $holdout;

		$cacheKey = $live->id . ':' . $unitType;
		$cached = $this->holdoutAssignmentCache[$cacheKey] ?? null;
		if ($cached !== null && $cached->iteration === $live->iteration) {
			return $cached;
		}

		$uid = $this->units[$unitType] ?? null;
		if ($uid === null) {
			return null;
		}

		$assignment = new Assignment();
		$assignment->id = $live->id;
		$assignment->name = $live->name;
		$assignment->iteration = $live->iteration;
		$assignment->unitType = $unitType;
		$assignment->variant = $this->getVariantAssigner($unitType, $uid)->assign($live->split, $live->seedHi, $live->seedLo);
		$assignment->assigned = true;
		$assignment->eligible = true;
		$assignment->holdoutArmCount = count($live->split);

		return $this->holdoutAssignmentCache[$cacheKey] = $assignment;
	}

	/**
	 * Whether $assignment was resolved against the experiment's current holdouts. Only each holdout's id and
	 * iteration count: a same-iteration seed or split edit must not force a duplicate exposure.
	 */
	private function holdoutSetMatches(string $experimentName, Assignment $assignment): bool {
		$pinned = $assignment->holdouts;
		if ($pinned === null) {
			return !isset($this->experimentHoldouts[$experimentName]);
		}

		$fresh = $this->experimentHoldouts[$experimentName] ?? null;
		if ($fresh === $pinned) {
			return true;
		}
		if ($fresh === null) {
			return false;
		}

		if (count($fresh) !== count($pinned)) {
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
		if ($assignment->switchback !== null) {
			// Stamped with the reading that resolved this call's period (java-sdk), since analysis derives the period from
			// exposedAt. It is kept on the slot rather than the context, so a nested SDK call cannot change it.
			if (!$assignment->switchback->silent) {
				$this->queueExposure($assignment, $assignment->switchback->readAt);
			}
			return;
		}

		if ($assignment->holdoutAssignments === null) {
			$this->queueExposure($assignment);
			return;
		}

		// A throwing event logger must not strand the remaining exposures: each is queued (and marked exposed) before
		// it is logged, so all of them are queued first and the first logger error is re-thrown afterwards.
		$error = null;
		if (!$assignment->suppressed || $assignment->overridden || $assignment->ruleOverride) {
			try {
				$this->queueExposure($assignment);
			}
			catch (Throwable $exception) {
				$error = $exception;
			}
		}
		else {
			$assignment->exposed = true;
		}

		$holdoutError = $this->triggerHoldoutExposures($assignment);
		if (($error ?? $holdoutError) !== null) {
			throw $error ?? $holdoutError;
		}
	}

	/**
	 * Queues each pinned holdout arm's exposure, once per holdout and unit type, and returns the first event logger
	 * error. Uses the arms pinned when the assignment was resolved, never a live re-resolution, so a refresh in
	 * between cannot publish another epoch.
	 */
	private function triggerHoldoutExposures(Assignment $assignment): ?Throwable {
		$error = null;
		foreach ($assignment->holdoutAssignments as $i => $holdoutAssignment) {
			if ($holdoutAssignment !== null) {
				$holdoutAssignment->name = $assignment->holdouts[$i]->name;
				try {
					$this->queueExposure($holdoutAssignment);
				}
				catch (Throwable $exception) {
					$error = $error ?? $exception;
				}
			}
		}

		return $error;
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
	 * Ports the JS SDK `_resolveVariableValue`: walks the experiments that define $key in ascending id order. For
	 * each one it computes the assignment; when the variant has variables, getVariableValue (not peek) queues the
	 * exposure the first time, and if $key is present and the unit is assigned, overridden or rule-assigned it returns
	 * that value.
	 * Otherwise it falls through to the next experiment, and finally to $default.
	 */
	private function resolveVariableValue(string $key, $default, bool $exposeAssignments) {
		$experiments = $this->indexVariables[$key] ?? [];
		// A held-out unit reads the control value, not $default, when nothing else wins. The first suppressed
		// experiment is kept even if it lacks the key, so a later suppressed one can't win (JS).
		$suppressedFallback = null;

		foreach ($experiments as $experimentVariables) {
			$assignment = $this->getAssignment($experimentVariables->data->name);

			if ($assignment->variables !== null) {
				// A switchback is exposed when the key reaches it assigned, overridden or rule-assigned, as java-sdk does, even
				// if its arm lacks the key (a control arm with no config must still be recorded); a strict mismatch records
				// nothing. Unit experiments keep the JS behaviour of exposing every candidate.
				if ($exposeAssignments && (!$assignment->exposed || $this->droppedHoldoutArms !== [])
					&& (!$this->hasSwitchbacks || $assignment->switchback === null
						|| $assignment->assigned || $assignment->overridden || $assignment->ruleOverride)) {
					$this->triggerExposures($assignment);
				}

				// property_exists (not isset) so a present key whose value is null is still returned,
				// matching JS: a lower-id experiment that defines $key=null shadows a later competitor.
				if (property_exists($assignment->variables, $key)
					&& ($assignment->assigned || $assignment->overridden || $assignment->ruleOverride)) {
					return $assignment->variables->{$key};
				}

				if ($assignment->suppressed && !$assignment->overridden && !$assignment->ruleOverride
					&& $suppressedFallback === null) {
					$suppressedFallback = $assignment->variables;
				}
			}
		}

		if ($suppressedFallback !== null && property_exists($suppressedFallback, $key)) {
			return $suppressedFallback->{$key};
		}

		return $default;
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

	public function setAttribute(string $name, $value): Context {
		$this->checkNotClosed();
		$this->attributes[] = (object) [
			'name' => $name,
			'value' => $value,
			'setAt' => self::getTime(),
		];
		++$this->attrsSeq;

		return $this;
	}

	public function setAttributes(array $attributes): Context {
		foreach ($attributes as $key => $value) {
			$this->setAttribute($key, $value);
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
		if (!$assignment->exposed || $this->droppedHoldoutArms !== []) {
			$this->triggerExposures($assignment);
		}

		return $assignment->variant;
	}

	private function queueExposure(Assignment $assignment, ?int $exposedAt = null): void {
		if (!empty($assignment->exposed)) {
			return;
		}

		if ($this->droppedHoldoutArms !== []) {
			unset($this->droppedHoldoutArms[spl_object_id($assignment)]);
		}

		$exposure = new Exposure();
		$assignment->exposed = true;
		$assignment->isUnitPinnedMissing = $assignment->isUnitPinnedMissing || $assignment->isRuleMissingUnit;
		$exposure->ingestAssignment($assignment);
		if ($exposedAt !== null) {
			$exposure->exposedAt = $exposedAt;
		}

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
	 * Reports an error to the event logger. Without one it falls back to error_log(), unless the caller has
	 * already written its own error_log() line ($fallbackToErrorLog false), so each error is logged once.
	 */
	private function logError(Throwable $throwable, bool $fallbackToErrorLog = true): void {
		if (!isset($this->eventLogger)) {
			if (!$fallbackToErrorLog) {
				return;
			}
			error_log(sprintf(
				'ABsmartly SDK Error: %s in %s:%d',
				$throwable->getMessage(),
				$throwable->getFile(),
				$throwable->getLine()
			));
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

	/**
	 * Numeric UIDs are accepted and stored as strings.
	 *
	 * @param string|int|float $uid
	 */
	public function setUnit(string $unitType, $uid): Context {
		$this->checkNotClosed();
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
			if ($assignment->switchback !== null) {
				// A switchback resolved before any linked unit was set is resolved again now that one may be. Only an
				// override published anything, so only an exposed override is kept.
				if ($assignment->switchback->serving->status === SwitchbackServing::ABSENT
					&& (!$assignment->overridden || !$assignment->exposed)) {
					unset($this->assignmentCache[$experimentName]);
				}
				continue;
			}

			if ($assignment->unitType !== $unitType) {
				continue;
			}

			// Holdout arms are drawn with the assignment's own unit type, so while it was missing every arm is null.
			if (($assignment->isRuleMissingUnit && !$assignment->isUnitPinnedMissing)
				|| (!$assignment->exposed && $assignment->holdoutAssignments !== null)) {
				unset($this->assignmentCache[$experimentName]);
			}
		}
	}

	/**
	 * @param mixed $uid
	 */
	private static function validateUnit(string $unitType, $uid): string {
		if (is_int($uid) || is_float($uid)) {
			return (string) $uid;
		}

		if (!is_string($uid)) {
			throw new InvalidArgumentException(sprintf('Unit "%s" must be a string or a number', $unitType));
		}

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
		if ($this->flushing) {
			return;
		}
		$this->flushing = true;
		try {
			$this->publishQueuedEvents($rethrow);
		}
		finally {
			$this->flushing = false;
		}
	}

	private function publishQueuedEvents(bool $rethrow): void {
		if ($this->getPendingCount() === 0) {
			return;
		}

		if ($this->isFailed()) {
			$errorMsg = sprintf(
				'ABsmartly SDK Warning: Discarding %d exposures and %d goals due to failed context state',
				count($this->exposures),
				count($this->achievements)
			);
			error_log($errorMsg);
			if (isset($this->eventLogger)) {
				$this->eventLogger->handleEvent($this, new ContextEventLoggerEvent(
					ContextEventLoggerEvent::Error,
					new \RuntimeException($errorMsg)
				));
			}
			$this->exposures = [];
			$this->exposureAssignments = [];
			$this->achievements = [];
			$this->pendingCount = 0;

			return;
		}

		if ($this->hasSwitchbacks && !$this->publishEarlierSwitchbackUnits($rethrow)) {
			return;
		}
		if ($this->pendingCount === 0) {
			return;
		}

		$event = $this->buildPublishEvent();

		try {
			$this->eventHandler->publish($event);
		}
		catch (Exception $exception) {
			$this->publishFailed($exception, $rethrow);
			return;
		}

		// The collector has accepted the batch: clear it before notifying the event logger so a throwing logger
		// can never cause these events to be retained and sent again.
		$this->exposures = [];
		$this->exposureAssignments = [];
		$this->achievements = [];
		$this->pendingCount = 0;

		$this->logPublished($event);
	}

	/** Keeps the queued events for a retry, capped, and re-throws when $rethrow is true (close()). */
	private function publishFailed(Exception $exception, bool $rethrow): void {
		error_log(sprintf(
			'ABsmartly SDK Error: Failed to publish %d exposures and %d goals: %s. Events preserved for retry.',
			count($this->exposures),
			count($this->achievements),
			$exception->getMessage()
		));
		$this->logError($exception, false);
		$this->capRetainedEvents();

		if ($rethrow) {
			throw $exception;
		}
	}

	private function logPublished(PublishEvent $event): void {
		try {
			$this->logEvent(ContextEventLoggerEvent::Publish, $event);
		}
		catch (Throwable $exception) {
			// Not a publish failure: report it without re-entering the logger that just threw.
			error_log(sprintf(
				'ABsmartly SDK Error: Event logger failed handling Publish: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
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
				$cached = $this->assignmentCache[self::assignmentCacheKey($assignment)] ?? null;
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
		error_log($errorMsg);
		$this->logError(new \RuntimeException($errorMsg), false);
	}

	private function buildPublishEvent(): PublishEvent {
		$event = new PublishEvent();
		$event->setUnits($this->units);
		$event->setAttributes($this->attributes);
		$event->exposures = $this->exposures;
		$event->goals = $this->achievements;

		return $event;
	}

	/**
	 * Publishes, before the main batch, each queued switchback exposure whose serving was read before a unit was added,
	 * in its own batch with the units of that read: the Collector resolves the serving unit again from a publish's
	 * units, and a unit added since could make it drop the exposure or record it under another unit. Each batch is a
	 * separate handler call and Publish event. Returns false when a batch fails; it and the later events stay queued.
	 */
	private function publishEarlierSwitchbackUnits(bool $rethrow): bool {
		$batches = [];
		foreach ($this->exposureAssignments as $i => $assignment) {
			// An absent serving had no linked unit to protect: a unit added since is what lets the Collector record it.
			if ($assignment->switchback !== null && $assignment->switchback->serving->status !== SwitchbackServing::ABSENT
				&& $assignment->switchback->units !== $this->units) {
				$batches[serialize($assignment->switchback->units)][] = $i;
			}
		}

		foreach ($batches as $indexes) {
			$event = new PublishEvent();
			$event->setUnits($this->exposureAssignments[$indexes[0]]->switchback->units);
			$event->setAttributes($this->attributes);
			$exposures = [];
			foreach ($indexes as $i) {
				$exposures[] = $this->exposures[$i];
			}
			$event->exposures = $exposures;

			try {
				$this->eventHandler->publish($event);
			}
			catch (Exception $exception) {
				$this->exposures = array_values($this->exposures);
				$this->exposureAssignments = array_values($this->exposureAssignments);
				$this->publishFailed($exception, $rethrow);
				return false;
			}

			foreach ($indexes as $i) {
				unset($this->exposures[$i], $this->exposureAssignments[$i]);
			}
			$this->pendingCount -= count($indexes);

			$this->logPublished($event);
		}

		if ($batches !== []) {
			$this->exposures = array_values($this->exposures);
			$this->exposureAssignments = array_values($this->exposureAssignments);
		}

		return true;
	}

	/**
	 * @param object|array|null $properties any object, or an associative array
	 */
	public function track(string $goalName, $properties = null): void {
		$this->checkNotClosed();
		$properties = $this->validateGoal($goalName, $properties);
		$achievement = new GoalAchievement($goalName, static::getTime(), $properties);
		$this->achievements[] = $achievement;
		++$this->pendingCount;

		$this->logEvent(ContextEventLoggerEvent::Goal, $achievement);
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

		// A context whose initial load failed stays failed: refresh() is a no-op that neither fetches nor
		// applies data and emits no refresh/error event, matching the JS SDK's `_refresh` (`if (!this._failed)`).
		if ($this->isFailed()) {
			return;
		}

		$oldData = $this->data;
		$oldIndex = $this->index;
		$oldIndexVariables = $this->indexVariables;
		$oldRuleKeys = $this->ruleKeys;
		$oldParsedRules = $this->parsedRules;
		$oldHoldoutsById = $this->holdoutsById;
		$oldExperimentHoldouts = $this->experimentHoldouts;
		$oldSwitchbackSources = $this->switchbackSources;
		try {
			$data = $this->dataProvider->getContextData();
			$this->setData($data);
		}
		catch (Exception $exception) {
			$this->data = $oldData;
			$this->index = $oldIndex;
			$this->indexVariables = $oldIndexVariables;
			$this->ruleKeys = $oldRuleKeys;
			$this->parsedRules = $oldParsedRules;
			$this->holdoutsById = $oldHoldoutsById;
			$this->experimentHoldouts = $oldExperimentHoldouts;
			$this->switchbackSources = $oldSwitchbackSources;
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to refresh context, keeping existing data: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
			$this->logError($exception, false);
			return;
		}

		$this->failed = false;

		// The data is installed, so a throwing logger no longer rolls it back: assignments a Refresh handler built from
		// it must stay consistent with the data in use (the JS SDK swallows logger errors the same way).
		try {
			$this->logEvent(ContextEventLoggerEvent::Refresh, $data);
		}
		catch (Throwable $exception) {
			error_log(sprintf(
				'ABsmartly SDK Error: Event logger failed handling Refresh: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
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
