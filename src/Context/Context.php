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
use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\VariableParser;
use ABSmartly\SDK\VariantAssigner;

use Exception;
use Throwable;

use function count;
use function get_object_vars;
use function gettype;
use function hash;
use function is_int;
use function microtime;
use function sprintf;
use function trim;

class Context {

	private ABsmartly $sdk;

	private ContextPublisher $eventHandler;
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
		$keys = [];
		if (!empty($this->data->experiments)) {
			foreach ($this->data->experiments as $experiment) {
				if (!isset($experiment->customFieldValues)) {
					continue;
				}

				$customFieldValues = $experiment->customFieldValues;
				if (is_string($customFieldValues)) {
					$customFieldValues = json_decode($customFieldValues, true) ?? [];
				}
				if (is_object($customFieldValues)) {
					$customFieldValues = get_object_vars($customFieldValues);
				}
				if (!is_array($customFieldValues)) {
					continue;
				}

				foreach (array_keys($customFieldValues) as $k) {
					if (substr($k, -5) !== '_type') {
						$keys[$k] = true;
					}
				}
			}
		}
		return array_keys($keys);
	}

	public function getCustomFieldValueType(string $experimentName, string $key): ?string {
		$experiment = $this->getExperiment($experimentName);
		if ($experiment === null || !isset($experiment->data->customFieldValues)) {
			return null;
		}

		$customFieldValues = $experiment->data->customFieldValues;
		if (is_string($customFieldValues)) {
			$customFieldValues = json_decode($customFieldValues, true) ?? [];
		}
		if (is_object($customFieldValues)) {
			$customFieldValues = get_object_vars($customFieldValues);
		}
		if (!is_array($customFieldValues)) {
			return null;
		}

		return $customFieldValues[$key . '_type'] ?? null;
	}

	public function pending(): int {
		return $this->pendingCount;
	}

	public static function getTime(): int {
		return (int) (microtime(true) * 1000);
	}

	private function __construct(ABsmartly $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ?ContextData $contextData = null, bool $pending = false) {
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
				$data = $this->data = $this->dataProvider->getContextData();
			}
			else {
				$data = $this->data = $contextData;
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

	private function setEventHandler(ContextPublisher $eventHandler): Context {
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
		$this->data = null;
		$this->failed = true;
		$this->readyError = $exception;
	}

	public static function createFromContextConfig(ABsmartly $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ContextPublisher $handler, ?ContextData $contextData = null): Context {
		$context = new Context($sdk, $contextConfig, $dataProvider, $contextData);
		$context->setEventHandler($handler);

		return $context;
	}

	public static function createPending(ABsmartly $sdk, ContextConfig $contextConfig, ContextDataProvider $dataProvider, ContextPublisher $handler): Context {
		$context = new Context($sdk, $contextConfig, $dataProvider, null, true);
		$context->setEventHandler($handler);

		return $context;
	}

	public function setContextData(ContextData $contextData): void {
		if ($this->ready) {
			return;
		}

		try {
			$this->data = $contextData;
			$this->setData($contextData);
			$this->ready = true;
			$this->logEvent(ContextEventLoggerEvent::Ready, $contextData);
		} catch (Exception $exception) {
			$this->setDataFailed($exception);
			$this->logError($exception);
		}
	}

	private function checkReady(): void {
		if (!$this->isReady()) {
			throw new LogicException('ABsmartly Context is not yet ready.');
		}

		$this->checkNotClosed();
	}

	public function getExperiment(string $experimentName): ?ExperimentVariables {
		return $this->index[$experimentName] ?? null;
	}

	public function getExperiments(): array {
		$return = [];

		if (!empty($this->data->experiments)) {
			foreach ($this->data->experiments as $experiment) {
				$return[] = $experiment->name;
			}
		}

		return $return;
	}

	public function customFieldValue(string $experimentName, string $fieldName) {
		$experiment = $this->getExperiment($experimentName);
		if ($experiment === null || !isset($experiment->data->customFieldValues)) {
			return null;
		}

		$customFieldValues = $experiment->data->customFieldValues;
		if (is_string($customFieldValues)) {
			try {
				$customFieldValues = json_decode($customFieldValues, true, 512, JSON_THROW_ON_ERROR);
			}
			catch (\JsonException $e) {
				error_log(sprintf(
					'ABsmartly SDK Error: Failed to decode custom field values for experiment "%s": %s',
					$experimentName,
					$e->getMessage()
				));
				return null;
			}
		}

		if (is_object($customFieldValues)) {
			$customFieldValues = get_object_vars($customFieldValues);
		}

		if (!isset($customFieldValues[$fieldName])) {
			return null;
		}

		$value = $customFieldValues[$fieldName];
		$type = $customFieldValues[$fieldName . '_type'] ?? null;

		if ($type === 'json' && is_string($value)) {
			try {
				return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
			}
			catch (\JsonException $e) {
				error_log(sprintf(
					'ABsmartly SDK Error: Failed to decode JSON custom field "%s" for experiment "%s": %s',
					$fieldName,
					$experimentName,
					$e->getMessage()
				));
				return null;
			}
		}

		if ($type === 'number' && is_string($value)) {
			if (strpos($value, '.') !== false) {
				return (float) $value;
			}
			return (int) $value;
		}

		if (substr($type ?? '', 0, 7) === 'boolean' && is_string($value)) {
			return $value === 'true' || $value === '1';
		}

		return $value;
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
				$newAudienceMismatch = !$result;

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
			} else if (!isset($this->cassignments[$experimentName]) || $this->cassignments[$experimentName] === $assignment->variant) {
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
				$assignment->audienceMismatch = !$result;
			}

			if (!empty($experiment->data->audienceStrict) && !empty($assignment->audienceMismatch)) {
				$assignment->variant = 0;
			}
			else if (empty($experiment->data->fullOnVariant) && $uid = $this->units[$experiment->data->unitType] ?? null) {
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
		if (!$this->isReady() || $this->isClosed()) {
			return $defaultValue;
		}
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
		$return = [];
		foreach ($this->indexVariables as $variable => $experimentVars) {
			$return[$variable] = $experimentVars->data->name;
		}

		return $return;
	}

	public function setAttribute(string $name, $value): Context {
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
		if (!$this->isReady() || $this->isClosed()) {
			return 0;
		}
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
		if (!$this->isReady() || $this->isClosed()) {
			return 0;
		}
		return $this->getAssignment($experimentName)->variant;
	}


	public function setUnits(array $units): Context {
		// It could have been possible to simply array_merge here, but we need
		// to verify strict-types, hence the foreach loop.
		foreach ($units as $key => $value) {
			if (!is_string($value)) {
				throw new InvalidArgumentException(
					sprintf('Unit set value with key "%s" must be of type string, %s passed', $key, gettype($value)));
			}

			$this->setUnit($key, $value);
		}

		return $this;
	}

	public function getUnit(string $unitType) {
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
		if (isset($this->units[$unitType]) && $this->units[$unitType] !== $uid) {
			throw new InvalidArgumentException(sprintf('Unit "%s" UID is already set', $unitType));
		}
		if (trim($uid) === '') {
			throw new InvalidArgumentException(sprintf('Unit "%s" UID must not be blank', $unitType));
		}

		$this->units[$unitType] = $uid;
		return $this;
	}

	public function getPendingCount(): int {
		return $this->pendingCount;
	}

	private function checkNotClosed(): void {
		if ($this->isClosed()) {
			throw new LogicException('ABsmartly Context is finalized.');
		}
	}

	public function flush(): void {
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
			$this->failed = true;
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to publish %d exposures and %d goals: %s. Data will be lost.',
				count($this->exposures),
				count($this->achievements),
				$exception->getMessage()
			));
			$this->logError($exception);
		}
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
			$this->logEvent(ContextEventLoggerEvent::Refresh, $data);
		}
		catch (Exception $exception) {
			$this->data = $oldData;
			$this->index = $oldIndex;
			$this->indexVariables = $oldIndexVariables;
			$this->failed = true;
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to refresh context, keeping existing data: %s in %s:%d',
				$exception->getMessage(),
				$exception->getFile(),
				$exception->getLine()
			));
			$this->logError($exception);
		}
	}

	public function close(): void {
		if ($this->isClosed()) {
			return;
		}

		$this->finalizing = true;
		if ($this->getPendingCount() > 0) {
			$this->flush();
		}

		$this->logEvent(ContextEventLoggerEvent::Finalize, null);
		$this->closed = true;
		$this->finalizing = false;
	}

}
