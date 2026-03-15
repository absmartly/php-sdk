<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;
use ABSmartly\SDK\Context\ContextEventHandler;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
use ABSmartly\SDK\Exposure;
use ABSmartly\SDK\GoalAchievement;
use ABSmartly\SDK\PublishEvent;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase {

	private array $units = [
		"session_id" => "e791e240fcd3df7d238cfc285f475e8152fcc0ec",
		"user_id" => "123456789",
		"email" => "bleh@absmartly.com",
	];

	private array $attributes = [
		"attr1" => "value1",
		"attr2" => "value2",
		"attr3" => 5,
	];

	private array $expectedVariants = [
		"exp_test_ab" => 1,
		"exp_test_abc" => 2,
		"exp_test_not_eligible" => 0,
		"exp_test_fullon" => 2,
		"exp_test_new" => 1,
	];

	private array $expectedVariables = [
		"banner.border" => 1,
		"banner.size" => "large",
		"button.color" => "red",
		"submit.color" => "blue",
		"submit.shape" => "rect",
		"show-modal" => true,
	];

	private array $variableExperiments = [
		"banner.border" => "exp_test_ab",
		"banner.size" => "exp_test_ab",
		"button.color" => "exp_test_abc",
		"card.width" => "exp_test_not_eligible",
		"submit.color" => "exp_test_fullon",
		"submit.shape" => "exp_test_fullon",
		"show-modal" =>"exp_test_new"
	];

	private ContextData $data;
	private ContextData $refreshData;
	private ContextData $audienceData;
	private ContextData $audienceStrictData;

	private ContextDataProvider $dataProvider;
	private ContextEventHandler $eventHandler;

	protected function createContext(ContextConfig $contextConfig): Context {
		$clientConfig = new ClientConfig('', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$this->dataProvider = new ContextDataProviderMock($client);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextDataProvider($this->dataProvider);
		$config->setContextEventHandler($this->eventHandler);

		return (new SDK($config))->createContext($contextConfig);
	}

	public function createReadyContext(string $source = 'context.json', bool $setUnits = true, ?ContextEventLogger $logger = null): Context {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$this->dataProvider = new ContextDataProviderMock($client);
		$this->dataProvider->setSource($source);
		$config->setContextDataProvider($this->dataProvider);

		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		if ($logger) {
			$contextConfig->setEventLogger($logger);
		}
		if ($setUnits) {
			$contextConfig->setUnits($this->units);
		}

		return (new SDK($config))->createContext($contextConfig);
	}

	private function getExperimentsList(Context $context): array {
		$experimentObjects = $context->getContextData()->experiments;
		$experiments = [];
		foreach ($experimentObjects as $experiment) {
			$experiments[] = $experiment->name;
		}

		return $experiments;
	}

	private function getContextData(string $source = 'context.json'): ContextData {
		$this->dataProvider->setSource($source);
		return $this->dataProvider->getContextData();
	}


	public function tearDown(): void {
		if (isset($this->eventHandler->submitted)) {
			$this->eventHandler->submitted = [];
			$this->eventHandler->prerun = null;
		}

		if (isset($this->dataProvider->prerun)) {
			$this->dataProvider->prerun = null;
		}
	}

	/*
	 * =============================================================================
	 *                                  TESTS
	 * =============================================================================
	 */


	public function testConstructorSetsOverrides(): void {
		$overrides = [
			"exp_test" => 2,
			"exp_test_1" => 1
		];

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);
		$contextConfig->setOverrides($overrides);

		$context = $this->createContext($contextConfig);

		foreach ($overrides as $experimentName => $variant) {
			self::assertSame($variant, $context->getOverride($experimentName));
		}
	}

	public function testConstructorSetsCustomAssignments(): void {
		$cassignments = [
			"exp_test" => 2,
			"exp_test_1" => 1
		];

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);
		$contextConfig->setCustomAssignments($cassignments);

		$context = $this->createContext($contextConfig);

		foreach ($cassignments as $experimentName => $variant) {
			self::assertSame($variant, $context->getCustomAssignment($experimentName));
		}
	}

	public function testBecomesReadyWithFulfilledPromise(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
	}

	public function testCallsEventLoggerWhenReady(): void {
		$eventHandler = new MockContextEventLoggerProxy();

		$this->createReadyContext('context.json', true, $eventHandler);
		self::assertSame(1, $eventHandler->called);
		self::assertSame(ContextEventLoggerEvent::Ready, $eventHandler->events[0]->getEvent());
	}

	public function testGetExperiments(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);
		$context = $this->createContext($contextConfig);

		$experiments = $context->getExperiments();
		self::assertEquals($this->getExperimentsList($context), $experiments);
	}

	public function testSetUnit(): void {
		$config = new ContextConfig();
		$config->setUnit('session_id', '0ab1e23f4eee');
		self::assertSame('0ab1e23f4eee', $config->getUnit('session_id'));
	}

	public function testSetOverride(): void {
		$context = $this->createReadyContext();

		$context->setOverride("exp_test", 2);
		self::assertSame(2, $context->getOverride('exp_test'));

		$context->setOverride("exp_test", 3);
		self::assertSame(3, $context->getOverride('exp_test'));

		$context->setOverride("exp_test_2", 1);
		self::assertSame(1, $context->getOverride('exp_test_2'));

		$overrides = [
			'exp_test_new' => 3,
			'exp_test_new_2' => 5,
		];
		$context->setOverrides($overrides);
		self::assertSame(3, $context->getOverride('exp_test'));
		self::assertSame(1, $context->getOverride('exp_test_2'));
		self::assertSame(3, $context->getOverride('exp_test_new'));
		self::assertSame(5, $context->getOverride('exp_test_new_2'));

		self::assertNull($context->getOverride('exp_test_not_found'));
	}

	public function testSetOverridesGenericsThrows(): void {
		$context = $this->createReadyContext();
		$this->expectException(\InvalidArgumentException::class);
		$context->setOverrides(['test' => '1']);
	}

	public function testSetOverrideClearsAssignmentCache(): void {
		$context = $this->createReadyContext();

		$overrides = [
			'exp_test_new' => 3,
			'exp_test_new_2' => 5,
		];

		$context->setOverrides($overrides);

		foreach ($overrides as $experimentName => $variant) {
			self::assertSame($variant, $context->getTreatment($experimentName));
		}

		self::assertSame(count($overrides), $context->getPendingCount());

		// overriding again with the same variant shouldn't clear assignment cache
		foreach ($overrides as $experimentName => $variant) {
			$context->setOverride($experimentName, $variant);
			self::assertSame($variant, $context->getTreatment($experimentName));
		}
		self::assertSame(count($overrides), $context->getPendingCount());

		// overriding with the different variant should clear assignment cache
		foreach ($overrides as $experimentName => $variant) {
			$context->setOverride($experimentName, $variant + 11);
			self::assertSame($variant + 11, $context->getTreatment($experimentName));
		}
		self::assertSame(2 * count($overrides), $context->getPendingCount());

		// overriding a computed assignment should clear assignment cache
		self::assertSame($this->expectedVariants['exp_test_ab'], $context->getTreatment('exp_test_ab'));
		self::assertSame(1 + (2 * count($overrides)), $context->getPendingCount());

		$context->setOverride('exp_test_ab', 9);
		self::assertSame(9, $context->getTreatment('exp_test_ab'));
		self::assertSame(2 + (2 * count($overrides)), $context->getPendingCount());
	}

	// Additional test
	public function testSetUnitEmpty(): void {
		$context = $this->createReadyContext();

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unit "db_user_id" UID must not be blank');
		$context->setUnit('db_user_id', '');
	}

	// Additional test
	public function testSetUnitThrowsOnAlreadySet(): void {
		$context = $this->createReadyContext();

		$context->setUnit('session_id', $this->units['session_id']); // This should be allowed

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unit "session_id" UID is already set');
		$context->setUnit('session_id', 'new-uid');
	}

	// Additional test
	public function testSetUnitThrowsOnGenericError(): void {
		$context = $this->createReadyContext();
		$this->expectException(\InvalidArgumentException::class);
		$context->setUnits(['test' => false]);
	}

	public function testSetCustomAssignment(): void {
		$context = $this->createReadyContext();

		$context->setCustomAssignment("exp_test", 2);
		self::assertSame(2, $context->getCustomAssignment('exp_test'));

		$context->setCustomAssignment("exp_test", 3);
		self::assertSame(3, $context->getCustomAssignment('exp_test'));

		$context->setCustomAssignment("exp_test_2", 4);
		self::assertSame(4, $context->getCustomAssignment('exp_test_2'));

		$cassignments = [
			"exp_test_new" => 3,
			"exp_test_new_2" => 5,
		];

		$context->setCustomAssignments($cassignments);

		self::assertSame(3, $context->getCustomAssignment('exp_test'));
		self::assertSame(4, $context->getCustomAssignment('exp_test_2'));
		self::assertSame(3, $context->getCustomAssignment('exp_test_new'));
		self::assertSame(5, $context->getCustomAssignment('exp_test_new_2'));

		self::assertNull($context->getCustomAssignment('exp_test_not_found'));
	}

	public function testSetCustomAssignmentsGenericsThrows(): void {
		$context = $this->createReadyContext();
		$this->expectException(\InvalidArgumentException::class);
		$context->setCustomAssignments(['test' => false]);
	}

	public function testSetCustomAssignmentDoesNotOverrideFullOnOrNotEligibleAssignments(): void {
		$context = $this->createReadyContext();

		$cassignments = [
			'exp_test_not_eligible' => 3,
			'exp_test_fullon' => 3,
		];

		$context->setCustomAssignments($cassignments);
		self::assertSame(0, $context->getTreatment("exp_test_not_eligible"));
		self::assertSame(2, $context->getTreatment("exp_test_fullon"));
	}

	public function testSetCustomAssignmentClearsAssignmentCache(): void {
		$context = $this->createReadyContext();

		$cassignments = [
			'exp_test_ab' => 2,
			'exp_test_abc' => 3,
		];

		foreach ($cassignments as $experimentName => $variant) {
			self::assertSame($this->expectedVariants[$experimentName], $context->getTreatment($experimentName));
		}

		self::assertSame(count($cassignments), $context->getPendingCount());

		$context->setCustomAssignments($cassignments);

		foreach ($cassignments as $experimentName => $variant) {
			$context->setCustomAssignment($experimentName, $variant);
			self::assertSame($variant, $context->getTreatment($experimentName));
		}

		self::assertSame(2 * count($cassignments), $context->getPendingCount());

		// overriding with the same variant shouldn't clear assignment cache
		foreach ($cassignments as $experimentName => $variant) {
			$context->setCustomAssignment($experimentName, $variant);
			self::assertSame($variant, $context->getTreatment($experimentName));
		}

		self::assertSame(2 * count($cassignments), $context->getPendingCount());

		// overriding with the different variant should clear assignment cache
		foreach ($cassignments as $experimentName => $variant) {
			$context->setCustomAssignment($experimentName, $variant + 11);
			self::assertSame($variant + 11, $context->getTreatment($experimentName));
		}

		self::assertSame(3 * count($cassignments), $context->getPendingCount());
	}

	public function testPeekTreatment(): void {
		$context = $this->createReadyContext();

		foreach ($context->getContextData()->experiments as $experiment) {
			self::assertSame($this->expectedVariants[$experiment->name], $context->peekTreatment($experiment->name));
		}

		self::assertSame(0, $context->peekTreatment('not_found'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testVariableValue(): void {
		$context = $this->createReadyContext();
		$experiments = $this->getExperimentsList($context);

		foreach ($this->variableExperiments as $variableName => $experimentName) {
			$actual = $context->peekVariableValue($variableName, 17);
			$eligible = $experimentName !== 'exp_test_not_eligible';

			if ($eligible && in_array($experimentName, $experiments, true)) {
				self::assertSame($this->expectedVariables[$variableName], $actual);
				continue;
			}

			self::assertSame(17, $actual);
		}

		self::assertSame(0, $context->getPendingCount());
	}

	public function testPeekVariableValueReturnsAssignedVariantOnAudienceMismatchNonStrictMode(): void {
		$context = $this->createReadyContext('audience_context.json');
		self::assertSame("large", $context->peekVariableValue("banner.size", "small"));
	}

	public function testPeekVariableValueReturnsControlVariantOnAudienceMismatchStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');
		self::assertSame("small", $context->peekVariableValue("banner.size",  "small"));
	}

	public function testGetVariableValue(): void {
		$context = $this->createReadyContext();
		$experiments = $this->getExperimentsList($context);

		foreach ($this->variableExperiments as $variableName => $experimentName) {
			$actual = $context->getVariableValue($variableName, 17);
			$eligible = $experimentName !== 'exp_test_not_eligible';

			if ($eligible && in_array($experimentName, $experiments, true)) {
				self::assertSame($this->expectedVariables[$variableName], $actual);
				self::assertSame($this->expectedVariables[$variableName], $actual); // Twice, for the queueExosure
				continue;
			}

			self::assertSame(17, $actual);
		}

		self::assertSame(count($context->getContextData()->experiments), $context->getPendingCount());
	}

	public function testGetVariableValueQueuesExposureWithAudienceMismatchFalseOnAudienceMatch(): void {
		$context = $this->createReadyContext('audience_context.json');
		$context->setAttribute('age', 21);

		self::assertSame("large", $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();
		self::assertArrayHasKey(0, $this->eventHandler->submitted);
		self::assertSame(21, $this->eventHandler->submitted[0]->attributes[0]->value);
		self::assertSame('exp_test_ab', $this->eventHandler->submitted[0]->exposures[0]->name);
		self::assertFalse($this->eventHandler->submitted[0]->exposures[0]->audienceMismatch);
	}

	public function testGetVariableValueQueuesExposureWithAudienceMismatchTrueOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame("large", $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();
		self::assertArrayHasKey(0, $this->eventHandler->submitted);
		self::assertSame('exp_test_ab', $this->eventHandler->submitted[0]->exposures[0]->name);
		self::assertTrue($this->eventHandler->submitted[0]->exposures[0]->audienceMismatch);
	}

	// Function name yikes!
	public function testGetVariableValueQueuesExposureWithAudienceMismatchFalseAndControlVariantOnAudienceMismatchInStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');

		self::assertSame("small", $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();
		self::assertArrayHasKey(0, $this->eventHandler->submitted);
		self::assertSame('exp_test_ab', $this->eventHandler->submitted[0]->exposures[0]->name);
		self::assertTrue($this->eventHandler->submitted[0]->exposures[0]->audienceMismatch);
	}

	public function testGetVariableValueCallsEventLogger(): void {
		$eventHandler = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $eventHandler);

		self::assertSame(1, $eventHandler->called);
		$eventHandler->clear();
		self::assertSame(0, $eventHandler->called);

		self::assertSame(1, $context->getVariableValue('banner.border'));
		self::assertSame('large', $context->getVariableValue('banner.size'));
		self::assertSame(1, $eventHandler->called);
		self::assertSame('exp_test_ab', $eventHandler->events[0]->getData()->name);
		self::assertSame(ContextEventLoggerEvent::Exposure, $eventHandler->events[0]->getEvent());
	}

	public function testGetVariableKeys(): void {
		$context = $this->createReadyContext('refreshed.json');
		self::assertEquals($this->variableExperiments, $context->getVariableKeys());
	}

	public function testPeekTreatmentReturnsOverrideVariant(): void {
		$context = $this->createReadyContext();

		foreach ($context->getContextData()->experiments as $experiment) {
			$context->setOverride($experiment->name, $this->expectedVariants[$experiment->name] + 11);
		}

		$context->setOverride('not_found', 3);

		foreach ($context->getContextData()->experiments as $experiment) {
			if (isset($this->expectedVariants[$experiment->name])) {
				$this->assertSame(
					$this->expectedVariants[$experiment->name] + 11,
					$context->peekTreatment($experiment->name)
				);
			}
		}

		self::assertSame(3, $context->peekTreatment('not_found'));

		// Call again
		foreach ($context->getContextData()->experiments as $experiment) {
			if (isset($this->expectedVariants[$experiment->name])) {
				$this->assertSame(
					$this->expectedVariants[$experiment->name] + 11,
					$context->peekTreatment($experiment->name)
				);
			}
		}

		self::assertSame(3, $context->peekTreatment('not_found'));
		self::assertSame(0, $context->getPendingCount());
	}

	public function testPeekTreatmentReturnsAssignedVariantOnAudienceMismatchNonStrictMode(): void {
		$context = $this->createReadyContext('audience_context.json');
		self::assertSame(1, $context->peekTreatment('exp_test_ab'));
	}

	public function testPeekTreatmentReturnsControlVariantOnAudienceMismatchStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');
		self::assertSame(0, $context->peekTreatment('exp_test_ab'));
	}

	public function testGetTreatment(): void {
		$context = $this->createReadyContext();

		foreach ($context->getContextData()->experiments as $experiment) {
			self::assertSame($this->expectedVariants[$experiment->name], $context->getTreatment($experiment->name));
		}

		self::assertSame(0, $context->getTreatment("not_found"));

		self::assertSame(count($context->getContextData()->experiments) + 1, $context->getPendingCount());

		$context->publish();
		$context->close();

		$publishEvent = $this->eventHandler->submitted[0];
		$time = (int) (microtime(true) * 1000);
		$expected = [
			Exposure::create(1, "exp_test_ab", "session_id", 1, $time, true, true, false, false, false, false),
			Exposure::create(2, "exp_test_abc", "session_id", 2, $time, true, true, false, false, false, false),
			Exposure::create(3, "exp_test_not_eligible", "user_id", 0, $time, true, false, false, false, false, false),
			Exposure::create(4, "exp_test_fullon", "session_id", 2, $time, true, true, false, true, false,	false),
			Exposure::create(0, "not_found", null, 0, $time, false, true, false, false, false, false),
		];

		foreach ($expected as $key => $exposure) {
			$expectedExposure = (object)(array) $exposure;

			// Avoid test failures because precision is lost during json decode/encode operation.
			$publishEvent->exposures[$key]->exposedAt = (float) $time;

			self::assertEquals($expectedExposure, $publishEvent->exposures[$key]);
		}
	}

	public function testGetTreatmentStartsPublishTimeoutAfterExposure(): void {
		$context = $this->createReadyContext();
		$context->getTreatment('exp_test_ab');
		$context->getTreatment('exp_test_abc');

		self::assertSame(2, $context->getPendingCount());
	}


	public function testGetTreatmentReturnsOverrideVariant(): void {
		$context = $this->createReadyContext();

		foreach ($this->expectedVariants as $experimentName => $variant) {
			$context->setOverride($experimentName, $variant + 11);
		}

		$context->setOverride('not_found', 3);

		foreach ($context->getContextData()->experiments as $experiment) {
			if (isset($this->expectedVariants[$experiment->name])) {
				self::assertSame($this->expectedVariants[$experiment->name] + 11, $context->getTreatment($experiment->name));
			}
		}

		self::assertSame(3, $context->getTreatment('not_found'));
	}

	public function testGetTreatmentQueuesExposureOnce(): void {
		$context = $this->createReadyContext();
		$data = $context->getContextData();

		foreach ($data->experiments as $experiment) {
			self::assertSame($this->expectedVariants[$experiment->name], $context->getTreatment($experiment->name));
		}

		self::assertSame(0, $context->getTreatment("not_found"));
		self::assertSame(count($data->experiments) + 1, $context->getPendingCount());
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchFalseOnAudienceMatch(): void {
		$context = $this->createReadyContext('audience_context.json');
		$context->setAttribute('age', 21);

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('pAE3a1i5Drs5mKRNq56adA', $event->units[0]->uid);
		self::assertSame('age', $event->attributes[0]->name);
		self::assertSame(21, $event->attributes[0]->value);
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchTrueOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];

		self::assertSame('pAE3a1i5Drs5mKRNq56adA', $event->units[0]->uid);
		self::assertTrue($event->exposures[0]->audienceMismatch);
		self::assertTrue($event->hashed);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchTrueAndControlVariantOnAudienceMismatchInStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());
	}


	public function testGetTreatmentCallsEventLogger(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$logger->clear();

		$context->getTreatment('exp_test_ab');
		$context->getTreatment('not_found');

		self::assertSame(2, $logger->called);

		self::assertSame('exp_test_ab', $logger->events[0]->getData()->name);
		self::assertSame('not_found', $logger->events[1]->getData()->name);

		// verify not called again with the same exposure
		$context->getTreatment('exp_test_ab');
		$context->getTreatment('not_found');

		self::assertSame(2, $logger->called);
	}


	public function testTrack(): void {
		$context = $this->createReadyContext();

		$context->track('goal1', (object) ['amount' => 125, 'hours' => 245]);
		$context->track('goal2', (object) ['tries' => 7]);

		self::assertSame(2, $context->getPendingCount());

		$context->track('goal2', (object) ['tests' => 12]);
		$context->track('goal3');

		self::assertSame(4, $context->getPendingCount());

		$context->publish();

		$publishEvent = $this->eventHandler->submitted[0];
		self::assertSame('pAE3a1i5Drs5mKRNq56adA', $publishEvent->units[0]->uid);
		self::assertSame('goal1', $publishEvent->goals[0]->name);
		self::assertSame('goal2', $publishEvent->goals[1]->name);
		self::assertSame('goal2', $publishEvent->goals[2]->name);
		self::assertSame('goal3', $publishEvent->goals[3]->name);

		self::assertSame(12, $publishEvent->goals[2]->properties->tests);
		self::assertNull($publishEvent->goals[3]->properties);
	}

	public function testTrackCallsEventLogger(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$logger->clear();

		$context->track('goal1', (object) ['amount' => 125, 'hours' => 245]);
		$context->track('goal2', (object) ['tries' => 7]);

		self::assertSame(2, $logger->called);

		self::assertSame(ContextEventLoggerEvent::Goal, $logger->events[0]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Goal, $logger->events[1]->getEvent());

		self::assertInstanceOf(GoalAchievement::class, $logger->events[0]->getData());
		self::assertInstanceOf(GoalAchievement::class, $logger->events[1]->getData());

		self::assertSame('goal1', $logger->events[0]->getData()->name);
		self::assertSame(7, $logger->events[1]->getData()->properties->tries);
	}

	public function testPublishDoesNotCallEventHandlerWhenQueueIsEmpty(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$logger->clear();

		$context->publish();
		self::assertEmpty($logger->events);
	}

	public function testPublishCallsEventLogger(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$context->track('goal1', (object) ['amount' => 125, 'hours' => 245]);
		$logger->clear();

		$context->publish();
		self::assertSame(ContextEventLoggerEvent::Publish, $logger->events[0]->getEvent());
	}

	public function testPublishCallsEventLoggerOnError(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Trigger failure');
		};

		$context->track('goal_failure');

		$logger->clear();

		$context->publish();

		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
	}

	public function testPublishResetsInternalQueuesAndKeepsAttributesOverridesAndCustomAssignments(): void {
		$context = $this->createReadyContext();

		$context->setAttributes(
			[
				'attr1' => 'value1',
				'attr2' => 2,
			]
		);
		$context->setOverride('not_found', 3);
		$context->setCustomAssignment('exp_test_abc', 3);

		self::assertSame(0, $context->getPendingCount());

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(3, $context->getTreatment('exp_test_abc'));
		self::assertSame(3, $context->getTreatment('not_found'));

		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);

		self::assertSame(4, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame(2, $event->attributes[1]->value);
		self::assertSame(245, $event->goals[0]->properties->hours);
		self::assertSame('not_found', $event->exposures[2]->name);

		self::assertSame(0, $context->getPendingCount());

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(3, $context->getTreatment('exp_test_abc'));
		self::assertSame(3, $context->getTreatment('not_found'));

		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);

		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[1];
		self::assertSame(2, $event->attributes[1]->value);
		self::assertSame(245, $event->goals[0]->properties->hours);
		self::assertFalse(property_exists($event, 'exposures'), 'Second publish should not have exposures - they were already sent in first publish');

		self::assertSame(0, $context->getPendingCount());

	}

	public function testPublishDoesNotCallEventHandlerWhenFailed(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$eventHandler = new ContextEventHandlerMock($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Trigger failure');
		};
		$config->setContextDataProvider($dataProvider);

		$eventLogger = new MockContextEventLoggerProxy();

		$contextConfig = new ContextConfig();
		$contextConfig->setEventLogger($eventLogger);
		$contextConfig->setEventHandler($eventHandler);
		$context =  (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isReady());
		self::assertTrue($context->isFailed());
		self::assertSame(0, $context->getPendingCount());

		$context->getTreatment('exp_test_abc');
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);

		self::assertSame(2, $context->getPendingCount());

		$context->publish();

		self::assertEmpty($eventHandler->submitted);
	}

	public function testClose(): void {
		$context = $this->createReadyContext();
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);

		self::assertSame(1, $context->getPendingCount());

		$context->close();

		self::assertTrue($context->isClosed());
	}

	public function testCloseCallsEventLogger(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);
		$context->publish();
		$logger->clear();
		$context->close();

		self::assertSame(ContextEventLoggerEvent::Finalize, $logger->events[0]->getEvent());
	}

	public function testCloseCallsEventLoggerWithPendingEvents(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);
		$context->close();

		self::assertSame(ContextEventLoggerEvent::Ready, $logger->events[0]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Goal, $logger->events[1]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Publish, $logger->events[2]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Finalize, $logger->events[3]->getEvent());
	}

	public function testCloseCallsEventLoggerOnError(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Trigger failure');
		};

		$context->track('goal_failure');

		$logger->clear();

		$context->close();
		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
	}

	public function testRefresh(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$refreshedData = $this->getContextData('refreshed.json');

		$context->refresh();

		$experiments = [];
		foreach ($refreshedData->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}

		self::assertSame($experiments, $context->getExperiments());
	}

	public function testRefreshCallsEventLogger(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);
		$context->refresh();
		$context->close();

		self::assertSame(ContextEventLoggerEvent::Ready, $logger->events[0]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Goal, $logger->events[1]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Refresh, $logger->events[2]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Publish, $logger->events[3]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Finalize, $logger->events[4]->getEvent());
	}

	public function testRefreshCallsEventLoggerOnError(): void {
		$clientConfig = new ClientConfig('', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$eventHandler = new ContextEventHandlerMock($client);
		$dataProvider = new ContextDataProviderMock($client);
		$config->setContextDataProvider($dataProvider);

		$eventLogger = new MockContextEventLoggerProxy();

		$contextConfig = new ContextConfig();
		$contextConfig->setEventLogger($eventLogger);
		$contextConfig->setEventHandler($eventHandler);
		$context =  (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		self::assertSame(0, $context->getPendingCount());

		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Trigger failure');
		};

		$eventLogger->clear();

		$context->refresh();

		self::assertSame(ContextEventLoggerEvent::Error, $eventLogger->events[0]->getEvent());
	}

	public function testRefreshKeepsAssignmentCacheWhenNotChanged(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$oldContext = $context->getContextData();

		foreach($context->getContextData()->experiments as $experiment) {
			$context->getTreatment($experiment->name);
		}

		$context->getTreatment('not_found');

		self::assertSame(count($context->getContextData()->experiments) + 1, $context->getPendingCount());

		$refreshedData = $this->getContextData('refreshed.json');
		$context->refresh();

		$experiments = [];
		foreach ($refreshedData->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}

		self::assertSame($experiments, $context->getExperiments());

		foreach ($oldContext->experiments as $experiment) {
			$context->getTreatment($experiment->name);
		}
		$context->getTreatment('not_found');

		self::assertSame(count($oldContext->experiments) + 1, $context->getPendingCount());
	}

	public function testRefreshKeepsAssignmentCacheWhenNotChangedOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_strict_context.json');
		self::assertTrue($context->isReady());

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->refresh();

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testRefreshKeepsAssignmentCacheWhenNotChangedWithOverride(): void {
		$context = $this->createReadyContext('audience_strict_context.json');
		self::assertTrue($context->isReady());

		$context->setOverride('exp_test_ab', 3);
		self::assertSame(3, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->refresh();

		self::assertSame(3, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());
	}


	public function testRefreshClearsAssignmentCacheForStoppedExperiment(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_abc";

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$data = $this->getContextData('refreshed_no_exp_test_abc.json');
		$context->refresh();

		$experiments = [];
		foreach ($data->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}
		self::assertSame($experiments, $context->getExperiments());

		self::assertSame(0, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshClearsAssignmentCacheForStartedExperiment(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_new";

		self::assertSame(0, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$data = $this->getContextData('refreshed.json');
		$context->refresh();

		$experiments = [];
		foreach ($data->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}
		self::assertSame($experiments, $context->getExperiments());

		self::assertSame(1, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshClearsAssignmentCacheForFullOnExperiment(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_abc";

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$data = $this->getContextData('refreshed_full_on.json');
		$context->refresh();

		$experiments = [];
		foreach ($data->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}
		self::assertSame($experiments, $context->getExperiments());

		self::assertSame(1, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshClearsAssignmentCacheForTrafficSplitChange(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_not_eligible";

		self::assertSame(0, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$data = $this->getContextData('refreshed_traffic_split.json');
		$context->refresh();

		$experiments = [];
		foreach ($data->experiments as $experiment) {
			$experiments[] = $experiment->name;
		}
		self::assertSame($experiments, $context->getExperiments());

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshClearsAssignmentCacheForExperimentIdChange(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_abc";

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$this->getContextData('refreshed_id.json');
		$context->refresh();


		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	/*
	 * =============================================================================
	 *                      PHASE 1: FAILED STATE TESTING
	 * =============================================================================
	 */

	public function testFailedStateInitialization(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Connection failed during initialization');
		};
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$contextConfig = new ContextConfig();
		$contextConfig->setEventHandler($eventHandler);
		$context = (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isReady());
		self::assertTrue($context->isFailed());
	}

	public function testIsFailedReturnsTrue(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Server unavailable');
		};
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$contextConfig = new ContextConfig();
		$contextConfig->setEventHandler($eventHandler);
		$context = (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isFailed());
		self::assertFalse($context->isClosed());
	}

	public function testOperationsOnFailedContext(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Init failure');
		};
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$contextConfig = new ContextConfig();
		$contextConfig->setEventHandler($eventHandler);
		$contextConfig->setUnits($this->units);
		$context = (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isFailed());

		self::assertSame(0, $context->getTreatment('any_experiment'));
		self::assertSame(1, $context->getPendingCount());

		$context->track('goal1', (object) ['amount' => 100]);
		self::assertSame(2, $context->getPendingCount());

		$context->publish();
		self::assertEmpty($eventHandler->submitted);
		self::assertSame(0, $context->getPendingCount());
	}

	public function testRecoveryFromFailedState(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$callCount = 0;
		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() use (&$callCount) {
			$callCount++;
			if ($callCount === 1) {
				throw new \RuntimeException('First call fails');
			}
		};
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$contextConfig = new ContextConfig();
		$contextConfig->setEventHandler($eventHandler);
		$context = (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isFailed());
		self::assertSame(1, $callCount);

		$context->refresh();
		self::assertSame(2, $callCount);
	}

	/*
	 * =============================================================================
	 *                    PHASE 2: ATTRIBUTE MANAGEMENT
	 * =============================================================================
	 */

	public function testSetAttribute(): void {
		$context = $this->createReadyContext();

		$context->setAttribute('user_age', 25);
		self::assertSame(25, $context->getAttribute('user_age'));

		$context->setAttribute('country', 'US');
		self::assertSame('US', $context->getAttribute('country'));
	}

	public function testSetAttributes(): void {
		$context = $this->createReadyContext();

		$context->setAttributes([
			'tier' => 'premium',
			'score' => 100,
			'active' => true,
		]);

		self::assertSame('premium', $context->getAttribute('tier'));
		self::assertSame(100, $context->getAttribute('score'));
		self::assertTrue($context->getAttribute('active'));
	}

	public function testGetAttribute(): void {
		$context = $this->createReadyContext();

		self::assertNull($context->getAttribute('nonexistent'));

		$context->setAttribute('name', 'John');
		self::assertSame('John', $context->getAttribute('name'));

		$context->setAttribute('name', 'Jane');
		self::assertSame('Jane', $context->getAttribute('name'));
	}

	public function testAttributePersistenceAcrossPublish(): void {
		$context = $this->createReadyContext();

		$context->setAttribute('persistent_attr', 'value1');
		$context->getTreatment('exp_test_ab');

		$context->publish();

		self::assertSame('value1', $context->getAttribute('persistent_attr'));

		$context->track('goal1');
		$context->publish();

		self::assertSame('value1', $context->getAttribute('persistent_attr'));
		self::assertCount(2, $this->eventHandler->submitted);
	}

	public function testAttributeInPublishedEvent(): void {
		$context = $this->createReadyContext();

		$context->setAttribute('plan', 'enterprise');
		$context->setAttribute('seats', 50);
		$context->getTreatment('exp_test_ab');

		$context->publish();

		self::assertCount(1, $this->eventHandler->submitted);
		$event = $this->eventHandler->submitted[0];

		$attributeNames = array_map(fn($attr) => $attr->name, $event->attributes);
		self::assertContains('plan', $attributeNames);
		self::assertContains('seats', $attributeNames);
	}

	/*
	 * =============================================================================
	 *                       PHASE 4: ERROR HANDLING
	 * =============================================================================
	 */

	public function testInvalidExperimentName(): void {
		$context = $this->createReadyContext();

		self::assertSame(0, $context->getTreatment(''));
		self::assertSame(0, $context->getTreatment('nonexistent_experiment'));
		self::assertSame(0, $context->getTreatment('exp_with_special_chars!@#'));
	}

	public function testMalformedContextData(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 999);
		self::assertSame(999, $context->getTreatment('exp_test_ab'));

		$context->setCustomAssignment('exp_test_abc', -1);
		self::assertSame(-1, $context->getTreatment('exp_test_abc'));
	}

	public function testNetworkErrorRecovery(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Network timeout');
		};

		$context->track('goal1');
		$context->publish();

		self::assertTrue($context->isFailed());

		$errorEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error);
		self::assertNotEmpty($errorEvents);

		$lastError = array_values($errorEvents)[count($errorEvents) - 1];
		self::assertInstanceOf(\Throwable::class, $lastError->getData());
		self::assertStringContainsString('Network timeout', $lastError->getData()->getMessage());
	}

	public function testPartialResponseHandling(): void {
		$context = $this->createReadyContext();

		$experiments = $context->getExperiments();
		self::assertNotEmpty($experiments);

		foreach ($experiments as $experimentName) {
			$treatment = $context->getTreatment($experimentName);
			self::assertIsInt($treatment);
			self::assertGreaterThanOrEqual(0, $treatment);
		}

		self::assertSame(0, $context->getTreatment('missing_experiment'));
	}

	/*
	 * =============================================================================
	 *                   PHASE 5: EVENT HANDLER SCENARIOS
	 * =============================================================================
	 */

	public function testEventHandlerAllEventTypes(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$context->getTreatment('exp_test_ab');
		$context->track('goal1', (object) ['amount' => 100]);
		$context->publish();
		$context->refresh();
		$context->close();

		$eventTypes = array_map(fn($e) => $e->getEvent(), $logger->events);

		self::assertContains(ContextEventLoggerEvent::Ready, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Exposure, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Goal, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Publish, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Refresh, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Finalize, $eventTypes);
	}

	public function testEventHandlerErrorInCallback(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Handler error');
		};

		$context->track('goal1');
		$context->publish();

		$errorEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error);
		self::assertNotEmpty($errorEvents);

		$errorEvent = array_values($errorEvents)[0];
		self::assertInstanceOf(\Throwable::class, $errorEvent->getData());
	}

	public function testEventHandlerOrdering(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$logger->clear();

		$context->getTreatment('exp_test_ab');
		$context->track('goal1');
		$context->publish();
		$context->close();

		$events = $logger->events;
		$eventTypes = array_map(fn($e) => $e->getEvent(), $events);

		$exposureIndex = array_search(ContextEventLoggerEvent::Exposure, $eventTypes);
		$goalIndex = array_search(ContextEventLoggerEvent::Goal, $eventTypes);
		$publishIndex = array_search(ContextEventLoggerEvent::Publish, $eventTypes);
		$finalizeIndex = array_search(ContextEventLoggerEvent::Finalize, $eventTypes);

		self::assertLessThan($goalIndex, $exposureIndex);
		self::assertLessThan($publishIndex, $goalIndex);
		self::assertLessThan($finalizeIndex, $publishIndex);
	}

	public function testEventHandlerReceivesCorrectData(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$logger->clear();

		$context->getTreatment('exp_test_ab');
		$context->track('custom_goal', (object) ['value' => 42]);
		$context->publish();

		$exposureEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Exposure);
		$goalEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Goal);
		$publishEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Publish);

		self::assertCount(1, $exposureEvents);
		self::assertCount(1, $goalEvents);
		self::assertCount(1, $publishEvents);

		$exposure = array_values($exposureEvents)[0]->getData();
		self::assertInstanceOf(Exposure::class, $exposure);
		self::assertSame('exp_test_ab', $exposure->name);

		$goal = array_values($goalEvents)[0]->getData();
		self::assertInstanceOf(GoalAchievement::class, $goal);
		self::assertSame('custom_goal', $goal->name);
		self::assertSame(42, $goal->properties->value);

		$publish = array_values($publishEvents)[0]->getData();
		self::assertInstanceOf(PublishEvent::class, $publish);
	}

	/*
	 * =============================================================================
	 *                     PHASE 6: INTEGRATION SCENARIOS
	 * =============================================================================
	 */

	public function testFullLifecycle(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		self::assertFalse($context->isClosed());

		$experiments = $context->getExperiments();
		self::assertNotEmpty($experiments);

		$context->setAttribute('session_type', 'returning');

		$treatment = $context->getTreatment('exp_test_ab');
		self::assertIsInt($treatment);

		$context->track('page_view');
		$context->track('conversion', (object) ['revenue' => 99.99]);

		$context->publish();
		self::assertSame(0, $context->getPendingCount());

		$context->refresh();

		$context->close();
		self::assertTrue($context->isClosed());

		$eventTypes = array_map(fn($e) => $e->getEvent(), $logger->events);
		self::assertContains(ContextEventLoggerEvent::Ready, $eventTypes);
		self::assertContains(ContextEventLoggerEvent::Finalize, $eventTypes);
	}

	public function testMultipleExperiments(): void {
		$context = $this->createReadyContext();

		$experiments = $context->getExperiments();
		self::assertGreaterThan(1, count($experiments));

		$treatments = [];
		foreach ($experiments as $experimentName) {
			$treatments[$experimentName] = $context->getTreatment($experimentName);
		}

		self::assertSame(count($experiments), count($treatments));

		foreach ($treatments as $experimentName => $treatment) {
			self::assertIsInt($treatment);
			self::assertGreaterThanOrEqual(0, $treatment);
		}

		$context->publish();

		self::assertCount(1, $this->eventHandler->submitted);
		$event = $this->eventHandler->submitted[0];
		self::assertSame(count($experiments), count($event->exposures));
	}

	public function testAttributeUpdatesInTemplates(): void {
		$context = $this->createReadyContext('audience_context.json');

		$context->setAttribute('age', 15);
		$treatmentBefore = $context->getTreatment('exp_test_ab');

		$context->publish();
		$this->eventHandler->submitted = [];

		$context->setAttribute('age', 25);
		$treatmentAfter = $context->getTreatment('exp_test_ab');

		$context->publish();

		$events = $this->eventHandler->submitted;
		self::assertCount(1, $events);

		$attributeNames = array_map(fn($attr) => $attr->name, $events[0]->attributes);
		self::assertContains('age', $attributeNames);
	}

	public function testCrossFeatureInteraction(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 0);
		$context->setCustomAssignment('exp_test_abc', 1);

		$overriddenTreatment = $context->getTreatment('exp_test_ab');
		self::assertSame(0, $overriddenTreatment);

		$customTreatment = $context->getTreatment('exp_test_abc');
		self::assertSame(1, $customTreatment);

		$regularTreatment = $context->getTreatment('exp_test_fullon');
		self::assertSame($this->expectedVariants['exp_test_fullon'], $regularTreatment);

		$borderValue = $context->getVariableValue('banner.border', 0);
		self::assertSame(0, $borderValue);

		$buttonColor = $context->getVariableValue('button.color', 'default');
		self::assertSame('blue', $buttonColor);

		$context->track('combined_goal', (object) [
			'overridden' => $overriddenTreatment,
			'custom' => $customTreatment,
			'regular' => $regularTreatment,
		]);

		$context->publish();

		self::assertCount(1, $this->eventHandler->submitted);
		$event = $this->eventHandler->submitted[0];

		self::assertGreaterThanOrEqual(3, count($event->exposures));
		self::assertCount(1, $event->goals);
	}

	public function testCallsEventLoggerOnError(): void {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$dataProvider = new ContextDataProviderMock($client);
		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Connection failed');
		};
		$config->setContextDataProvider($dataProvider);

		$eventHandler = new ContextEventHandlerMock($client);
		$eventLogger = new MockContextEventLoggerProxy();

		$contextConfig = new ContextConfig();
		$contextConfig->setEventLogger($eventLogger);
		$contextConfig->setEventHandler($eventHandler);
		(new SDK($config))->createContext($contextConfig);

		self::assertSame(1, $eventLogger->called);
		self::assertSame(ContextEventLoggerEvent::Error, $eventLogger->events[0]->getEvent());
	}

	public function testCallsEventLoggerOnSuccess(): void {
		$eventLogger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $eventLogger);

		self::assertSame(1, $eventLogger->called);
		self::assertSame(ContextEventLoggerEvent::Ready, $eventLogger->events[0]->getEvent());
		self::assertInstanceOf(\ABSmartly\SDK\Context\ContextData::class, $eventLogger->events[0]->getData());
	}

	public function testShouldLoadExperimentData(): void {
		$context = $this->createReadyContext();

		$experiments = $context->getExperiments();
		self::assertContains('exp_test_ab', $experiments);
		self::assertContains('exp_test_abc', $experiments);
		self::assertContains('exp_test_not_eligible', $experiments);
		self::assertContains('exp_test_fullon', $experiments);
	}

	public function testSetUnitBeforeReady(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setUnit('session_id', 'test-session');
		self::assertSame('test-session', $contextConfig->getUnit('session_id'));
	}

	public function testSetUnitAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		$context->setUnit('new_unit', 'value');
		self::assertSame('value', $context->getUnit('new_unit'));
	}

	public function testSetAttributeBeforeReady(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setAttribute('attr1', 'value1');
		self::assertSame('value1', $contextConfig->getAttribute('attr1'));
	}

	public function testPeekTreatmentDoesNotQueueExposures(): void {
		$context = $this->createReadyContext();

		foreach ($context->getContextData()->experiments as $experiment) {
			$context->peekTreatment($experiment->name);
		}

		$context->peekTreatment('not_found');
		self::assertSame(0, $context->getPendingCount());
	}

	public function testTreatmentQueuesExposureAfterPeek(): void {
		$context = $this->createReadyContext();

		$context->peekTreatment('exp_test_ab');
		self::assertSame(0, $context->getPendingCount());

		$context->getTreatment('exp_test_ab');
		self::assertSame(1, $context->getPendingCount());
	}

	public function testTreatmentQueuesExposureOnlyOnce(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->getTreatment('exp_test_ab');
		$context->getTreatment('exp_test_ab');

		self::assertSame(1, $context->getPendingCount());
	}

	public function testTreatmentQueuesExposureWithBaseVariantOnUnknownExperiment(): void {
		$context = $this->createReadyContext();

		self::assertSame(0, $context->getTreatment('unknown_experiment'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('unknown_experiment', $event->exposures[0]->name);
		self::assertSame(0, $event->exposures[0]->variant);
		self::assertFalse($event->exposures[0]->assigned);
	}

	public function testTreatmentDoesNotReQueueExposureOnUnknownExperiment(): void {
		$context = $this->createReadyContext();

		self::assertSame(0, $context->getTreatment('unknown_experiment'));
		self::assertSame(0, $context->getTreatment('unknown_experiment'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testTreatmentQueuesExposureWithOverrideVariant(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 5);
		self::assertSame(5, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('exp_test_ab', $event->exposures[0]->name);
		self::assertSame(5, $event->exposures[0]->variant);
		self::assertTrue($event->exposures[0]->overridden);
	}

	public function testTreatmentQueuesExposureWithCustomAssignmentVariant(): void {
		$context = $this->createReadyContext();

		$context->setCustomAssignment('exp_test_ab', 2);
		self::assertSame(2, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('exp_test_ab', $event->exposures[0]->name);
		self::assertSame(2, $event->exposures[0]->variant);
		self::assertTrue($event->exposures[0]->custom);
	}

	public function testVariableValueDefaultWhenUnassigned(): void {
		$context = $this->createReadyContext();
		self::assertSame('default', $context->getVariableValue('nonexistent_variable', 'default'));
	}

	public function testVariableValueWhenOverridden(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 1);

		self::assertSame(1, $context->getVariableValue('banner.border', 0));
		self::assertSame('large', $context->getVariableValue('banner.size', 'small'));
	}

	public function testVariableValueQueuesExposureAfterPeekVariable(): void {
		$context = $this->createReadyContext();

		$context->peekVariableValue('banner.border', 0);
		self::assertSame(0, $context->getPendingCount());

		$context->getVariableValue('banner.border', 0);
		self::assertSame(1, $context->getPendingCount());
	}

	public function testVariableValueQueuesExposureOnlyOnce(): void {
		$context = $this->createReadyContext();

		$context->getVariableValue('banner.border', 0);
		$context->getVariableValue('banner.border', 0);
		$context->getVariableValue('banner.size', 'small');

		self::assertSame(1, $context->getPendingCount());
	}

	public function testVariableValueReturnsDefaultOnUnknownVariable(): void {
		$context = $this->createReadyContext();
		self::assertSame(42, $context->getVariableValue('completely_unknown_var', 42));
	}

	public function testVariableValueReturnsDefaultAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		self::assertSame(0, $context->getVariableValue('banner.border', 0));
		self::assertSame('default', $context->getVariableValue('nonexistent', 'default'));
	}

	public function testPeekVariableValueDefaultWhenUnassigned(): void {
		$context = $this->createReadyContext();
		self::assertSame('default', $context->peekVariableValue('nonexistent_variable', 'default'));
	}

	public function testPeekVariableValueWhenOverridden(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 1);

		self::assertSame(1, $context->peekVariableValue('banner.border', 0));
		self::assertSame('large', $context->peekVariableValue('banner.size', 'small'));
	}

	public function testPeekVariableValueDoesNotQueueExposure(): void {
		$context = $this->createReadyContext();

		$context->peekVariableValue('banner.border', 0);
		$context->peekVariableValue('banner.size', 'small');
		$context->peekVariableValue('button.color', 'blue');

		self::assertSame(0, $context->getPendingCount());
	}

	public function testTrackQueuesGoals(): void {
		$context = $this->createReadyContext();

		$context->track('goal1', (object) ['amount' => 125]);
		$context->track('goal2', (object) ['tries' => 7]);

		self::assertSame(2, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('goal1', $event->goals[0]->name);
		self::assertSame('goal2', $event->goals[1]->name);
		self::assertSame(125, $event->goals[0]->properties->amount);
		self::assertSame(7, $event->goals[1]->properties->tries);
	}

	public function testTrackDoesNotThrowWithNumberProperties(): void {
		$context = $this->createReadyContext();

		$context->track('goal1', (object) ['amount' => 125, 'hours' => 245.5]);
		self::assertSame(1, $context->getPendingCount());
	}

	public function testTrackAcceptsNullProperties(): void {
		$context = $this->createReadyContext();

		$context->track('goal1');
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertNull($event->goals[0]->properties);
	}

	public function testTrackCallableBeforeReady(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);
		$context = $this->createContext($contextConfig);

		$context->track('goal1', (object) ['amount' => 100]);
		self::assertSame(1, $context->getPendingCount());
	}

	public function testTrackThrowsAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		$this->expectException(\ABSmartly\SDK\Exception\LogicException::class);
		$context->track('goal1');
	}

	public function testTrackQueuesGoalsWithTimestamp(): void {
		$context = $this->createReadyContext();

		$timeBefore = (int) (microtime(true) * 1000);
		$context->track('goal1', (object) ['amount' => 100]);
		$timeAfter = (int) (microtime(true) * 1000);

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertGreaterThanOrEqual($timeBefore, $event->goals[0]->achievedAt);
		self::assertLessThanOrEqual($timeAfter, $event->goals[0]->achievedAt);
	}

	public function testPublishShouldNotCallClientPublishWhenQueueIsEmpty(): void {
		$context = $this->createReadyContext();
		$context->publish();
		self::assertEmpty($this->eventHandler->submitted);
	}

	public function testPublishShouldCallClientPublish(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->publish();

		self::assertCount(1, $this->eventHandler->submitted);
		self::assertNotEmpty($this->eventHandler->submitted[0]->exposures);
	}

	public function testPublishShouldIncludeExposureData(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('exp_test_ab', $event->exposures[0]->name);
		self::assertSame(1, $event->exposures[0]->variant);
	}

	public function testPublishShouldIncludeGoalData(): void {
		$context = $this->createReadyContext();

		$context->track('test_goal', (object) ['revenue' => 99]);
		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('test_goal', $event->goals[0]->name);
		self::assertSame(99, $event->goals[0]->properties->revenue);
	}

	public function testPublishShouldIncludeAttributeData(): void {
		$context = $this->createReadyContext();

		$context->setAttribute('user_type', 'premium');
		$context->getTreatment('exp_test_ab');
		$context->publish();

		$event = $this->eventHandler->submitted[0];
		$attributeNames = array_map(fn($attr) => $attr->name, $event->attributes);
		self::assertContains('user_type', $attributeNames);
	}

	public function testPublishShouldClearQueueOnSuccess(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		self::assertSame(1, $context->getPendingCount());

		$context->publish();
		self::assertSame(0, $context->getPendingCount());
	}

	public function testPublishShouldNotClearQueueOnFailure(): void {
		$context = $this->createReadyContext();
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Publish failed');
		};

		$context->track('goal1');
		$context->publish();

		self::assertTrue($context->isFailed());
	}

	public function testPublishThrowsAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		$this->expectException(\ABSmartly\SDK\Exception\LogicException::class);
		$context->publish();
	}

	public function testFinalizeShouldNotCallClientPublishWhenQueueIsEmpty(): void {
		$context = $this->createReadyContext();
		$context->close();

		self::assertEmpty($this->eventHandler->submitted);
		self::assertTrue($context->isClosed());
	}

	public function testFinalizeShouldCallClientPublish(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->close();

		self::assertCount(1, $this->eventHandler->submitted);
	}

	public function testFinalizeShouldIncludeExposureData(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->close();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('exp_test_ab', $event->exposures[0]->name);
	}

	public function testFinalizeShouldIncludeGoalData(): void {
		$context = $this->createReadyContext();

		$context->track('goal1', (object) ['value' => 50]);
		$context->close();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('goal1', $event->goals[0]->name);
	}

	public function testFinalizeShouldIncludeAttributeData(): void {
		$context = $this->createReadyContext();

		$context->setAttribute('plan', 'pro');
		$context->getTreatment('exp_test_ab');
		$context->close();

		$event = $this->eventHandler->submitted[0];
		$attributeNames = array_map(fn($attr) => $attr->name, $event->attributes);
		self::assertContains('plan', $attributeNames);
	}

	public function testFinalizeShouldClearQueueOnSuccess(): void {
		$context = $this->createReadyContext();

		$context->getTreatment('exp_test_ab');
		$context->close();

		self::assertTrue($context->isClosed());
	}

	public function testOverrideCallableBeforeReady(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setOverride('exp_test', 5);
		self::assertSame(5, $contextConfig->getOverride('exp_test'));
	}

	public function testCustomAssignmentOverridesNaturalAssignment(): void {
		$context = $this->createReadyContext();

		self::assertSame($this->expectedVariants['exp_test_ab'], $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->setCustomAssignment('exp_test_ab', 2);
		self::assertSame(2, $context->getTreatment('exp_test_ab'));
		self::assertSame(2, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		$lastExposure = $event->exposures[count($event->exposures) - 1];
		self::assertSame('exp_test_ab', $lastExposure->name);
		self::assertSame(2, $lastExposure->variant);
		self::assertTrue($lastExposure->custom);
	}

	public function testCustomAssignmentCallableBeforeReady(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setCustomAssignment('exp_test', 3);
		self::assertSame(3, $contextConfig->getCustomAssignment('exp_test'));
	}

	public function testCustomAssignmentAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		$context->setCustomAssignment('exp_test', 1);
		self::assertSame(1, $context->getCustomAssignment('exp_test'));
	}

	public function testRefreshKeepsOverrides(): void {
		$context = $this->createReadyContext();

		$context->setOverride('exp_test_ab', 5);
		self::assertSame(5, $context->getTreatment('exp_test_ab'));

		$this->getContextData('refreshed.json');
		$context->refresh();

		self::assertSame(5, $context->getTreatment('exp_test_ab'));
	}

	public function testRefreshKeepsCustomAssignments(): void {
		$context = $this->createReadyContext();

		$context->setCustomAssignment('exp_test_ab', 2);
		self::assertSame(2, $context->getTreatment('exp_test_ab'));

		$this->getContextData('refreshed.json');
		$context->refresh();

		self::assertSame(2, $context->getTreatment('exp_test_ab'));
	}

	public function testRefreshClearsAssignmentCacheForIterationChange(): void {
		$context = $this->createReadyContext();
		self::assertTrue($context->isReady());

		$experimentName = "exp_test_abc";

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));
		self::assertSame(2, $context->getPendingCount());

		$this->getContextData('refreshed_iteration.json');
		$context->refresh();

		self::assertSame(2, $context->getTreatment($experimentName));
		self::assertSame(0, $context->getTreatment('not_found'));

		self::assertSame(3, $context->getPendingCount());
	}

	public function testRefreshThrowsAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		$this->expectException(\ABSmartly\SDK\Exception\LogicException::class);
		$context->refresh();
	}

	public function testTreatmentReturnsZeroAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
	}

	public function testPeekTreatmentReturnsZeroAfterFinalized(): void {
		$context = $this->createReadyContext();
		$context->close();

		self::assertSame(0, $context->peekTreatment('exp_test_ab'));
	}

	public function testCustomFieldKeysReturnsKeys(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$experiment = $context->getExperiment('exp_test_ab');
		self::assertNotNull($experiment);
		self::assertNotNull($experiment->data->customFieldValues);
	}

	public function testCustomFieldValueReturnsStringField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'country');
		self::assertSame('US,UK,ES', $value);
	}

	public function testCustomFieldValueReturnsTextField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'description');
		self::assertSame('Test experiment for AB testing', $value);
	}

	public function testCustomFieldValueReturnsParsedJsonField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'config');
		self::assertIsArray($value);
		self::assertSame('red', $value['color']);
		self::assertSame(10, $value['size']);
	}

	public function testCustomFieldValueReturnsNumberField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'min_age');
		self::assertSame(18, $value);
	}

	public function testCustomFieldValueReturnsDecimalNumberField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'decimal_val');
		self::assertSame(3.14, $value);
	}

	public function testCustomFieldValueReturnsBooleanField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'enabled');
		self::assertTrue($value);
	}

	public function testCustomFieldValueReturnsNullForNonExistentField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'nonexistent_field');
		self::assertNull($value);
	}

	public function testCustomFieldValueReturnsNullForExperimentsWithoutCustomFields(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_no_custom_fields', 'any_field');
		self::assertNull($value);
	}

	public function testCustomFieldValueReturnsNullForNonExistentExperiment(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('nonexistent_experiment', 'any_field');
		self::assertNull($value);
	}

	public function testEventLoggerCalledOnceOnReady(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$readyEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Ready);
		self::assertCount(1, $readyEvents);
	}

	public function testCustomFieldValueBooleanPrefixType(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'is_active');
		self::assertFalse($value);
	}

	public function testCustomFieldValueBooleanFalseWithZero(): void {
		$context = $this->createReadyContext('context_custom_fields.json');

		$value = $context->customFieldValue('exp_test_ab', 'disabled');
		self::assertFalse($value);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMatchTrueOnAudienceMatch(): void {
		$context = $this->createReadyContext('audience_context.json');
		$context->setAttribute('age', 21);

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMatchFalseOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMatchFalseAndControlVariantOnAudienceMismatchStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame(0, $event->exposures[0]->variant);
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	public function testVariableValueQueuesExposureWithAudienceMatchTrueOnMatch(): void {
		$context = $this->createReadyContext('audience_context.json');
		$context->setAttribute('age', 21);

		self::assertSame('large', $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	public function testVariableValueQueuesExposureWithAudienceMatchFalseOnMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame('large', $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	public function testVariableValueQueuesExposureWithAudienceMatchFalseAndControlOnMismatchStrictMode(): void {
		$context = $this->createReadyContext('audience_strict_context.json');

		self::assertSame('small', $context->getVariableValue('banner.size', 'small'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	public function testPeekVariableValueReturnsAssignedOnAudienceMismatchNonStrict(): void {
		$context = $this->createReadyContext('audience_context.json');
		self::assertSame('large', $context->peekVariableValue('banner.size', 'small'));
	}

	public function testPeekVariableValueReturnsDefaultOnAudienceMismatchStrict(): void {
		$context = $this->createReadyContext('audience_strict_context.json');
		self::assertSame('small', $context->peekVariableValue('banner.size', 'small'));
	}

	public function testFinalizeCallsEventLoggerOnError(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Finalize failure');
		};

		$context->track('goal1');
		$logger->clear();
		$context->close();

		self::assertSame(ContextEventLoggerEvent::Error, $logger->events[0]->getEvent());
	}

	public function testFinalizeCallsEventLoggerOnSuccess(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$logger->clear();

		$context->close();

		self::assertSame(1, $logger->called);
		self::assertSame(ContextEventLoggerEvent::Finalize, $logger->events[0]->getEvent());
	}

	public function testFinalizePropagatesClientErrorMessage(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Server unavailable');
		};

		$context->track('goal1');
		$logger->clear();
		$context->close();

		$errorEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error);
		self::assertNotEmpty($errorEvents);
		$errorEvent = array_values($errorEvents)[0];
		self::assertStringContainsString('Server unavailable', $errorEvent->getData()->getMessage());
	}

	public function testPublishPropagatesClientErrorMessage(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$this->eventHandler->prerun = static function() {
			throw new \RuntimeException('Connection refused');
		};

		$context->track('goal1');
		$logger->clear();
		$context->publish();

		$errorEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error);
		self::assertNotEmpty($errorEvents);
		$errorEvent = array_values($errorEvents)[0];
		self::assertStringContainsString('Connection refused', $errorEvent->getData()->getMessage());
	}

	public function testRefreshShouldRejectOnError(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);

		$this->dataProvider->prerun = static function() {
			throw new \RuntimeException('Refresh failed');
		};

		$logger->clear();
		$context->refresh();

		self::assertTrue($context->isFailed());
		$errorEvents = array_filter($logger->events, fn($e) => $e->getEvent() === ContextEventLoggerEvent::Error);
		self::assertNotEmpty($errorEvents);
	}

	public function testRefreshShouldNotCallClientPublishWhenFailed(): void {
		$clientConfig = new ClientConfig('', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$eventHandler = new ContextEventHandlerMock($client);
		$dataProvider = new ContextDataProviderMock($client);
		$config->setContextDataProvider($dataProvider);

		$contextConfig = new ContextConfig();
		$contextConfig->setEventHandler($eventHandler);
		$context = (new SDK($config))->createContext($contextConfig);

		self::assertTrue($context->isReady());

		$dataProvider->prerun = static function() {
			throw new \RuntimeException('Refresh error');
		};

		$context->refresh();

		self::assertTrue($context->isFailed());
	}

	public function testClosedContextRejectsWriteOperations(): void {
		$context = $this->createReadyContext();
		$context->close();

		self::assertTrue($context->isClosed());

		self::assertSame(0, $context->getTreatment('exp_test_ab'));
		self::assertSame(0, $context->peekTreatment('exp_test_ab'));

		$thrownForTrack = false;
		try {
			$context->track('goal1');
		} catch (\ABSmartly\SDK\Exception\LogicException $e) {
			$thrownForTrack = true;
		}
		self::assertTrue($thrownForTrack);

		$thrownForPublish = false;
		try {
			$context->publish();
		} catch (\ABSmartly\SDK\Exception\LogicException $e) {
			$thrownForPublish = true;
		}
		self::assertTrue($thrownForPublish);

		$thrownForRefresh = false;
		try {
			$context->refresh();
		} catch (\ABSmartly\SDK\Exception\LogicException $e) {
			$thrownForRefresh = true;
		}
		self::assertTrue($thrownForRefresh);
	}

	public function testReadyErrorReturnsNullOnSuccess(): void {
		$context = $this->createReadyContext();
		self::assertNull($context->readyError());
	}

	public function testIsFinalizingReturnsFalseWhenNotClosing(): void {
		$context = $this->createReadyContext();
		self::assertFalse($context->isFinalizing());
	}

	public function testIsFinalizingReturnsFalseAfterClose(): void {
		$context = $this->createReadyContext();
		$context->close();
		self::assertFalse($context->isFinalizing());
		self::assertTrue($context->isClosed());
	}

	public function testGetCustomFieldKeysReturnsAllKeys(): void {
		$context = $this->createReadyContext('context_custom_fields.json');
		$keys = $context->getCustomFieldKeys();
		self::assertIsArray($keys);
		self::assertContains('country', $keys);
		self::assertContains('description', $keys);
		self::assertContains('enabled', $keys);
		self::assertContains('config', $keys);
		self::assertContains('min_age', $keys);
	}

	public function testGetCustomFieldKeysDoesNotIncludeTypeKeys(): void {
		$context = $this->createReadyContext('context_custom_fields.json');
		$keys = $context->getCustomFieldKeys();
		foreach ($keys as $key) {
			self::assertStringNotContainsString('_type', $key);
		}
	}

	public function testGetCustomFieldKeysReturnsEmptyArrayWithNoCustomFields(): void {
		$context = $this->createReadyContext();
		$keys = $context->getCustomFieldKeys();
		self::assertIsArray($keys);
		self::assertEmpty($keys);
	}

	public function testGetCustomFieldValueTypeReturnsType(): void {
		$context = $this->createReadyContext('context_custom_fields.json');
		self::assertSame('string', $context->getCustomFieldValueType('exp_test_ab', 'country'));
		self::assertSame('text', $context->getCustomFieldValueType('exp_test_ab', 'description'));
		self::assertSame('number', $context->getCustomFieldValueType('exp_test_ab', 'min_age'));
		self::assertSame('boolean', $context->getCustomFieldValueType('exp_test_ab', 'enabled'));
		self::assertSame('json', $context->getCustomFieldValueType('exp_test_ab', 'config'));
	}

	public function testGetCustomFieldValueTypeReturnsNullForUnknownField(): void {
		$context = $this->createReadyContext('context_custom_fields.json');
		self::assertNull($context->getCustomFieldValueType('exp_test_ab', 'nonexistent_field'));
	}

	public function testGetCustomFieldValueTypeReturnsNullForUnknownExperiment(): void {
		$context = $this->createReadyContext('context_custom_fields.json');
		self::assertNull($context->getCustomFieldValueType('nonexistent_experiment', 'country'));
	}

	public function testGetUnitReturnsUidForKnownUnitType(): void {
		$context = $this->createReadyContext();
		self::assertSame('e791e240fcd3df7d238cfc285f475e8152fcc0ec', $context->getUnit('session_id'));
		self::assertSame('123456789', $context->getUnit('user_id'));
	}

	public function testGetUnitReturnsNullForUnknownUnitType(): void {
		$context = $this->createReadyContext();
		self::assertNull($context->getUnit('nonexistent_unit'));
	}
}
