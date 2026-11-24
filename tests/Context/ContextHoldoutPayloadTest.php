<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;
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
 * Holdouts as they arrive from the collector: decoding, forward compatibility, malformed entries, and the
 * cross-sdk-tests Holdout Conformance scenarios (501-507) that the java-sdk suite doesn't cover.
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
		// 9223372036854775808.0 is 2^63, the first float past PHP_INT_MAX: casting it would warn and wrap.
		$malformed->holdoutIds = [11, '12', 1.5, null, [13], 1e19, -1e19, 9223372036854775808.0, -9223372036854775808.0, 14];
		$malformed->holdoutType = ['full'];
		set_error_handler(static function (int $errno, string $message): bool {
			throw new \ErrorException($message, 0, $errno);
		});
		try {
			$experiment = new Experiment($malformed);
		}
		finally {
			restore_error_handler();
		}
		self::assertSame([11, PHP_INT_MIN, 14], $experiment->holdoutIds);
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

	public function testUnknownRootFieldsSurviveSyncAndAsyncClientDecoding(): void {
		$json = file_get_contents(__DIR__ . '/../Fixtures/json/holdouts_forward_compat.json');
		$expected = json_decode($json);
		$config = new ClientConfig('endpoint', 'key', 'app', 'env');
		$sync = (new Client($config, self::httpClient($json)))->getContextData();
		$async = null;
		(new Client($config, self::asyncHttpClient($json)))->getContextDataAsync()->then(function($data) use (&$async) {
			$async = $data;
		});
		foreach ([$sync, $async] as $data) {
			self::assertInstanceOf(ContextData::class, $data);
			self::assertEquals($expected->experiments, $data->experiments);
			self::assertEquals($expected->holdouts, $data->holdouts);
			$context = $this->createContext($data);
			self::assertSame(0, $context->getTreatment('exp_covered'));
			self::assertSame(1, $context->getTreatment('exp_uncovered'));
		}
	}

	public function testUnchangedHoldoutSnapshotDoesNotReadItsFieldsOnCachedPeeks(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		$context = $this->createContext(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));
		$context->peekTreatment('exp');
		$reads = 0;
		$counted = new class($reads) {
			private int $reads;
			public function __construct(int &$reads) {
				$this->reads = &$reads;
			}
			public function __get(string $name) {
				++$this->reads;
				return $name === 'id' ? 11 : 1;
			}
		};
		$assignment = self::invoke($context, 'getAssignment', 'exp');
		$assignment->holdouts = [$counted];
		self::property($context, 'experimentHoldouts', ['exp' => [$counted]]);
		for ($i = 0; $i < 10; ++$i) {
			$context->peekTreatment('exp');
		}
		self::assertSame(0, $reads);
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

	// A missing iteration, seed or name, an iteration or seed outside the int64 range or not whole, a name that is not a
	// string, or a split with a non-number can't serve an arm: the holdout is ignored without a warning, so a strict
	// error handler doesn't fail the context, and the covered experiment assigns normally. The split [1, 0] would
	// hold every unit out if the holdout were applied.
	public function testMalformedHoldoutIterationSeedOrNameIsIgnoredWithoutAWarning(): void {
		$unset = new \stdClass();
		$cases = [];
		foreach (['iteration', 'seedHi', 'seedLo'] as $numeric) {
			$cases[] = [$numeric, 1e19];
			$cases[] = [$numeric, -1e19];
			// 2^63, the first float past PHP_INT_MAX.
			$cases[] = [$numeric, 9223372036854775808.0];
		}
		$cases[] = ['seedHi', 13.5];
		$cases[] = ['iteration', 1.5];
		$cases[] = ['seedLo', $unset];
		$cases[] = ['name', null];
		$cases[] = ['name', 5];
		$cases[] = ['name', $unset];
		foreach ([[null, 1], [true, 0], [[1], 0], [1, null]] as $split) {
			$cases[] = ['split', $split];
		}
		foreach ($cases as [$field, $value]) {
			$label = $field . '=' . ($value === $unset ? 'unset' : json_encode($value));
			$holdout = self::holdout(11, 'holdout_malformed', 13, 111, [1, 0]);
			if ($value === $unset) {
				unset($holdout->{$field});
			}
			else {
				$holdout->{$field} = $value;
			}
			set_error_handler(static function (int $errno, string $message): bool {
				throw new \ErrorException($message, 0, $errno);
			});
			try {
				$context = $this->createContext(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));
				self::assertTrue($context->isReady(), $label);
				self::assertFalse($context->isFailed(), $label);
				self::assertSame(1, $context->getTreatment('exp'), $label);
			}
			finally {
				restore_error_handler();
			}

			self::assertSame([[1, 'exp', 'session_id', 1]], $this->published($context), $label);
		}
	}

	// A holdout id listed twice covers the experiment once and exposes once (JS insertUniqueSorted).
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

	// Scenario 505: one holdout covering experiments with two unit types publishes one exposure per unit type.
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

	// Scenario 506: a strict audience mismatch on a covered experiment that isn't held out still exposes the holdout.
	public function testStrictAudienceMismatchStillExposesTheHoldout(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 1, 222)]));
		$context->setAttributeValue('age', 5);

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[1, 'exp', 'session_id', 0], [11, 'holdout_a', 'session_id', 1]], $this->published($context));
	}

	// Scenarios 507 and 226: a suppressed candidate falls back to its control value only when nothing assigned wins.
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

	// Main's shared key rule wins over the held-out fallback: a later winner whose variant lacks the key returns the
	// default, not the earlier held-out candidate's control value. Only the winner and the holdouts are exposed.
	public function testWinnerWithoutTheKeyReturnsTheDefaultOverAHeldOutCandidatesControl(): void {
		$suppressed = self::experiment(1, 'exp_suppressed', [11]);
		$suppressed->variants = [(object) ['name' => 'A', 'config' => '{"color":"control_1"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment_1"}']];
		$winner = self::experiment(2, 'exp_winner', []);
		$winner->variants = [(object) ['name' => 'A', 'config' => '{"color":"control_2"}'],
			(object) ['name' => 'B', 'config' => '{"other":"treatment_2"}']];
		$later = self::experiment(3, 'exp_later', []);
		$later->variants = [(object) ['name' => 'A', 'config' => '{"color":"control_3"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment_3"}']];

		$context = $this->createContext(new ContextData([$suppressed, $winner, $later], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame('default', $context->peekVariableValue('color', 'default'));
		self::assertSame(0, $context->getPendingCount());
		self::assertSame('default', $context->getVariableValue('color', 'default'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0], [2, 'exp_winner', 'session_id', 1]], $this->published($context));
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
		$cached = self::invoke($context, 'getAssignment', 'exp');
		$context->setAttributeValue('age', 5);
		self::assertSame(0, $context->getTreatment('exp'));
		$context->setAttributeValue('age', 50);
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp'));
		self::assertSame(1, $context->getPendingCount());
	}

	// A holdout exposure is re-queued only if its arm still owned the holdout's cache slot when the cap dropped it.
	// Here a read draws the new iteration's arm first, so re-reading the removed experiment that pins the retired arm
	// does not replay it.
	public function testDroppedHoldoutExposureOfARetiredArmIsNotQueuedAgain(): void {
		[$context, $provider] = $this->contextWithProvider(new ContextData(
			[self::experiment(1, 'exp_old', [11]), self::experiment(2, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp_old'));

		$bumped = self::holdout(11, 'holdout_a', 1, 222);
		$bumped->iteration = 2;
		$provider->next = new ContextData([self::experiment(2, 'exp', [11])], [$bumped]);
		$context->refresh();
		self::assertSame(1, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 3;
		$this->failNextPublish($context);

		$context->maxRetainedEvents = 10000;
		self::assertSame(0, $context->getTreatment('exp_old'));
		self::assertSame([[2, 'exp', 'session_id', 1], [11, 'holdout_a', 'session_id', 1]], $this->published($context));
	}

	// An override whose experiment left the data keeps its assignment and pinned arm. When the cap drops that arm's
	// exposure after the holdout moved to a new iteration, re-exposing the override must not republish the retired arm.
	public function testDroppedExposureOfARetiredArmPinnedByAnOverrideIsNotQueuedAgain(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp_gone', [11])], [self::holdout(11, 'holdout_a', 13, 111)]), ['exp_gone' => 1]);
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

	private function contextWithProvider(ContextData $data, array $overrides = [], array $units = ['session_id' => self::UID],
		?ContextEventLogger $logger = null): array {
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$provider = new HoldoutDataProvider();
		$config->setContextDataProvider($provider);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($units);
		$contextConfig->setOverrides($overrides);
		if ($logger !== null) {
			$contextConfig->setEventLogger($logger);
		}
		return [(new SDK($config))->createContextWithData($contextConfig, $data), $provider];
	}

	// A refresh whose provider raises an Error, not only an Exception, keeps the previous data, its holdouts and its
	// assignments in use, and refresh() does not throw.
	public function testRefreshWhoseProviderRaisesAnErrorKeepsThePreviousData(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->variants = [(object) ['name' => 'A', 'config' => '{"color":"control"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment"}']];
		$data = new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]);
		[$context, $provider] = $this->contextWithProvider($data);
		self::assertSame(0, $context->peekTreatment('exp'));

		$provider->error = new \TypeError('mistyped field');
		$context->refresh();

		self::assertSame($data, $context->getContextData());
		self::assertFalse($context->isFailed());
		self::assertSame(['color' => 'exp'], $context->getVariableKeys());
		self::assertSame('control', $context->peekVariableValue('color', 'default'));
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
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

	public function testFailedRefreshKeepsADroppedHoldoutExposurePending(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$provider->error = new \RuntimeException('collector down');
		$context->refresh();

		$context->maxRetainedEvents = 10000;
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// A dropped mark stays while a cached assignment pins its arm, even one for an experiment or holdout a refresh
	// removed, and is forgotten once nothing does.
	public function testRefreshKeepsADroppedArmWhileACachedAssignmentPinsIt(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));
		self::assertSame(0, $context->getTreatment('exp'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$provider->next = new ContextData([self::experiment(1, 'exp', [11])], [$holdout]);
		$context->refresh();
		self::assertCount(1, self::droppedHoldoutArms($context));

		$provider->next = new ContextData([self::experiment(1, 'exp', [])], [$holdout]);
		$context->refresh();
		self::assertCount(1, self::droppedHoldoutArms($context));

		$context->maxRetainedEvents = 10000;
		self::assertSame(1, $context->getTreatment('exp'));
		$context->refresh();
		self::assertSame([], self::droppedHoldoutArms($context));
	}

	// After an iteration bump, the dropped exposures of the old arm (pinned by a removed experiment) and of the new arm
	// are tracked apart, so both are queued again whichever is read first.
	public function testDroppedOldAndNewArmsAreRequeuedInEitherReadOrder(): void {
		foreach ([['exp_old', 'exp_new'], ['exp_new', 'exp_old']] as $order) {
			[$context, $provider] = $this->contextWithProvider(
				new ContextData([self::experiment(1, 'exp_old', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
			self::assertSame(0, $context->getTreatment('exp_old'));
			$context->maxRetainedEvents = 1;
			$this->failNextPublish($context);

			$bumped = self::holdout(11, 'holdout_a', 1, 222);
			$bumped->iteration = 2;
			$provider->next = new ContextData([self::experiment(2, 'exp_new', [11])], [$bumped]);
			$context->refresh();
			self::assertSame(1, $context->getTreatment('exp_new'));
			$this->failNextPublish($context);

			$context->maxRetainedEvents = 10000;
			foreach ($order as $name) {
				$context->getTreatment($name);
			}

			$holdoutExposures = array_values(array_filter($this->published($context), fn($exposure) => $exposure[0] === 11));
			sort($holdoutExposures);
			self::assertSame([[11, 'holdout_a', 'session_id', 0], [11, 'holdout_a', 'session_id', 1]], $holdoutExposures,
				implode(',', $order));
		}
	}

	// A refresh while a mark is outstanding skips the null arms of an assignment whose unit isn't set.
	public function testRefreshWithADroppedMarkToleratesAnAssignmentWithAMissingUnit(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		$data = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_user', [11], 'user_id')], [$holdout]);
		[$context, $provider] = $this->contextWithProvider($data);
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame(0, $context->peekTreatment('exp_user'));
		$context->maxRetainedEvents = 1;
		$this->failNextPublish($context);

		$provider->next = $data;
		$context->refresh();
		self::assertCount(1, self::droppedHoldoutArms($context));
	}

	// A refresh that removes the covered experiment, alone or with its holdout, keeps the cached assignment, so the
	// next read still queues the holdout exposure the cap dropped.
	public function testRemovedExperimentStillRequeuesItsDroppedHoldoutExposure(): void {
		foreach ([[self::holdout(11, 'holdout_a', 13, 111)], []] as $holdouts) {
			[$context, $provider] = $this->contextWithProvider(
				new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));
			self::assertSame(0, $context->getTreatment('exp'));
			$context->maxRetainedEvents = 1;
			$this->failNextPublish($context);

			$provider->next = new ContextData([self::experiment(2, 'exp_other', [])], $holdouts);
			$context->refresh();

			$context->maxRetainedEvents = 10000;
			self::assertSame(0, $context->getTreatment('exp'));
			self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context), (string) count($holdouts));
		}
	}

	// An exposed read with nothing dropped returns before touching the holdout arms, as JS does: a stale arm name is
	// left as it was rather than refreshed.
	public function testExposedReadsSkipTheHoldoutArmsWhileNothingIsDropped(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->variants = [(object) ['name' => 'A', 'config' => '{"color":"control"}'],
			(object) ['name' => 'B', 'config' => '{"color":"treatment"}']];
		[$context] = $this->contextWithProvider(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]));
		self::assertSame(0, $context->getTreatment('exp'));

		$arm = self::invoke($context, 'getAssignment', 'exp')->holdoutAssignments[0];
		$arm->name = 'sentinel';
		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame('control', $context->getVariableValue('color', 'default'));
		self::assertSame('sentinel', $arm->name);
	}

	// An override whose experiment changes unit type keeps the same holdout id and iteration, but its arm is drawn
	// again for the new unit type, which publishes its own holdout exposure.
	public function testOverrideRedrawsHoldoutArmsWhenTheUnitTypeChanges(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]), ['exp' => 1],
			['session_id' => self::UID, 'user_id' => 'b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3']);
		self::assertSame(1, $context->getTreatment('exp'));

		$provider->next = new ContextData([self::experiment(1, 'exp', [11], 'user_id')], [$holdout]);
		$context->refresh();
		self::assertSame(1, $context->getTreatment('exp'));

		self::assertSame([
			[1, 'exp', 'session_id', 1], [11, 'holdout_a', 'session_id', 0],
			[1, 'exp', 'user_id', 1], [11, 'holdout_a', 'user_id', 1],
		], $this->published($context));
	}

	// A Refresh handler that reads assignments and then throws leaves the refreshed data installed, so the arm it
	// drew from that data stays consistent with what later reads use.
	public function testThrowingRefreshLoggerKeepsTheRefreshedDataAndItsArms(): void {
		$logger = new class implements ContextEventLogger {
			public bool $armed = false;
			public ?int $peeked = null;

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Refresh) {
					$this->armed = false;
					$this->peeked = $context->peekTreatment('exp');
					throw new \RuntimeException('logger failed');
				}
			}
		};
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 1, 222)]), [], ['session_id' => self::UID], $logger);
		self::assertSame(1, $context->peekTreatment('exp'));

		// Same iteration, a seed that holds the unit out: only a fresh resolution against the new data sees it.
		$logger->armed = true;
		$provider->next = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_new', [11])],
			[self::holdout(11, 'holdout_a', 13, 111)]);
		$context->refresh();

		self::assertSame(1, $logger->peeked);
		self::assertSame(13, $context->getContextData()->holdouts[0]->seedHi);
		self::assertSame(1, $context->getTreatment('exp_new'));
	}

	// A covered experiment that changes unit type on refresh is assigned again for the new unit, and both it and its
	// holdout publish an exposure for that unit type.
	public function testCoveredExperimentIsReassignedWhenTheUnitTypeChanges(): void {
		$holdout = self::holdout(11, 'holdout_a', 1, 222);
		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]), [],
			['session_id' => self::UID, 'user_id' => self::UID]);
		$context->getTreatment('exp');

		$provider->next = new ContextData([self::experiment(1, 'exp', [11], 'user_id')], [$holdout]);
		$context->refresh();
		$context->getTreatment('exp');

		$units = array_map(fn($exposure) => [$exposure[0], $exposure[2]], $this->published($context));
		self::assertSame([[1, 'session_id'], [11, 'session_id'], [1, 'user_id'], [11, 'user_id']], $units);
	}

	// An override on an experiment no holdout covers is reused across a unit type change, as without holdouts.
	public function testUncoveredOverrideIsReusedAcrossAUnitTypeChange(): void {
		$holdout = self::holdout(11, 'holdout_a', 13, 111);
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', []), self::experiment(2, 'exp_covered', [11])], [$holdout]), ['exp' => 1],
			['session_id' => self::UID, 'user_id' => self::UID]);
		self::assertSame(1, $context->getTreatment('exp'));

		$provider->next = new ContextData([self::experiment(1, 'exp', [], 'user_id'), self::experiment(2, 'exp_covered', [11])], [$holdout]);
		$context->refresh();
		self::assertSame(1, $context->getTreatment('exp'));
		self::assertSame([[1, 'exp', 'session_id', 1]], $this->published($context));
	}

	/** @return array{0: Context, 1: HoldoutDataProvider, 2: object} */
	private function contextWithThrowingRefreshLogger(\Throwable $throwable): array {
		$logger = new class($throwable) implements ContextEventLogger {
			public bool $armed = false;
			public array $events = [];
			private \Throwable $throwable;

			public function __construct(\Throwable $throwable) {
				$this->throwable = $throwable;
			}

			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				$this->events[] = $event->getEvent();
				if ($this->armed && $event->getEvent() === ContextEventLoggerEvent::Refresh) {
					throw $this->throwable;
				}
			}
		};
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 1, 222)]), [], ['session_id' => self::UID], $logger);
		$logger->armed = true;
		$logger->events = [];
		return [$context, $provider, $logger];
	}

	private static function captureErrorLog(callable $action): array {
		$file = tempnam(sys_get_temp_dir(), 'absmartly-log');
		$previous = ini_set('error_log', $file);
		try {
			$action();
		}
		finally {
			ini_set('error_log', $previous === false ? '' : $previous);
		}
		$lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
		unlink($file);
		return $lines;
	}

	// A throwing Refresh logger, Exception or Error, is ignored: nothing goes to the error log or back to that logger as
	// an Error event, and the refreshed data stays installed.
	public function testThrowingRefreshLoggerIsIgnoredAndKeepsTheRefreshedData(): void {
		foreach ([new \RuntimeException('logger failed'), new \Error('logger broke')] as $throwable) {
			[$context, $provider, $logger] = $this->contextWithThrowingRefreshLogger($throwable);
			$provider->next = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_new', [])], []);

			$lines = self::captureErrorLog(fn() => $context->refresh());

			self::assertSame([ContextEventLoggerEvent::Refresh], $logger->events, get_class($throwable));
			self::assertSame([], $lines);
			self::assertSame(['exp', 'exp_new'], $context->getExperiments());
		}
	}

	public function testRefreshWhoseProviderFailsKeepsThePreviousData(): void {
		[$context, $provider, $logger] = $this->contextWithThrowingRefreshLogger(new \RuntimeException('unused'));
		$provider->error = new \RuntimeException('collector down');

		self::captureErrorLog(fn() => $context->refresh());

		self::assertSame([ContextEventLoggerEvent::Error], $logger->events);
		self::assertSame(['exp'], $context->getExperiments());
		self::assertSame([11], $context->getContextData()->experiments[0]->holdoutIds);
	}

	// While another experiment's holdout arm is dropped, re-reading an exposed covered experiment re-queues nothing of
	// its own: only the dropped arm comes back, on its own experiment's next read.
	public function testExposedCoveredExperimentIsNotQueuedAgainWhileAnotherArmIsDropped(): void {
		[$context] = $this->contextWithProvider(new ContextData(
			[self::experiment(1, 'exp1', [11]), self::experiment(2, 'exp2', [12])],
			[self::holdout(11, 'holdout_a', 13, 111), self::holdout(12, 'holdout_b', 1, 222)]));
		self::assertSame(0, $context->getTreatment('exp1'));
		self::assertSame(1, $context->getTreatment('exp2'));
		$context->maxRetainedEvents = 3;
		$this->failNextPublish($context);
		self::assertSame(3, $context->getPendingCount());

		self::assertSame(1, $context->getTreatment('exp2'));
		self::assertSame(3, $context->getPendingCount());
		self::assertSame([[2, 'exp2', 'session_id', 1], [12, 'holdout_b', 'session_id', 1]], $this->published($context));
	}

	// When the logger throws on both the experiment's exposure and a holdout's, the experiment's error is re-thrown,
	// after both exposures were queued.
	public function testOwnExposureErrorIsRethrownBeforeAHoldoutError(): void {
		$logger = new class implements ContextEventLogger {
			public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
				if ($event->getEvent() === ContextEventLoggerEvent::Exposure) {
					throw new \RuntimeException('exposure ' . $event->getData()->id);
				}
			}
		};
		$client = new Client(new ClientConfig('https://demo.absmartly.io/v1', 'test-api-key', 'website', 'production'));
		$config = new Config($client);
		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);
		$contextConfig = new ContextConfig();
		$contextConfig->setUnits(['session_id' => self::UID]);
		$contextConfig->setEventLogger($logger);
		$context = (new SDK($config))->createContextWithData($contextConfig,
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 1, 222)]));

		try {
			$context->getTreatment('exp');
			self::fail('the logger error must reach the caller');
		}
		catch (\RuntimeException $exception) {
			self::assertSame('exposure 1', $exception->getMessage());
		}
		self::assertSame(2, $context->getPendingCount());
	}

	private static function droppedHoldoutArms(Context $context): array {
		return self::property($context, 'droppedHoldoutArms');
	}

	/** @return mixed */
	private static function property(Context $context, string $name, ...$value) {
		$reflection = new \ReflectionProperty(Context::class, $name);
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
		if ($value !== []) {
			$reflection->setValue($context, $value[0]);
		}
		return $reflection->getValue($context);
	}

	public function testRefreshRemovingCoverageReassignsTheUnit(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->peekTreatment('exp'));

		$provider->next = new ContextData([self::experiment(1, 'exp', [])], []);
		$context->refresh();
		self::assertSame(1, $context->peekTreatment('exp'));
	}

	private function publishedBody(ContextData $data): string {
		$context = $this->createContext($data, ['session_id' => self::UID, 'user_id' => '123', 'email' => 'a@b.c']);
		$context->setAttributeValue('age', 21);
		foreach ($data->experiments as $experiment) {
			$context->getTreatment($experiment->name);
		}
		foreach (array_keys($context->getVariableKeys()) as $key) {
			$context->getVariableValue($key, 'default');
		}
		$context->trackWithProperties('goal', ['amount' => 5]);
		$context->publish();

		return preg_replace('/"(publishedAt|exposedAt|achievedAt|setAt)":[0-9.]+/', '"$1":0', json_encode($this->eventHandler->submitted));
	}

	// Without holdout coverage the published bytes are those main published before holdouts existed:
	// publish_without_holdouts.json was recorded from origin/main 80a5ec6 with this same sequence (timestamps zeroed).
	// That holds whether the holdouts field is absent, empty, or lists holdouts that cover nothing.
	public function testPublishIsByteIdenticalWithoutHoldoutCoverage(): void {
		$expected = rtrim(file_get_contents(__DIR__ . '/../Fixtures/json/publish_without_holdouts.json'));

		self::assertSame($expected, $this->publishedBody(new ContextData(self::fixture('context.json')->experiments)));
		self::assertSame($expected, $this->publishedBody(new ContextData(self::fixture('context.json')->experiments, [])));
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

	// A rule-won experiment wins the key even when its holdout holds the unit out, so a later held-out candidate's
	// control value is never the fallback: the winner's variant lacks the key, so the read returns the default.
	public function testRuleWonExperimentIsNeverTheSuppressedFallback(): void {
		$ruleWon = self::experiment(1, 'exp_rule_won', [11]);
		// The key is indexed through the control variant, while the rule wins variant B, which lacks it.
		$ruleWon->variants = [(object) ['name' => 'A', 'config' => '{"color":"c1"}'], (object) ['name' => 'B', 'config' => '{"other":2}']];
		$ruleWon->assignmentRules = '{"rules":[{"name":"all","type":"assign","conditions":null,"environments":[],"variant":1}]}';
		$suppressed = self::experiment(2, 'exp_suppressed', [11]);
		$suppressed->variants = [(object) ['name' => 'A', 'config' => '{"color":"c2"}'], (object) ['name' => 'B', 'config' => '{"color":"t2"}']];

		$context = $this->createContext(new ContextData([$ruleWon, $suppressed], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame('default', $context->peekVariableValue('color', 'default'));
		self::assertSame('default', $context->getVariableValue('color', 'default'));
		self::assertSame([[1, 'exp_rule_won', 'session_id', 1], [11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// An overridden experiment wins the key even when its holdout holds the unit out, so a later held-out candidate's
	// control value is never the fallback: the override's variant lacks the key, so the read returns the default.
	public function testOverriddenHeldOutCandidateIsNeverTheSuppressedFallback(): void {
		$overridden = self::experiment(1, 'exp_overridden', [11]);
		// The key is indexed through the control variant, while the override selects variant B, which lacks it.
		$overridden->variants = [(object) ['name' => 'A', 'config' => '{"color":"c1"}'], (object) ['name' => 'B', 'config' => '{"other":2}']];
		$suppressed = self::experiment(2, 'exp_suppressed', [11]);
		$suppressed->variants = [(object) ['name' => 'A', 'config' => '{"color":"c2"}'], (object) ['name' => 'B', 'config' => '{"color":"t2"}']];

		$context = $this->createContext(new ContextData([$overridden, $suppressed], [self::holdout(11, 'holdout_all', 13, 111, [1.0, 0.0])]));
		$context->setOverride('exp_overridden', 1);

		self::assertSame('default', $context->peekVariableValue('color', 'default'));
		self::assertSame('default', $context->getVariableValue('color', 'default'));
		self::assertSame(0, $context->getTreatment('exp_suppressed'));
		self::assertSame([[1, 'exp_overridden', 'session_id', 1], [11, 'holdout_all', 'session_id', 0]], $this->published($context));
	}

	public function testSuppressedAssignmentIsReusedAcrossAttributeChangesAndCustomAssignments(): void {
		$experiment = self::experiment(1, 'exp', [11]);
		$experiment->audienceStrict = true;
		$experiment->audience = '{"filter":[{"gte":[{"var":"age"},{"value":20}]}]}';
		$context = $this->createContext(new ContextData([$experiment], [self::holdout(11, 'holdout_a', 13, 111)]));
		$context->setCustomAssignment('exp', 1);

		self::assertSame(0, $context->getTreatment('exp'));
		$cached = self::invoke($context, 'getAssignment', 'exp');
		$context->setAttributeValue('age', 5);
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp'));
		$context->setAttributeValue('age', 50);
		self::assertSame($cached, self::invoke($context, 'getAssignment', 'exp'));
		self::assertSame(1, $context->getPendingCount());
	}

	public function testFailedRefreshKeepsTheInstalledHoldouts(): void {
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->getTreatment('exp'));

		$provider->error = new \RuntimeException('collector down');
		$context->refresh();

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	public function testFailedRefreshKeepsTheInstalledHoldoutDefinition(): void {
		[$context, $provider] = $this->contextWithProvider(new ContextData(
			[self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_later', [11])], [self::holdout(11, 'holdout_a', 13, 111)]));

		self::assertSame(0, $context->getTreatment('exp'));

		$provider->error = new \RuntimeException('collector down');
		$context->refresh();

		self::assertSame(0, $context->getTreatment('exp_later'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
	}

	// The holdout exposure carries the name of the holdout definition the exposing decision was made from, as JS does.
	public function testHoldoutExposureUsesTheNameTheExposingDecisionSaw(): void {
		[$context, $provider] = $this->contextWithProvider(
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
		$holdout = self::holdout(11, 'holdout_a', 1, 222);
		[$context, $provider] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));

		self::assertSame(1, $context->peekTreatment('exp'));
		$resplit = self::experiment(1, 'exp', [11]);
		$resplit->split = [1.0, 0.0];
		$provider->next = new ContextData([$resplit], [$holdout]);
		$context->refresh();
		$context->setUnit('user_id', 'other');

		self::assertSame(1, $context->getTreatment('exp'));
	}

	/** @dataProvider typedHoldoutDeclarations */
	public function testIntegralFloatsInATypedProviderHoldoutInstallAndShareOneArm(string $declaration): void {
		if (strpos($declaration, 'readonly') !== false && PHP_VERSION_ID < 80100) {
			self::markTestSkipped('readonly properties need PHP 8.1');
		}
		$holdout = eval('return new class { ' . $declaration . ' };');
		[$context, $provider] = $this->contextWithProvider(
			new ContextData([self::experiment(1, 'exp', []), self::experiment(2, 'exp_sibling', [])]));
		self::assertSame([1, 1], [$context->peekTreatment('exp'), $context->peekTreatment('exp_sibling')]);

		$provider->next = new ContextData([self::experiment(1, 'exp', [11]), self::experiment(2, 'exp_sibling', [11])], [$holdout]);
		$context->refresh();

		self::assertSame([0, 0], [$context->getTreatment('exp'), $context->getTreatment('exp_sibling')]);
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
		self::assertSame(2.0, $holdout->iteration);
	}

	public static function typedHoldoutDeclarations(): array {
		$fields = 'public int $id = 11; public string $name = "holdout_a"; public int $seedHi = 13; public int $seedLo = 111; '
			. 'public array $split = [0.1, 0.9];';
		return [
			'typed float' => [$fields . ' public float $iteration = 2.0;'],
			'readonly float' => [$fields . ' public readonly float $iteration; public function __construct() { $this->iteration = 2.0; }'],
		];
	}

	public function testHoldoutWithTheInt64LowerBoundAsAFloatIdIsServable(): void {
		$holdout = self::holdout(11, 'holdout_min', 13, 111, [1.0, 0.0]);
		$holdout->id = -9223372036854775808.0;
		$experiment = self::experiment(1, 'exp', [PHP_INT_MIN]);
		$context = $this->createContext(new ContextData([$experiment], [$holdout]));

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame([[PHP_INT_MIN, 'holdout_min', 'session_id', 0]], $this->published($context));
	}

	/** @dataProvider uninitializedHoldoutFields */
	public function testProviderHoldoutWithAnUninitializedTypedFieldIsIgnored(string $field): void {
		$declarations = ['id' => 'public int $id', 'name' => 'public string $name', 'split' => 'public array $split'];
		$values = ['id' => ' = 11', 'name' => ' = "holdout_a"', 'split' => ' = [0.1, 0.9]'];
		$code = 'public int $iteration = 1; public int $seedHi = 13; public int $seedLo = 111;';
		foreach ($declarations as $name => $declaration) {
			$code .= ' ' . $declaration . ($name === $field ? '' : $values[$name]) . ';';
		}
		$holdout = eval('return new class { ' . $code . ' };');
		[$context] = $this->contextWithProvider(new ContextData([self::experiment(1, 'exp', [11])], [$holdout]));

		self::assertFalse($context->isFailed());
		self::assertSame(1, $context->getTreatment('exp'));
	}

	public static function uninitializedHoldoutFields(): array {
		return ['id' => ['id'], 'name' => ['name'], 'split' => ['split']];
	}

	// JS can't tell 11.0 from 11, so integral floats name and seed a holdout like integers; the caller's data is untouched.
	public function testIntegralFloatIdsAndSeedsAreIntegers(): void {
		$payload = json_decode('{"experiments":[' . json_encode(self::experiment(1, 'exp', [])) . '],"holdouts":[{"id":11.0,"name":"holdout_a",'
			. '"iteration":1.0,"seedHi":13.0,"seedLo":111.0,"split":[0.1,0.9]}]}', false, 512, JSON_THROW_ON_ERROR);
		$payload->experiments[0]->holdoutIds = [11.0, 11.5, 1e30];
		$payload->experiments[] = self::experiment(2, 'exp_sibling', [11]);
		$context = $this->createContext(new ContextData($payload->experiments, $payload->holdouts));

		self::assertSame(0, $context->getTreatment('exp'));
		self::assertSame(0, $context->getTreatment('exp_sibling'));
		self::assertSame([[11, 'holdout_a', 'session_id', 0]], $this->published($context));
		self::assertSame(11.0, $payload->holdouts[0]->id);
		self::assertSame(13.0, $payload->holdouts[0]->seedHi);
	}
}
