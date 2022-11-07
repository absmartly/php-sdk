<?php

namespace Absmartly\SDK\Tests\Context;

use Absmartly\SDK\Client\Client;
use Absmartly\SDK\Client\ClientConfig;
use Absmartly\SDK\Config;
use Absmartly\SDK\Context\Context;
use Absmartly\SDK\Context\ContextConfig;
use Absmartly\SDK\Context\ContextData;
use Absmartly\SDK\Context\ContextDataProvider;
use Absmartly\SDK\Context\ContextEventHandler;
use Absmartly\SDK\Context\ContextEventLogger;
use Absmartly\SDK\Context\ContextEventLoggerEvent;
use Absmartly\SDK\GoalAchievement;
use Absmartly\SDK\SDK;
use Absmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use Absmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use Absmartly\SDK\Tests\Mocks\MockContextEventLoggerProxy;
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
	public function testSetUnitEmpty(): void {;
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
		self::assertSame('21', $this->eventHandler->submitted[0]->attributes->age);
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
		self::assertSame('e791e240fcd3df7d238cfc285f475e8152fcc0ec', $event->units->session_id);
		self::assertSame('21', $event->attributes->age);
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchTrueOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		$context->publish();

		$event = $this->eventHandler->submitted[0];
		self::assertSame('e791e240fcd3df7d238cfc285f475e8152fcc0ec', $event->units->session_id);
		self::assertTrue($event->exposures[0]->audienceMismatch);
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
		self::assertSame('e791e240fcd3df7d238cfc285f475e8152fcc0ec', $publishEvent->units->session_id);
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

		self::assertSame('2', $event->attributes->attr2);
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
		self::assertSame('2', $event->attributes->attr2);
		self::assertSame(245, $event->goals[0]->properties->hours);
		self::assertSame('not_found', $event->exposures[2]->name);

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

		self::assertSame(ContextEventLoggerEvent::Close, $logger->events[0]->getEvent());
	}

	public function testCloseCallsEventLoggerWithPendingEvents(): void {
		$logger = new MockContextEventLoggerProxy();
		$context = $this->createReadyContext('context.json', true, $logger);
		$context->track('goal1', (object) ["amount" => 125, "hours" => 245]);
		$context->close();

		self::assertSame(ContextEventLoggerEvent::Ready, $logger->events[0]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Goal, $logger->events[1]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Publish, $logger->events[2]->getEvent());
		self::assertSame(ContextEventLoggerEvent::Close, $logger->events[3]->getEvent());
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
		self::assertSame(ContextEventLoggerEvent::Close, $logger->events[4]->getEvent());
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
}
