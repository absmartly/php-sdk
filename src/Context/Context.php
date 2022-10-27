<?php

namespace Absmartly\SDK\Context;

use Absmartly\SDK\Assignment;
use Absmartly\SDK\AudienceMatcher;
use Absmartly\SDK\Experiment;
use Absmartly\SDK\ExperimentVariables;
use Absmartly\SDK\Exposure;
use Absmartly\SDK\SDK;
use Absmartly\SDK\VariableParser;
use Absmartly\SDK\VariantAssigner;
use InvalidArgumentException;

class Context {

	protected SDK $sdk;

	protected int $timestamp;
	private int $publishDelay = 100;
	private int $refreshInterval = 0;

	protected ContextEventHandler $eventHandler;
	protected ContextEventLogger $eventLogger;
	protected ContextDataProvider $dataProvider;
	protected VariableParser $variableParser;
	protected AudienceMatcher $audienceMatcher;
	protected ScheduledExecutorService $scheduler;

	protected array $units = [];
	protected bool $failed;

	protected ContextData $data;

	protected array $index;
	protected array $indexVariables;


	protected array $hashedUnits;
	protected array $assigners = [];
	protected array $assignmentCache;

	protected array $exposures = [];
	protected array $achivements;

	protected array $attributes = [];
	protected array $overrides = [];
	protected array $cassignments = [];



	protected int $pendingCount = 0;
	protected bool $closing = false;
	protected bool $closed = false;
	protected bool $refreshing = false;

//	private volatile CompletableFuture<Void> readyFuture_;
//	private volatile CompletableFuture<Void> closingFuture_;
//	private volatile CompletableFuture<Void> refreshFuture_;

	protected ReentrantLock $timeoutLock;
	protected ?ScheduledFuture $timeout = null;
	protected ?ScheduledFuture $refreshTimer = null;








	public function isReady(): bool {}
	public function isFailed(): bool {
		return $this->failed;
	}
	public function isClosed(): bool {}
	public function isClosing(): bool {}



	public function __construct(SDK $sdk, ContextConfig $contextConfig, ContextData $data) {
		$this->sdk = $sdk;
		$this->data = $data;
		$this->setUnits($contextConfig->getUnits());
		$this->setOverrides($contextConfig->getOverrides());
		$this->setCustomAssignments($contextConfig->getCustomAssignments());

		if ($logger = $contextConfig->getEventLogger()) {
			$this->setEventLogger($logger);
		}

		$this->audienceMatcher = new AudienceMatcher();
		$this->variableParser = new VariableParser();

		$this->setData($data);
	}

	public function setEventLogger(ContextEventLogger $eventLogger): Context {
		$this->eventLogger = $eventLogger;
	}

	public function setData(ContextData $data): void {
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

	public static function createFromContextConfig(SDK $sdk, ContextConfig $contextConfig, ContextData $data): Context {
		return new Context($sdk, $contextConfig, $data);
	}

	public function checkReady(): void {

	}

	public function getExperiment(string $experimentName): ?ExperimentVariables {
		if (isset($this->index[$experimentName])) {
			return $this->index[$experimentName];
		}
		return null;
	}

	public function getExperiments(): array {
		if (!empty($this->data->experiments)) {
			return array_keys($this->data->experiments);
		}
		return [];
	}

	protected function getAssignment(string $experimentName): Assignment {
		$experiment = $this->getExperiment($experimentName);

		if (isset($this->assignmentCache[$experimentName])) {
			//return $this->assignmentCache[$experimentName] = $this->refreshAssignmentCache($this->assignmentCache[$experimentName]);
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
			if (!empty($experiment->data->audience)) {
				$attrs = [];
				foreach ($this->attributes as $name => $value) {
					$attrs[$name] = $value;
				}

				$result = $this->audienceMatcher->evaluate($experiment->data->audience, $attrs);
				$assignment->audienceMismatch = !$result;
			}


			if (isset($experiment->data->audienceStrict) && !empty($assignment->audienceMismatch)) {
				$assignment->variant = 0;
			}
			else if (empty($experiment->data->fullOnVariant) && $uid = $this->units[$experiment->data->unitType] ?? null) {
				$unitHash = $this->getUnitHash($unitType, $uid);
				$assigner = $this->getVariantAssigner($unitType, $unitHash);

				$eligible = $assigner->assign(
					$experiment->data->trafficSplit,
					$experiment->data->seedHi,
					$experiment->data->seedLo
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

		if (($experiment !== null) && ($assignment->variant < count($experiment->data->variants))) {
			$assignment->variables = $experiment->variables[$assignment->variant];
		}

		return $assignment;
	}

	public function getVariableValue(string $key, $defaultValue) {
		$this->checkReady();
		$assignment = $this->getVariableAssignment($key);

		// if ($assignment->exposed) // TODO
		//

		return $assignment->variables->{$key} ?? $defaultValue;
	}

	public function getVariableKeys(): array {
		$return = [];
		foreach ($this->indexVariables as $variable => $experimentVars) {
			$return[$variable] = $experimentVars->data->name;
		}

		return $return;
	}

	public function setAttribute(string $name, string $value): Context {
		$this->attributes[$name] = $value;
		return $this;
	}



	private function refreshAssignmentCache(Assignment $assignment): Assignment {

	}
















































































































































	private function getUnitHash(string $unitType, string $unitUID): string {
		if (isset($this->hashedUnits[$unitType])) {
			return $this->hashedUnits[$unitType];
		}

		$this->hashedUnits[$unitType] = hash("md5", $unitUID, true);
		$this->hashedUnits[$unitType] = strtr(base64_encode($this->hashedUnits[$unitType]), [
			'+' => '-',
			'/' => '_',
			'=' => '',
		]);

		return $this->hashedUnits[$unitType];
	}

	public function getVariantAssigner(string $unitType, string $unitHash): VariantAssigner {
		if (isset($this->assigners[$unitType])) {
			return $this->assigners[$unitType];
		}

		return $this->assigners[$unitType] = new VariantAssigner($unitHash);
	}


	public function getTreatment(string $experimentName): int {
		$this->checkReady(true);
		$assignment = $this->getAssignment($experimentName);
		if (empty($assignment->exposed)) {
			$this->queueExposure($assignment);
		}

		return $assignment->variant;
	}

	protected function queueExposure(Assignment $assignment): void {
		if (!empty($assignment->exposed)) {
			return;
		}

		$exposure = new Exposure();
		$assignment->exposed = true;
		$exposure->ingestAssignment($assignment);

		++$this->pendingCount;

		$this->logEvent(ContextEventLogger::EVENT_EXPOSURE, $exposure);
	}

	protected function logEvent(string $event, object $data): void {

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

	public function getData(): ContextData {
		return $this->data;
	}

	public function peekTreatment(string $experimentName): int {
		$this->checkReady(true);
		return $this->getAssignment($experimentName)->variant;
	}


	public function setUnits(array $units): Context {
		// It could have been possible to simply array_merge here, but we need
		// to verify strict-types, hence the foreach loop.
		foreach ($units as $key => $value) {
			if (!is_scalar($value)) {
				throw new InvalidArgumentException(sprintf('Unit set value with key "%s" must be of type string, %s passed', $key, gettype($value)));
			}

			$this->setUnit($key, $value);
		}

		return $this;
	}

	public function setOverrides(array $overrides): Context {
		// See note in ContextConfig::setUnits
		foreach ($overrides as $experimentName => $variant) {
			if (!is_integer($variant)) {
				throw new InvalidArgumentException(sprintf('Override set value with key "%s" must be of type integer, %s passed', $experimentName, gettype($variant)));
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
			if (!is_integer($variant)) {
				throw new InvalidArgumentException(sprintf('Custom assignment set value with key "%s" must be of type integer, %s passed', $experimentName, gettype($variant)));
			}

			$this->setCustomAssignment($experimentName, (int) $variant);
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
}
