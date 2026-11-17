<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Experiment;
use ABSmartly\SDK\Http\AsyncHttpClientInterface;
use ABSmartly\SDK\Http\HttpClientInterface;
use ABSmartly\SDK\Http\Response;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use ABSmartly\SDK\Tests\Mocks\HoldoutDataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Holdouts as they arrive from the collector: decoding, forward compatibility, malformed entries, and the shared
 * cross-sdk-tests cases H1-H8 that the java-sdk suite doesn't cover.
 */
class ContextHoldoutPayloadTest extends TestCase {
	private const UID = 'e791e240fcd3df7d238cfc285f475e8152fcc0ec';

	private ContextEventHandlerMock $eventHandler;

	private static function fixture(string $name): object {
		return json_decode(file_get_contents(__DIR__ . '/../Fixtures/json/' . $name), false, 512, JSON_THROW_ON_ERROR);
	}

	private static function httpClient(string $content): HttpClientInterface {
		return new class($content) implements HttpClientInterface {
			private string $content;

			public function __construct(string $content) {
				$this->content = $content;
			}

			public function get(string $url, array $query = [], array $headers = []): Response {
				$response = new Response();
				$response->status = 200;
				$response->content = $this->content;
				return $response;
			}

			public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				$response = new Response();
				$response->status = 200;
				$response->content = '';
				return $response;
			}

			public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				return $this->put($url, $query, $headers, $body);
			}

			public function close(): void {
			}
		};
	}

	private function createContext(ContextData $data, array $units = ['session_id' => self::UID]): Context {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($units);

		return (new SDK($config))->createContextWithData($contextConfig, $data);
	}

	/** @return array<int, array{int, string, string, int}> [id, name, unit, variant] of each published exposure */
	private function published(Context $context): array {
		$context->publish();
		$exposures = [];
		foreach ($this->eventHandler->submitted as $event) {
			foreach ($event->exposures ?? [] as $exposure) {
				$exposures[] = [$exposure->id, $exposure->name, $exposure->unit, $exposure->variant];
			}
		}
		return $exposures;
	}

	private static function experiment(int $id, string $name, array $holdoutIds, string $unitType = 'session_id'): object {
		return (object) [
			'id' => $id, 'name' => $name, 'unitType' => $unitType, 'iteration' => 1, 'seedHi' => 100, 'seedLo' => 200,
			'split' => [0.5, 0.5], 'trafficSeedHi' => 1, 'trafficSeedLo' => 2, 'trafficSplit' => [0, 1], 'fullOnVariant' => 0,
			'applications' => [(object) ['name' => 'website']],
			'variants' => [(object) ['name' => 'A', 'config' => null], (object) ['name' => 'B', 'config' => null]],
			'audience' => null, 'audienceStrict' => false, 'holdoutIds' => $holdoutIds,
		];
	}

	private static function holdout(int $id, string $name, int $seedHi, int $seedLo, array $split = [0.1, 0.9]): object {
		return (object) ['id' => $id, 'name' => $name, 'unitType' => 'session_id', 'iteration' => 1, 'seedHi' => $seedHi,
			'seedLo' => $seedLo, 'split' => $split];
	}

	public function testTheJavaFixtureHoldsTheUnitOutOfTheCoveredExperiment(): void {
		$data = self::fixture('holdouts_context.json');
		$context = $this->createContext(new ContextData($data->experiments, $data->holdouts));

		self::assertSame(0, $context->getTreatment('exp_test_holdout'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	public function testClientDecodesHoldoutsOnFetch(): void {
		$json = file_get_contents(__DIR__ . '/../Fixtures/json/holdouts_context.json');
		$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), self::httpClient($json));

		$data = $client->getContextData();

		self::assertCount(1, $data->experiments);
		self::assertSame([11, 12], array_map(fn($holdout) => $holdout->id, $data->holdouts));
	}

	private static function asyncHttpClient(string $content): AsyncHttpClientInterface {
		return new class($content) implements AsyncHttpClientInterface {
			private string $content;
			public int $asyncGets = 0;

			public function __construct(string $content) {
				$this->content = $content;
			}

			public function get(string $url, array $query = [], array $headers = []): Response {
				throw new \LogicException('the async fetch must not fall back to a blocking get');
			}

			public function put(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				throw new \LogicException('unused');
			}

			public function post(string $url, array $query = [], array $headers = [], string $body = ''): Response {
				throw new \LogicException('unused');
			}

			public function getAsync(string $url, array $query = [], array $headers = []): PromiseInterface {
				++$this->asyncGets;
				$response = new Response();
				$response->status = 200;
				$response->content = $this->content;
				return resolve($response);
			}

			public function putAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
				throw new \LogicException('unused');
			}

			public function postAsync(string $url, array $query = [], array $headers = [], string $body = ''): PromiseInterface {
				throw new \LogicException('unused');
			}

			public function close(): void {
			}
		};
	}

	public function testClientDecodesHoldoutsOnAsyncFetch(): void {
		$json = file_get_contents(__DIR__ . '/../Fixtures/json/holdouts_context.json');
		$http = self::asyncHttpClient($json);
		$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), $http);

		$data = null;
		$client->getContextDataAsync()->then(function (ContextData $resolved) use (&$data) {
			$data = $resolved;
		});

		self::assertSame(1, $http->asyncGets);
		self::assertSame([11, 12], array_map(fn($holdout) => $holdout->id, $data->holdouts));
	}

	// Without an async HTTP client the async fetch resolves the blocking fetch, which keeps the holdouts too.
	public function testClientDecodesHoldoutsOnAsyncFetchWithABlockingHttpClient(): void {
		$json = file_get_contents(__DIR__ . '/../Fixtures/json/holdouts_context.json');
		$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), self::httpClient($json));

		$data = null;
		$client->getContextDataAsync()->then(function (ContextData $resolved) use (&$data) {
			$data = $resolved;
		});

		self::assertSame([11, 12], array_map(fn($holdout) => $holdout->id, $data->holdouts));
	}

	public function testClientTreatsAMissingOrNonArrayHoldoutsFieldAsNoHoldouts(): void {
		foreach (['{"experiments":[]}', '{"experiments":[],"holdouts":null}', '{"experiments":[],"holdouts":{"id":1}}',
			'{"experiments":[],"holdouts":"x"}'] as $json) {
			$client = new Client(new ClientConfig('endpoint', 'key', 'app', 'env'), self::httpClient($json));
			self::assertSame([], $client->getContextData()->holdouts, $json);
		}
	}

	public function testContextDataKeepsItsOneArgumentConstructor(): void {
		$data = new ContextData([]);
		self::assertSame([], $data->holdouts);
		self::assertSame([], (new ContextData())->experiments);
	}

	public function testExperimentReadsHoldoutFieldsAndToleratesMalformedOnes(): void {
		$base = self::experiment(1, 'exp', [12, 11]);
		$base->holdoutType = 'full';
		$experiment = new Experiment($base);
		self::assertSame([12, 11], $experiment->holdoutIds);
		self::assertSame('full', $experiment->holdoutType);

		$absent = self::experiment(1, 'exp', []);
		unset($absent->holdoutIds);
		$experiment = new Experiment($absent);
		self::assertNull($experiment->holdoutIds);
		self::assertNull($experiment->holdoutType);

		$malformed = self::experiment(1, 'exp', []);
		$malformed->holdoutIds = [11, '12', 1.5, null, [13], 14];
		$malformed->holdoutType = ['full'];
		$experiment = new Experiment($malformed);
		self::assertSame([11, 14], $experiment->holdoutIds);
		self::assertNull($experiment->holdoutType);

		$malformed->holdoutIds = 'not-a-list';
		self::assertNull((new Experiment($malformed))->holdoutIds);
	}

	public function testUnknownFieldsAtEveryLevelAreIgnored(): void {
		$data = self::fixture('holdouts_forward_compat.json');
		$errors = [];
		set_error_handler(function (int $errno, string $message) use (&$errors) {
			$errors[] = $message;
			return true;
		});
		try {
			$context = $this->createContext(new ContextData($data->experiments, $data->holdouts));
		}
		finally {
			restore_error_handler();
		}

		self::assertSame([], $errors);
		self::assertTrue($context->isReady());
		self::assertFalse($context->isFailed());
		// holdout_a holds the unit out; holdout_b (3 arms, arm 2) doesn't; the dangling id 999 is ignored.
		self::assertSame(0, $context->getTreatment('exp_covered'));
		self::assertSame(1, $context->getTreatment('exp_uncovered'));
		self::assertSame([
			[11, 'holdout_a', 'session_id', 0],
			[12, 'holdout_b', 'session_id', 2],
			[2, 'exp_uncovered', 'session_id', 1],
		], $this->published($context));
	}

	public function testMalformedHoldoutEntriesAreIgnoredAndOthersStillApply(): void {
		$valid = self::holdout(11, 'holdout_valid', 13, 111);
		$malformed = [
			'a string', 42, [1, 2],
			(object) ['id' => 20, 'name' => 'no_split', 'iteration' => 1, 'seedHi' => 13, 'seedLo' => 111],
			(object) ['id' => 21, 'name' => 'string_split', 'iteration' => 1, 'seedHi' => 13, 'seedLo' => 111, 'split' => '0.1,0.9'],
			(object) ['id' => 22, 'name' => 'string_value', 'iteration' => 1, 'seedHi' => 13, 'seedLo' => 111, 'split' => ['0.1', 0.9]],
			(object) ['id' => '23', 'name' => 'string_id', 'iteration' => 1, 'seedHi' => 13, 'seedLo' => 111, 'split' => [0.1, 0.9]],
			(object) ['id' => 24, 'iteration' => 1, 'seedHi' => 13, 'seedLo' => 111, 'split' => [0.1, 0.9]],
			(object) ['id' => 25, 'name' => 'no_seed', 'iteration' => 1, 'split' => [0.1, 0.9]],
			(object) ['id' => 26, 'name' => 'null_iteration', 'iteration' => null, 'seedHi' => 13, 'seedLo' => 111, 'split' => [0.1, 0.9]],
		];
		$experiments = [self::experiment(1, 'exp_valid', [11])];
		foreach ([20, 21, 22, 23, 24, 25, 26] as $i => $id) {
			$experiments[] = self::experiment(100 + $i, 'exp_malformed_' . $id, [$id]);
		}

		$context = $this->createContext(new ContextData($experiments, array_merge($malformed, [$valid])));

		self::assertSame(0, $context->getTreatment('exp_valid'));
		foreach ([20, 21, 22, 23, 24, 25, 26] as $id) {
			self::assertSame(1, $context->getTreatment('exp_malformed_' . $id), (string) $id);
		}
		self::assertSame([11, 'holdout_valid', 'session_id', 0], $this->published($context)[0]);
	}

	// H1: a holdout id listed twice covers the experiment once and exposes once (JS insertUniqueSorted).
	public function testDuplicateHoldoutIdsCoverOnceAndExposeOnce(): void {
		$context = $this->createContext(new ContextData([self::experiment(1, 'exp', [12, 11, 12, 11])],
			[self::holdout(11, 'holdout_a', 1, 222), self::holdout(12, 'holdout_b', 1, 222)]));

		self::assertSame(1, $context->getTreatment('exp'));
		self::assertSame([
			[1, 'exp', 'session_id', 1],
			[11, 'holdout_a', 'session_id', 1],
			[12, 'holdout_b', 'session_id', 1],
		], $this->published($context));
	}

	// H6: one holdout covering experiments with two unit types publishes one exposure per unit type.
	public function testSharedHoldoutAcrossUnitTypesPublishesOneExposurePerUnitType(): void {
		$context = $this->createContext(new ContextData([
			self::experiment(1, 'exp_session', [11]),
			self::experiment(2, 'exp_user', [11], 'user_id'),
			self::experiment(3, 'exp_session_2', [11]),
		], [self::holdout(11, 'holdout_a', 1, 222)]), ['session_id' => self::UID, 'user_id' => self::UID]);

		$context->getTreatment('exp_session');
		$context->getTreatment('exp_user');
		$context->getTreatment('exp_session_2');

		$holdoutExposures = array_values(array_filter($this->published($context), fn($exposure) => $exposure[0] === 11));
		self::assertSame([[11, 'holdout_a', 'session_id', 1], [11, 'holdout_a', 'user_id', 1]], $holdoutExposures);
	}

	// H7: a strict audience mismatch on a covered experiment that isn't held out still exposes the holdout.
	public function testStrictAudienceMismatchStillExposesTheHoldout(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 1, 222)]));
		$context->setAttribute('age', 5);

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[1, 'exp', 'session_id', 0], [11, 'holdout_a', 'session_id', 1]], $this->published($context));
	}

	// H8 and scenario 226: a suppressed candidate falls back to its control value only when nothing assigned wins.
	public function testSuppressedVariableFallsBackToControlOnlyWhenNothingAssignedWins(): void {
		$suppressed = self::experiment(1, 'exp_suppressed', [11]);
		$suppressed->variants = [(object) ['name' => 'A', 'config' => '{"color":"control_1"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment_1"}']];
		$assigned = self::experiment(2, 'exp_assigned', [12]);
		$assigned->variants = [(object) ['name' => 'A', 'config' => '{"color":"control_2"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment_2"}']];
		$holdouts = [self::holdout(11, 'holdout_a', 13, 111), self::holdout(12, 'holdout_b', 1, 222)];

		$context = $this->createContext(new ContextData([$suppressed, $assigned], $holdouts));
		self::assertSame('treatment_2', $context->peekVariableValue('color', 'default'));
		self::assertSame('treatment_2', $context->getVariableValue('color', 'default'));
		self::assertSame([
			[11, 'holdout_a', 'session_id', 0],
			[2, 'exp_assigned', 'session_id', 1],
			[12, 'holdout_b', 'session_id', 1],
		], $this->published($context));

		$context = $this->createContext(new ContextData([$suppressed], $holdouts));
		self::assertSame('control_1', $context->peekVariableValue('color', 'default'));
		self::assertSame('control_1', $context->getVariableValue('color', 'default'));
		self::assertSame('default', $context->getVariableValue('missing', 'default'));
	}

	// A suppressed candidate that lacks the key still blocks a later suppressed candidate's control value (JS).
	public function testOnlyTheFirstSuppressedCandidateProvidesTheFallback(): void {
		$first = self::experiment(1, 'exp_first', [11]);
		$first->variants = [(object) ['name' => 'A', 'config' => '{"other":1}'], (object) ['name' => 'B', 'config' => '{"color":"t1"}']];
		$second = self::experiment(2, 'exp_second', [11]);
		$second->variants = [(object) ['name' => 'A', 'config' => '{"color":"c2"}'], (object) ['name' => 'B', 'config' => '{"color":"t2"}']];

		$context = $this->createContext(new ContextData([$first, $second], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame('default', $context->getVariableValue('color', 'default'));
	}

	// H4: peeks never queue a holdout exposure.
	public function testPeeksQueueNoHoldoutExposure(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->variants[1]->config = '{"color":"blue"}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 1, 222)]));

		self::assertSame(1, $context->peekTreatment('exp'));
		self::assertSame('blue', $context->peekVariableValue('color', 'default'));
		self::assertSame(0, $context->getPendingCount());
	}

	// A suppressed assignment never evaluated its audience, so attribute changes neither rebuild nor re-expose it.
	public function testAttributeChangesDoNotRebuildASuppressedAssignment(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->getTreatment('exp'));
		$context->setAttribute('age', 5);
		self::assertSame(0, $context->getTreatment('exp'));
		$context->setAttribute('age', 50);
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame(1, $context->getPendingCount());
	}

	// A holdout exposure dropped by the retained-event cap is only re-queued while its arm is still the cached one: after
	// an iteration bump the next read publishes the new arm alone.
	public function testDroppedHoldoutExposureOfARetiredArmIsNotQueuedAgain(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = function () {
			throw new \RuntimeException('collector down');
		};

		self::assertSame(0, $context->getTreatment('exp'));
		$bumped = self::holdout(11, 'holdout_a', 1, 222);
		$bumped->iteration = 2;
		$provider->next = new ContextData([self::experiment(1, 'exp', [11])], [$bumped]);
		$context->refresh();
		$context->track('goal');
		$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		self::assertSame(1, $context->getTreatment('exp'));
		self::assertSame([[1, 'exp', 'session_id', 1], [11, 'holdout_a', 'session_id', 1]], $this->published($context));
	}

	// An override whose experiment left the data keeps its assignment and pinned arm. When the cap drops that arm's
	// exposure after the holdout moved to a new iteration, re-exposing the override must not republish the retired arm.
	public function testDroppedExposureOfARetiredArmPinnedByAnOverrideIsNotQueuedAgain(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$contextConfig->setOverride('exp_gone', 1);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp_gone', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->maxRetainedEvents = 1;

		self::assertSame(1, $context->getTreatment('exp_gone'));
		$bumped = self::holdout(11, 'holdout_a', 1, 222);
		$bumped->iteration = 2;
		$provider->next = new ContextData([self::experiment(2, 'exp_new', [11])], [$bumped]);
		$context->refresh();
		self::assertSame(1, $context->getTreatment('exp_new'));

		$this->eventHandler->prerun = function () {
			throw new \RuntimeException('collector down');
		};
		$context->track('goal');
		$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		self::assertSame(1, $context->getTreatment('exp_gone'));
		self::assertSame([[1, 'exp_gone', 'session_id', 1]], $this->published($context));
	}

	// A holdout exposure dropped by the retained-event cap is re-queued by the next exposing read, like any other.
	public function testHoldoutExposureDroppedByTheRetainedEventCapIsQueuedAgain(): void {
		$context = $this->createContext(new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = function () {
			throw new \RuntimeException('collector down');
		};

		self::assertSame(0, $context->getTreatment('exp'));
		$context->track('goal');
		$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame(2, $context->getPendingCount());
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// getVariableValue() re-queues a holdout exposure the cap dropped, like getTreatment(); once it is queued again
	// the exposed reads go back to skipping the arms.
	public function testVariableReadRequeuesAHoldoutExposureDroppedByTheRetainedEventCap(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->variants = [(object) ['name' => 'A', 'config' => '{"color":"control"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment"}']];
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->maxRetainedEvents = 1;
		$this->eventHandler->prerun = function () {
			throw new \RuntimeException('collector down');
		};

		self::assertSame('control', $context->getVariableValue('color', 'default'));
		$context->track('goal');
		$context->publish();
		self::assertSame(1, $context->getPendingCount());

		$this->eventHandler->prerun = null;
		self::assertSame('control', $context->getVariableValue('color', 'default'));
		self::assertSame(2, $context->getPendingCount());
		self::assertSame([], self::droppedHoldoutArms($context));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	private function contextWithProvider(ContextData $data, array $overrides = []): array {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$contextConfig->setOverrides($overrides);
		return [(new SDK($config))->createContextWithData($contextConfig, $data), $provider];
	}

	private function failNextPublish(Context $context): void {
		$this->eventHandler->prerun = function () {
			throw new \RuntimeException('collector down');
		};
		$context->track('goal');
		$context->publish();
		$this->eventHandler->prerun = null;
	}

	// Queuing a retired arm that an override still pins must not hide the live arm's dropped exposure.
	public function testRetiredArmDoesNotClearTheLiveArmsDroppedExposure(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp_override', [11]), self::experiment(2, 'exp', [11])], [self::holdout(11, 'holdout_a', 1, 222)]),
			['exp_override' => 1]);
		self::assertSame(1, $context->peekTreatment('exp_override'));

		$bumped = self::holdout(11, 'holdout_a', 13, 111);
		$bumped->iteration = 2;
		$provider->next = new ContextData([self::experiment(2, 'exp', [11])], [$bumped]);
		$context->refresh();
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);
		self::assertSame(1, $context->getPendingCount());

		self::assertSame(1, $context->getTreatment('exp_override'));
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[1, 'exp_override', 'session_id', 1], [11, 'holdout_a', 'session_id', 1], [11, 'holdout_a', 'session_id', 0]],
			$this->published($context));
	}

	// An experiment whose id and unit type match a dropped holdout's is not that holdout's arm.
	public function testExperimentWithAHoldoutsIdDoesNotClearItsDroppedExposure(): void {
		[$context] = $this->contextWithProvider(new ContextData(
			[self::experiment(1, 'exp', [11]), self::experiment(11, 'exp_eleven', [])], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$context->maxRetainedEvents = 10000;
		self::assertSame(1, $context->getTreatment('exp_eleven'));
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'exp_eleven', 'session_id', 1], [11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// A dropped arm whose holdout a refresh removed can't be queued again, so exposed reads stop re-checking for it.
	public function testRefreshForgetsADroppedArmWhoseHoldoutIsGone(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);
		self::assertSame(['11:session_id' => true], self::droppedHoldoutArms($context));

		$provider->next = new ContextData([self::experiment(1, 'exp', [])], []);
		$context->refresh();
		self::assertSame([], self::droppedHoldoutArms($context));
	}

	// A refresh that fails part-way keeps the dropped mark, so the held-back holdout exposure is still queued again.
	public function testFailedRefreshKeepsADroppedHoldoutExposurePending(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$broken = self::experiment(2, 'exp_broken', []);
		$broken->audience = '{not json';
		$provider->next = new ContextData([self::experiment(1, 'exp', []), $broken], []);
		$context->refresh();

		$context->maxRetainedEvents = 10000;
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// A refresh with the same holdout keeps the dropped mark; one that bumps its iteration or stops covering any
	// experiment with it forgets the mark, since nothing can queue that arm again.
	public function testRefreshKeepsADroppedArmOnlyWhileItsHoldoutStillCoversAnExperiment(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$provider->next = new ContextData([self::experiment(1, 'exp', [11])], [$holdout]);
		$context->refresh();
		self::assertSame(['11:session_id' => true], self::droppedHoldoutArms($context));

		$provider->next = new ContextData([self::experiment(1, 'exp', [])], [$holdout]);
		$context->refresh();
		self::assertSame([], self::droppedHoldoutArms($context));

		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);
		$bumped = clone $holdout;
		$bumped->iteration = 2;
		$provider->next = new ContextData([self::experiment(1, 'exp', [11])], [$bumped]);
		$context->refresh();
		self::assertSame([], self::droppedHoldoutArms($context));
	}

	private static function droppedHoldoutArms(Context $context): array {
		$property = new \ReflectionProperty(Context::class, 'droppedHoldoutArms');
		if (PHP_VERSION_ID < 80100) {
			$property->setAccessible(true);
		}
		return $property->getValue($context);
	}

	// A refresh that removes an experiment's holdouts rebuilds its suppressed assignment, so the unit is assigned again.
	public function testRefreshRemovingCoverageReassignsTheUnit(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->peekTreatment('exp'));

		$provider->next = new ContextData([self::experiment(1, 'exp', [])], []);
		$context->refresh();
		self::assertSame(1, $context->peekTreatment('exp'));
	}

	private function publishedBody(ContextData $data): string {
		$context = $this->createContext($data, ['session_id' => self::UID, 'user_id' => '123', 'email' => 'a@b.c']);
		$context->setAttribute('age', 21);
		foreach ($data->experiments as $experiment) {
			$context->getTreatment($experiment->name);
		}
		foreach (array_keys($context->getVariableKeys()) as $key) {
			$context->getVariableValue($key, 'default');
		}
		$context->track('goal', ['amount' => 5]);
		$context->publish();

		return preg_replace('/"(publishedAt|exposedAt|achievedAt|setAt)":[0-9.]+/', '"$1":0', json_encode($this->eventHandler->submitted));
	}

	// Without holdouts the published bytes are those of a payload that predates them, whether the holdouts field is
	// absent, empty, or lists holdouts that cover nothing.
	public function testPublishIsByteIdenticalWithoutHoldoutCoverage(): void {
		$experiments = self::fixture('context.json')->experiments;
		$expected = $this->publishedBody(new ContextData(self::fixture('context.json')->experiments));

		self::assertStringContainsString('"exposures":[{"id":1,"name":"exp_test_ab","unit":"session_id","variant":1,', $expected);
		self::assertSame($expected, $this->publishedBody(new ContextData($experiments, [])));
		self::assertSame($expected, $this->publishedBody(new ContextData(self::fixture('context.json')->experiments,
			[self::holdout(11, 'holdout_a', 13, 111)])));

		$dangling = self::fixture('context.json')->experiments;
		foreach ($dangling as $experiment) {
			$experiment->holdoutIds = [999];
		}
		self::assertSame($expected, $this->publishedBody(new ContextData($dangling, [self::holdout(11, 'holdout_a', 13, 111)])));
	}

	private static function invoke(Context $context, string $method, ...$args) {
		$reflection = new \ReflectionMethod(Context::class, $method);
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		return $reflection->invoke($context, ...$args);
	}

	// Scenario 222: arity comes from the split, so a 3-arm split marked "full" (or unmarked) is still a 3-arm holdout.
	public function testThreeArmArityComesFromTheSplitNotHoldoutType(): void {
		foreach (['full', null] as $holdoutType) {
			$holdout = self::holdout(21, 'holdout_three_arm', 0, 3, [0.3, 0.3, 0.4]);
			if ($holdoutType !== null) {
				$holdout->holdoutType = $holdoutType;
			}
			$fullOn = self::experiment(2, 'exp_fullon', [21]);
			$fullOn->fullOnVariant = 1;
			$context = $this->createContext(new ContextData([self::experiment(1, 'exp', [21]), $fullOn], [$holdout]));

			self::assertSame(0, $context->getTreatment('exp'));
			self::assertSame(1, $context->getTreatment('exp_fullon'));
			self::assertSame([[21, 'holdout_three_arm', 'session_id', 1], [2, 'exp_fullon', 'session_id', 1]], $this->published($context));
		}
	}

	// A rule-won experiment is never the suppressed fallback, even when its holdout holds the unit out (JS).
	public function testRuleWonExperimentIsNeverTheSuppressedFallback(): void {
		$ruleWon = self::experiment(1, 'exp_rule_won', [11]);
		// The key is indexed through the control variant, while the rule wins variant B, which lacks it.
		$ruleWon->variants = [(object) ['name' => 'A', 'config' => '{"color":"c1"}'], (object) ['name' => 'B', 'config' => '{"other":2}']];
		$ruleWon->assignmentRules = '{"rules":[{"name":"all","type":"assign","conditions":null,"environments":[],"variant":1}]}';
		$suppressed = self::experiment(2, 'exp_suppressed', [11]);
		$suppressed->variants = [(object) ['name' => 'A', 'config' => '{"color":"c2"}'], (object) ['name' => 'B', 'config' => '{"color":"t2"}']];

		$context = $this->createContext(new ContextData([$ruleWon, $suppressed], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame('c2', $context->peekVariableValue('color', 'default'));
		self::assertSame('c2', $context->getVariableValue('color', 'default'));
	}

	// A suppressed assignment is reused across attribute changes and custom assignments rather than rebuilt per read.
	public function testSuppressedAssignmentIsReusedAcrossAttributeChangesAndCustomAssignments(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->setCustomAssignment('exp', 1);

		self::assertSame(0, $context->getTreatment('exp'));
		$cached = self::invoke($context, 'getAssignment', 'exp');
		$context->setAttribute('age', 5);
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp'));
		$context->setAttribute('age', 50);
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp'));
		self::assertSame(1, $context->getPendingCount());
	}

	// A refresh that fails part-way keeps the holdouts of the data still installed.
	public function testFailedRefreshKeepsTheInstalledHoldouts(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->getTreatment('exp'));

		$broken = self::experiment(2, 'exp_broken', []);
		unset($broken->split);
		$provider->next = new ContextData([self::experiment(1, 'exp', [11]), $broken], [self::holdout(12, 'holdout_b', 1, 222)]);
		$context->refresh();

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// A failed refresh that would have bumped a holdout's iteration and seed leaves the installed definition live, so
	// a newly covered experiment still reads the unit's pinned arm.
	public function testFailedRefreshKeepsTheInstalledHoldoutDefinition(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData(
			[self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_later', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->getTreatment('exp'));

		$bumped = self::holdout(11, 'holdout_a', 1, 222);
		$bumped->iteration = 2;
		$broken = self::experiment(3, 'exp_broken', []);
		unset($broken->variants);
		$provider->next = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_later', [11]), $broken], [$bumped]);
		$context->refresh();

		self::assertSame(0, $context->getTreatment('exp_later'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// The holdout exposure carries the name of the holdout definition the exposing decision was made from, as JS does.
	public function testHoldoutExposureUsesTheNameTheExposingDecisionSaw(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_old_name', 1, 222)]));

		self::assertSame(1, $context->peekTreatment('exp'));
		$provider->next = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_new', [11])],
			[self::holdout(11, 'holdout_new_name', 1, 222)]);
		$context->refresh();

		self::assertSame(1, $context->getTreatment('exp_new'));
		self::assertSame([[2, 'exp_new', 'session_id', 1], [11, 'holdout_new_name', 'session_id', 1]], $this->published($context));
	}

	// Setting an unrelated unit type leaves an unexposed covered assignment alone: a same-iteration split edit, which
	// the cache ignores, shows whether it was rebuilt.
	public function testSetUnitForAnotherUnitTypeKeepsAnUnexposedCoveredAssignment(): void {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$holdout = self::holdout(11, 'holdout_a', 1, 222);
		$context = (new SDK($config))->createContextWithData($contextConfig, new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));

		self::assertSame(1, $context->peekTreatment('exp'));
		$resplit = self::experiment(1, 'exp', [11]);
		$resplit->split = [1.0, 0.0];
		$provider->next = new ContextData([$resplit], [$holdout]);
		$context->refresh();
		$context->setUnit('user_id', 'other');

		self::assertSame(1, $context->getTreatment('exp'));
	}

	// JS can't tell 11.0 from 11, so integral floats name and seed a holdout like integers; the caller's data is untouched.
	public function testIntegralFloatIdsAndSeedsAreIntegers(): void {
		$payload = json_decode('{"experiments":[' . json_encode(self::experiment(1, 'exp', [])) . '],"holdouts":[{"id":11.0,"name":"holdout_a",'
			. '"iteration":1.0,"seedHi":13.0,"seedLo":111.0,"split":[0.1,0.9]}]}', false, 512, JSON_THROW_ON_ERROR);
		$payload->experiments[0]->holdoutIds = [11.0, 11.5, 1e30];
		$payload->experiments[] = self::experiment(2, 'exp_sibling', [11]);
		$context = $this->createContext(new ContextData($payload->experiments, $payload->holdouts));

		// The sibling reuses the arm (iteration 1.0 is 1), so the holdout is exposed once.
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame(0, $context->getTreatment('exp_sibling'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
		self::assertSame(11.0, $payload->holdouts[0]->id);
		self::assertSame(13.0, $payload->holdouts[0]->seedHi);
	}
}
