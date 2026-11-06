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
use ABSmartly\SDK\Utils;
use ABSmartly\SDK\VariableParser;
use ABSmartly\SDK\VariantAssigner;

use Exception;
use JsonException;
use Throwable;

use function array_slice;
use function count;
use function get_object_vars;
use function gettype;
use function hash;
use function is_int;
use function is_object;
use function json_decode;
use function microtime;
use function min;
use function strpos;
use function sprintf;
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
	private array $achievements = [];

	private array $attributes = [];
	private array $overrides = [];
	private array $cassignments = [];

	private int $pendingCount = 0;
	private bool $closed = false;
	private bool $finalizing = false;
	private bool $ready;
	private int $attrsSeq = 0;
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

	private function setData(ContextData $data): void {
		$this->data = $data;
		$this->index = [];
		$this->indexVariables = [];

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
					$this->indexVariables[$key] = $experimentVariables;
				}
				$experimentVariables->variables[] = $parsed;
			}

			$this->index[$experiment->name] = $experimentVariables;
		}
	}

	private function setDataFailed(?Throwable $exception = null): void {
		$this->indexVariables = [];
		$this->index = [];
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
		} catch (Exception $exception) {
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
		if (!empty($experiment->audience) && !empty((array) $experiment->audience)) {
			if ($this->attrsSeq > ($assignment->attrsSeq ?? 0)) {
				$attrs = $this->getAttributes();
				$result = $this->audienceMatcher->evaluate($experiment->audience, $attrs);
				$newAudienceMismatch = $result !== null ? !$result : $assignment->audienceMismatch;

				if ($newAudienceMismatch !== $assignment->audienceMismatch) {
					return false;
				}

				$assignment->attrsSeq = $this->attrsSeq;
			}
		}
		return true;
	}

	private function getAssignment(string $experimentName): Assignment {
		$experiment = $this->getExperiment($experimentName);

		if (isset($this->assignmentCache[$experimentName])) {
			$assignment = $this->assignmentCache[$experimentName];
			if (array_key_exists($experimentName, $this->overrides)) {
				$override = $this->overrides[$experimentName];
				if ($assignment->overridden && $assignment->variant === $override) {
					// override up-to-date
					return $assignment;
				}
			}
			else if ($experiment === null) {
				if (!$assignment->assigned) {
					// previously not-running experiment
					return $assignment;
				}
			} else if (!array_key_exists($experimentName, $this->cassignments) || ($assignment->custom && $this->cassignments[$experimentName] === $assignment->variant)) {
				if ($this->experimentMatches($experiment->data, $assignment) && $this->audienceMatches($experiment->data, $assignment)) {
					// assignment up-to-date
					return $assignment;
				}
			}
		}

		$this->assignmentCache[$experimentName] = $assignment = new Assignment();

		$assignment->name = $experimentName;
		$assignment->eligible = true;

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
			if (!empty($experiment->data->audience) && !empty((array) $experiment->data->audience)) {
				$attrs = $this->getAttributes();

				$result = $this->audienceMatcher->evaluate($experiment->data->audience, $attrs);
				if (is_bool($result)) {
					$assignment->audienceMismatch = !$result;
				}
			}

			if (!empty($experiment->data->audienceStrict) && !empty($assignment->audienceMismatch)) {
				$assignment->variant = 0;
			}
			else if (empty($experiment->data->fullOnVariant) && ($uid = $this->units[$experiment->data->unitType] ?? null) !== null) {
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
			else {
				$assignment->assigned = true;
				$assignment->variant = $experiment->data->fullOnVariant;
				$assignment->fullOn = true;
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

	public function getVariableValue(string $key, $defaultValue = null) {
		$this->checkReady();
		$assignment = $this->getVariableAssignment($key);

		if ($assignment === null) {
			return $defaultValue;
		}

		if ($assignment->variables !== null) {
			if (empty($assignment->exposed)) {
				$this->queueExposure($assignment);
			}

			if (isset($assignment->variables->{$key}) && ($assignment->assigned || $assignment->overridden)) {
				return $assignment->variables->{$key};
			}
		}

		return $defaultValue;
	}

	public function getVariableKeys(): array {
		$this->checkReady(false);
		$return = [];
		foreach ($this->indexVariables as $variable => $experimentVars) {
			$return[$variable] = $experimentVars->data->name;
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

	private function getVariantAssigner(string $unitType, string $unitHash): VariantAssigner {
		return $this->assigners[$unitType] ?? ($this->assigners[$unitType] = new VariantAssigner($unitHash));
	}

	public function getTreatment(string $experimentName): int {
		$this->checkReady();
		$assignment = $this->getAssignment($experimentName);
		if (empty($assignment->exposed)) {
			$this->queueExposure($assignment);
		}

		return $assignment->variant;
	}

	private function queueExposure(Assignment $assignment): void {
		if (!empty($assignment->exposed)) {
			return;
		}

		$exposure = new Exposure();
		$assignment->exposed = true;
		$exposure->ingestAssignment($assignment);

		$this->exposures[] = $exposure;
		++$this->pendingCount;

		$this->logEvent(ContextEventLoggerEvent::Exposure, $exposure);
	}

	private function logEvent(string $event, ?object $data): void {
		if (!isset($this->eventLogger)) {
			return;
		}

		$this->eventLogger->handleEvent($this, new ContextEventLoggerEvent($event, $data));
	}

	private function logError(Throwable $throwable): void {
		if (!isset($this->eventLogger)) {
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


	public function peekVariableValue(string $key, $default) {
		$this->checkReady(false);
		$assignment = $this->getVariableAssignment($key);
		if ($assignment && isset($assignment->variables->{$key})) {
			return $assignment->variables->{$key};
		}

		return $default;
	}

	private function getVariableAssignment(string $key): ?Assignment {
		$experiment = $this->getVariableExperiment($key);
		if (!$experiment) {
			return null;
		}

		return $this->getAssignment($experiment->data->name);
	}

	private function getVariableExperiment(string $experimentName): ?ExperimentVariables {
		return $this->indexVariables[$experimentName] ?? null;
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
	 * Numeric UIDs are accepted and stored as strings, as in earlier releases.
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
		return $this;
	}

	/**
	 * @param mixed $uid
	 */
	private static function validateUnit(string $unitType, $uid): string {
		if (is_int($uid) || is_float($uid)) {
			$uid = (string) $uid;
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
			$this->achievements = [];
			$this->pendingCount = 0;

			return;
		}

		if ($this->getPendingCount() === 0) {
			return;
		}

		$event = $this->buildPublishEvent();

		try {
			$this->eventHandler->publish($event);
			$this->logEvent(ContextEventLoggerEvent::Publish, $event);
			$this->exposures = [];
			$this->achievements = [];
			$this->pendingCount = 0;
		}
		catch (Exception $exception) {
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to publish %d exposures and %d goals: %s. Events preserved for retry.',
				count($this->exposures),
				count($this->achievements),
				$exception->getMessage()
			));
			$this->logError($exception);
			$this->capRetainedEvents();

			if ($rethrow) {
				throw $exception;
			}
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
			$this->exposures = array_slice($this->exposures, $droppedExposures);
			$toDrop -= $droppedExposures;
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
		$oldData = $this->data;
		$oldIndex = $this->index;
		$oldIndexVariables = $this->indexVariables;
		try {
			$data = $this->dataProvider->getContextData();
			$this->setData($data);
			$this->failed = false;
			$this->logEvent(ContextEventLoggerEvent::Refresh, $data);
		}
		catch (Exception $exception) {
			$this->data = $oldData;
			$this->index = $oldIndex;
			$this->indexVariables = $oldIndexVariables;
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to refresh context, keeping existing data: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
			$this->logError($exception);
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
