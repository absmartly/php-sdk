<?php

namespace Absmartly\SDK\Tests\Context;

use Absmartly\SDK\AudienceMatcher;
use Absmartly\SDK\Client\Client;
use Absmartly\SDK\Client\ClientConfig;
use Absmartly\SDK\Config;
use Absmartly\SDK\Context\Context;
use Absmartly\SDK\Context\ContextConfig;
use Absmartly\SDK\Context\ContextData;
use Absmartly\SDK\Context\ContextDataProvider;
use Absmartly\SDK\Context\ContextEventHandler;
use Absmartly\SDK\Context\ContextEventLogger;
use Absmartly\SDK\SDK;
use Absmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use Absmartly\SDK\VariableParser;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase {

	protected array $units = [
		"session_id" => "e791e240fcd3df7d238cfc285f475e8152fcc0ec",
		"user_id" => "123456789",
		"email" => "bleh@absmartly.com",
	];

	protected array $attributes = [
		"attr1" => "value1",
		"attr2" => "value2",
		"attr3" => 5,
	];

	protected array $expectedVariants = [
		"exp_test_ab" => 1,
		"exp_test_abc" => 2,
		"exp_test_not_eligible" => 0,
		"exp_test_fullon" => 2,
		//"exp_test_new" => 1, // TODO: Fix this with refreshData
	];

	protected array $expectedVariables = [
		"banner.border" => 1,
		"banner.size" => "large",
		"button.color" => "red",
		"submit.color" => "blue",
		"submit.shape" => "rect",
		"show-modal" => true,
	];

	protected array $variableExperiments = [
		"banner.border" => "exp_test_ab",
		"banner.size" => "exp_test_ab",
		"button.color" => "exp_test_abc",
		"card.width" => "exp_test_not_eligible",
		"submit.color" => "exp_test_fullon",
		"submit.shape" => "exp_test_fullon",
		"show-modal" =>"exp_test_new"
	];

	/*
	 *
	 * final Unit[] publishUnits = new Unit[]{
			new Unit("user_id", "JfnnlDI7RTiF9RgfG2JNCw"),
			new Unit("session_id", "pAE3a1i5Drs5mKRNq56adA"),
			new Unit("email", "IuqYkNRfEx5yClel4j3NbA")
	};
	 */


	protected ContextData $data;
	protected ContextData $refreshData;
	protected ContextData $audienceData;
	protected ContextData $audienceStrictData;

	protected ContextDataProvider $dataProvider;
	protected ContextEventLogger $eventLogger;
	protected ContextEventHandler $eventHandler;
	protected VariableParser $variableParser;
	protected AudienceMatcher $audienceMatcher;
	protected ScheduledExecutorService $scheduler;

	// DefaultContextDataDeserializer deser = new DefaultContextDataDeserializer();
	// Clock clock = Clock.fixed(1_620_000_000_000L);


	protected function createContext(ContextConfig $contextConfig): Context {
		$clientConfig = new ClientConfig('', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$provider = new ContextDataProviderMock($client);
		$config->setContextDataProvider($provider);

		return (new SDK($config))->createContext($contextConfig);
	}

	public function createReadyContext(string $source = 'context.json', bool $setUnits = true): Context {
		$clientConfig = new ClientConfig('', '', '', '');
		$client = new Client($clientConfig);
		$config = new Config($client);

		$provider = new ContextDataProviderMock($client);
		$provider->setSource($source);
		$config->setContextDataProvider($provider);



		$contextConfig = new ContextConfig();
		if ($setUnits) {
			$contextConfig->setUnits($this->units);
		}

		return (new SDK($config))->createContext($contextConfig);
	}

	private function getExperimentsList(Context $context): array {
		$experimentObjects = $context->getData()->experiments;
		$experiments = [];
		foreach ($experimentObjects as $experiment) {
			$experiments[] = $experiment->name;
		}

		return $experiments;
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

	// TODO: testBecomesReadyWithFulfilledPromise
	// TODO: testBecomesReadyAndFailedWithFulfilledErrorPromise
	// TODO: testBecomesReadyAndFailedWithErrorPromise
	// TODO: testCallsEventLoggerWhenReady
	// TODO: testCallsEventLoggerWithFulfilledPromise
	// TODO: testCallsEventLoggerWithErrorPromise
	// TODO: testCallsEventLoggerWithFulfilledErrorPromise
	// TODO: testWaitUntilReady
	// TODO: testWaitUntilReadyWithFulfilledPromise

	public function testGetExperiments(): void {
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);
		$context = $this->createContext($contextConfig);

		$experiments = $context->getExperiments();
		self::assertEquals(array_keys($context->getData()->experiments), $experiments);
	}

	// TODO testStartsRefreshTimerWhenReady
	// TODO testDoesNotStartRefreshTimerWhenFailed
	// TODO testStartsPublishTimeoutWhenReadyWithQueueNotEmpty

	public function testSetUnit(): void {
		$config = new ContextConfig();
		$config->setUnit('session_id', '0ab1e23f4eee');
		self::assertSame('0ab1e23f4eee', $config->getUnit('session_id'));
	}

	// TODO testSetUnitsBeforeReady
	// TODO testSetAttributesBeforeReady

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

		// TODO Rest
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

	// TODO: setOverridesBeforeReady

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

		// TODO: Rest
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

		//self::assertSame(1, $context->peekTreatment('exp_test_new'));

		foreach ($this->expectedVariants as $experimentName => $variant) {
			self::assertSame($variant, $context->peekTreatment($experimentName), sprintf('Assert experiment %s variant value is %s', $experimentName, $variant));
		}

		self::assertSame(0, $context->peekTreatment('not_found'));

		// TODO: Call again and see the pendingCount does not change.
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

		self::assertSame(count($context->getData()->experiments), $context->getPendingCount());
	}

	// TODO testGetVariableValueQueuesExposureWithAudienceMismatchFalseOnAudienceMatch
	// TODO testGetVariableValueQueuesExposureWithAudienceMismatchTrueOnAudienceMismatch
	// TODO testGetVariableValueQueuesExposureWithAudienceMismatchFalseAndControlVariantOnAudienceMismatchInStrictMode
	// TODO testGetVariableValueCallsEventLogger

	public function testGetVariableKeys(): void {
		$context = $this->createReadyContext('refreshed.json');
		self::assertEquals($this->variableExperiments, $context->getVariableKeys());
	}

	public function testPeekTreatmentReturnsOverrideVariant(): void {
		$context = $this->createReadyContext();

		foreach ($context->getData()->experiments as $experiment) {
			$context->setOverride($experiment->name, $this->expectedVariants[$experiment->name] + 11);
		}

		$context->setOverride('not_found', 3);

		foreach ($context->getData()->experiments as $experiment) {
			if (isset($this->expectedVariants[$experiment->name])) {
				$this->assertSame(
					$this->expectedVariants[$experiment->name] + 11,
					$context->peekTreatment($experiment->name)
				);
			}
		}

		self::assertSame(3, $context->peekTreatment('not_found'));

		// Call again
		foreach ($context->getData()->experiments as $experiment) {
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

		foreach ($context->getData()->experiments as $experiment) {
			self::assertSame($this->expectedVariants[$experiment->name], $context->getTreatment($experiment->name));
		}

		self::assertSame(0, $context->getTreatment("not_found"));

		self::assertSame(count($context->getData()->experiments) + 1, $context->getPendingCount()); // TODO

		// TODO: Rest
	}

	public function testGetTreatmentStartsPublishTimeoutAfterExposure(): void {
		$context = $this->createReadyContext();
		$context->getTreatment('exp_test_ab');
		$context->getTreatment('exp_test_abc');

		self::assertSame(2, $context->getPendingCount());

		// TODO: Rest
	}


	public function testGetTreatmentReturnsOverrideVariant(): void {
		$context = $this->createReadyContext();

		foreach ($this->expectedVariants as $experimentName => $variant) {
			$context->setOverride($experimentName, $variant + 11);
		}

		$context->setOverride('not_found', 3);

		foreach ($context->getData()->experiments as $experiment) {
			if (isset($this->expectedVariants[$experiment->name])) {
				self::assertSame($this->expectedVariants[$experiment->name] + 11, $context->getTreatment($experiment->name));
			}
		}

		self::assertSame(3, $context->getTreatment('not_found'));

		// TODO: Rest
	}

	public function testGetTreatmentQueuesExposureOnce(): void {
		$context = $this->createReadyContext();
		$data = $context->getData();

		foreach ($data->experiments as $experiment) {
			self::assertSame($this->expectedVariants[$experiment->name], $context->getTreatment($experiment->name));
		}

		self::assertSame(0, $context->getTreatment("not_found"));
		self::assertSame(count($data->experiments) + 1, $context->getPendingCount());

		// TODO: Rest
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchFalseOnAudienceMatch(): void {
		$context = $this->createReadyContext('audience_context.json');
		$context->setAttribute('age', 21);

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		// TODO: Rest
	}

	public function testGetTreatmentQueuesExposureWithAudienceMismatchTrueOnAudienceMismatch(): void {
		$context = $this->createReadyContext('audience_context.json');

		self::assertSame(1, $context->getTreatment('exp_test_ab'));
		self::assertSame(1, $context->getPendingCount());

		// TODO: Rest
	}

	// TODO: testGetTreatmentQueuesExposureWithAudienceMismatchTrueAndControlVariantOnAudienceMismatchInStrictMode
	// TODO: testGetTreatmentCallsEventLogger
	// TODO: testTrack
	// TODO: testTrackCallsEventLogger
	// TODO: testTrackStartsPublishTimeoutAfterAchievement
	// TODO: testTrackQueuesWhenNotReady
	// TODO: testPublishDoesNotCallEventHandlerWhenQueueIsEmpty
	// TODO: testPublishCallsEventLogger
	// TODO: testPublishCallsEventLoggerOnError
	// TODO: testPublishResetsInternalQueuesAndKeepsAttributesOverridesAndCustomAssignments
	// TODO: testPublishDoesNotCallEventHandlerWhenFailed
	// TODO: testPublishExceptionally
	// TODO: testClose
	// TODO: testCloseCallsEventLogger
	// TODO: testCloseCallsEventLoggerWithPendingEvents
	// TODO: testCloseCallsEventLoggerOnError
	// TODO: testCloseExceptionally
	// TODO: testCloseStopsRefreshTimer
	// TODO: testRefresh
	// TODO: testRefreshCallsEventLogger
	// TODO: testRefreshCallsEventLoggerOnError
	// TODO: testRefreshExceptionally
	// TODO: testRefreshKeepsAssignmentCacheWhenNotChanged
	// TODO: testRefreshKeepsAssignmentCacheWhenNotChangedOnAudienceMismatch
	// TODO: testRefreshKeepsAssignmentCacheWhenNotChangedWithOverride
	// TODO: testRefreshClearsAssignmentCacheForStoppedExperiment
	// TODO: testRefreshClearsAssignmentCacheForStartedExperiment
	// TODO: testRefreshClearsAssignmentCacheForFullOnExperiment
	// TODO: testRefreshClearsAssignmentCacheForTrafficSplitChange
	// TODO: testRefreshClearsAssignmentCacheForExperimentIdChange


	// TODO:  setCustomAssignmentsBeforeReady


}
