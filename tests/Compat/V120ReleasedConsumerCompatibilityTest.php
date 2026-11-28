<?php

namespace ABSmartly\SDK\Tests\Compat;

use ABSmartly\SDK\Assignment;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextEventHandler;
use ABSmartly\SDK\Experiment;
use ABSmartly\SDK\ExperimentVariables;
use ABSmartly\SDK\Exposure;
use ABSmartly\SDK\GoalAchievement;
use ABSmartly\SDK\PublishEvent;
use ABSmartly\SDK\SDK;
use PHPUnit\Framework\TestCase;

/**
 * Published-consumer oracle for additive 1.2.0 -> 1.3.0 (no network in tests).
 *
 * Source: https://repo.packagist.org/p2/absmartly/php-sdk.json, version v1.2.0.
 * Packagist dist/source reference: cbc577289a4e599248d638c21043a9cfff6df142.
 * Downloaded: https://api.github.com/repos/absmartly/php-sdk/zipball/cbc577289a4e599248d638c21043a9cfff6df142
 * ZIP SHA-256: 624ebedd0a64367c4b6b254d0b00294f168ff1a9864b2b0ea755c92009cf36df.
 * Packagist supplies no dist shasum; this is the downloaded archive's own digest.
 *
 * Golden JSON and reflection declarations below were captured by running these
 * factories and reflectionSnapshot() against the extracted published artifact,
 * NOT a local tag or feature branch. To reproduce, register its src/ directory
 * for ABSmartly\\SDK\\ in a fresh process with the installed dev dependencies;
 * print json_encode(makeReleasedModel($case), JSON_THROW_ON_ERROR) for each
 * releasedModelCases() entry; hash('sha256', serialize(get_object_vars($model)))
 * supplies PUBLIC_VARS_SHA256. Capture reflectionSnapshot() for releasedClasses().
 * For makeReleasedPublishEvent(), capture its public bag before json_encode(),
 * then normalize only the wire publishedAt back to 1700000000123.
 * PHP 7.4 and 8.x produce the same golden results.
 *
 * Inputs contain no switchback experiment. Existing dynamic holdout/rule state
 * is shipped 1.2.0 behavior, not permission to leak new defaults into old JSON.
 */
class V120ReleasedConsumerCompatibilityTest extends TestCase {
	public static function releasedModelCases(): array {
		return [
			'assignment, untouched' => ['assignment-empty'],
			'assignment, populated' => ['assignment-populated'],
			'experiment, required fields only' => ['experiment-minimal'],
			'experiment, rules and custom fields' => ['experiment-rich'],
			'experiment, existing holdout metadata' => ['experiment-holdout'],
			'context data, empty' => ['data-empty'],
			'context data, raw collector objects' => ['data-raw'],
			'experiment variables, uninitialized' => ['variables-empty'],
			'experiment variables, populated' => ['variables-populated'],
			'exposure, uninitialized' => ['exposure-empty'],
			'exposure, factory' => ['exposure-populated'],
			'goal, properties' => ['goal'],
			'assignment, resolved and exposed by Context' => ['assignment-resolved'],
		];
	}

	private static function experimentInput(): object {
		return (object) [
			'id' => 71, 'name' => 'released_unit', 'unitType' => 'user_id', 'iteration' => 3,
			'seedHi' => 11, 'seedLo' => 22, 'split' => [0.0, 1.0],
			'trafficSeedHi' => 33, 'trafficSeedLo' => 44, 'trafficSplit' => [0.0, 1.0],
			'fullOnVariant' => 0, 'variants' => [],
		];
	}

	/** Public to make the artifact capture procedure above reproducible. */
	public static function makeReleasedModel(string $case): object {
		switch ($case) {
			case 'assignment-empty':
				return new Assignment();
			case 'assignment-populated':
				$assignment = new Assignment();
				$assignment->id = 71;
				$assignment->iteration = 3;
				$assignment->name = 'released_unit';
				$assignment->unitType = 'user_id';
				$assignment->trafficSplit = [0.0, 1.0];
				$assignment->variant = 1;
				$assignment->assigned = true;
				$assignment->eligible = true;
				$assignment->variables = (object) ['banner' => 'blue', 'enabled' => true];
				return $assignment;
			case 'experiment-minimal':
				return new Experiment(self::experimentInput());
			case 'experiment-rich':
				$data = self::experimentInput();
				$data->audience = '{"filter":["eq",{"var":"country"},"PT"]}';
				$data->audienceStrict = true;
				$data->applications = [(object) ['name' => 'shop']];
				$data->variants = [(object) ['name' => 'A', 'config' => null],
					(object) ['name' => 'B', 'config' => '{"banner":"blue"}']];
				$data->customFieldValues = [(object) ['name' => 'owner', 'type' => 'string', 'value' => 'sdk']];
				$data->assignmentRules = '{"rules":[]}';
				$data->unknownCollectorField = 'ignored';
				return new Experiment($data);
			case 'experiment-holdout':
				$data = self::experimentInput();
				$data->holdoutIds = [19, 20.0, '21', null, 1.5];
				$data->holdoutType = 'full';
				return new Experiment($data);
			case 'data-empty':
				return new ContextData();
			case 'data-raw':
				return new ContextData([self::experimentInput()], [(object) ['id' => 19, 'split' => [0.5, 0.5]]]);
			case 'variables-empty':
				return new ExperimentVariables();
			case 'variables-populated':
				$variables = new ExperimentVariables();
				$variables->data = new Experiment(self::experimentInput());
				$variables->variables = ['banner' => ['blue', 'green']];
				return $variables;
			case 'exposure-empty':
				return new Exposure();
			case 'exposure-populated':
				return Exposure::create(71, 'released_unit', 'user_id', 1, 1700000000123.0,
					true, true, false, false, false, false);
			case 'goal':
				return new GoalAchievement('checkout', 1700000000123, (object) ['amount' => 12.5, 'currency' => 'EUR']);
			case 'assignment-resolved':
				// Real consumer path, including dynamic 1.2.0 state on an ordinary unit assignment.
				// Explicit data and an offline handler forbid network access even on an accidental publish.
				$client = new Client(new ClientConfig('https://unused.invalid', 'key', 'app', 'env'));
				$handler = new class($client) extends ContextEventHandler {
					public function publish(PublishEvent $event): void {
						throw new \LogicException('This compatibility fixture must not publish');
					}
				};
				$config = (new Config($client))->setContextEventHandler($handler);
				$context = (new SDK($config))->createContextWithData(
					(new ContextConfig())->setUnit('user_id', 'released-customer'), new ContextData([self::experimentInput()])
				);
				$context->getTreatment('released_unit');
				$cache = new \ReflectionProperty(Context::class, 'assignmentCache');
				if (PHP_VERSION_ID < 80100) { $cache->setAccessible(true); }
				return $cache->getValue($context)['released_unit'];
		}
		throw new \LogicException('Unknown capture case: ' . $case);
	}

	/** @dataProvider releasedModelCases */
	public function testNonSwitchbackModelsKeepReleasedObjectVarsAndExactJson(string $case): void {
		$model = self::makeReleasedModel($case);
		$expectedJson = self::MODEL_JSON[$case];
		// These independently guard the public property bag and a model's jsonSerialize() contract.
		self::assertSame($expectedJson, json_encode((object) get_object_vars($model), JSON_THROW_ON_ERROR), $case . ' get_object_vars');
		self::assertSame($expectedJson, json_encode($model, JSON_THROW_ON_ERROR), $case . ' json_encode');
		self::assertSame(self::PUBLIC_VARS_SHA256[$case], hash('sha256', serialize(get_object_vars($model))),
			$case . ' strict public values, scalar types and nested object classes');
		self::assertSame(array_keys(get_object_vars(json_decode($expectedJson, false, 512, JSON_THROW_ON_ERROR))),
			array_keys(get_object_vars($model)), $case . ' public property names and order');
	}

	public static function makeReleasedPublishEvent(): PublishEvent {
		$event = new PublishEvent();
		$event->setUnits(['user_id' => 'released-customer']);
		$event->setAttributes([(object) ['name' => 'country', 'value' => 'PT', 'setAt' => 1700000000000]]);
		$event->exposures = [self::makeReleasedModel('exposure-populated')];
		$event->goals = [self::makeReleasedModel('goal')];
		$event->publishedAt = 1700000000123;
		return $event;
	}

	public function testNonSwitchbackPublishEventKeepsReleasedPublicBagAndWireJson(): void {
		$event = self::makeReleasedPublishEvent();
		self::assertSame(self::PUBLISH_VARS_JSON, json_encode((object) get_object_vars($event), JSON_THROW_ON_ERROR));
		$wire = json_decode(json_encode($event, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
		self::assertIsInt($wire->publishedAt);
		// PublishEvent refreshes only this clock field in jsonSerialize(); normalize it, not any model state.
		$wire->publishedAt = 1700000000123;
		self::assertSame(self::PUBLISH_WIRE_JSON, json_encode($wire, JSON_THROW_ON_ERROR));
	}

	public static function releasedClasses(): array {
		return [Assignment::class, Experiment::class, ExperimentVariables::class, Exposure::class,
			ContextData::class, GoalAchievement::class, PublishEvent::class, Context::class,
			ContextConfig::class, SDK::class];
	}

	/** PHP 7.4's ReflectionType string cast omits nullability; 8.x can resolve self differently. */
	private static function typeSignature(\ReflectionType $type, string $declaringClass): string {
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
		if ($name === 'self') {
			$name = $declaringClass;
		}
		return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
	}

	/** Capture declarations, ignoring private implementation, source paths and line numbers. */
	public static function reflectionSnapshot(string $class): array {
		$reflection = new \ReflectionClass($class);
		$parent = $reflection->getParentClass();
		$defaults = $reflection->getDefaultProperties();
		$result = [
			'class' => ($reflection->isFinal() ? 'final ' : '') . ($reflection->isAbstract() ? 'abstract ' : '')
				. $class . ($parent ? ' extends ' . $parent->getName() : ''),
			'properties' => [], 'methods' => [],
		];
		foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $property) {
			$name = $property->getName();
			$result['properties'][$name] = ($property->isPublic() ? 'public ' : 'protected ')
				. ($property->isStatic() ? 'static ' : '') . ($property->hasType() ? self::typeSignature($property->getType(), $property->getDeclaringClass()->getName()) . ' ' : '')
				. (array_key_exists($name, $defaults) ? '= ' . json_encode($defaults[$name], JSON_THROW_ON_ERROR) : 'uninitialized');
		}
		foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
			$parameters = [];
			foreach ($method->getParameters() as $parameter) {
				$parameters[] = ($parameter->hasType() ? self::typeSignature($parameter->getType(), $method->getDeclaringClass()->getName()) . ' ' : '')
					. ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName()
					. ($parameter->isDefaultValueAvailable() ? ' = ' . json_encode($parameter->getDefaultValue(), JSON_THROW_ON_ERROR) : '');
			}
			$result['methods'][$method->getName()] = ($method->isPublic() ? 'public ' : 'protected ')
				. ($method->isFinal() ? 'final ' : '') . ($method->isAbstract() ? 'abstract ' : '') . ($method->isStatic() ? 'static ' : '')
				. ($method->returnsReference() ? '&' : '') . '(' . implode(', ', $parameters) . ')'
				. ($method->hasReturnType() ? ': ' . self::typeSignature($method->getReturnType(), $method->getDeclaringClass()->getName()) : '');
		}
		ksort($result['properties']);
		ksort($result['methods']);
		return $result;
	}

	public function testReleasedReflectionDeclarationsRemainCompatible(): void {
		foreach (self::RELEASED_SIGNATURES as $class => $json) {
			$expected = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			$actual = self::reflectionSnapshot($class);
			self::assertSame($expected['class'], $actual['class'], $class);
			if ($class === Context::class || $class === ContextConfig::class || $class === SDK::class) {
				// New entry points are additive; removing or changing an old signature is not.
				foreach (['properties', 'methods'] as $kind) {
					foreach ($expected[$kind] as $name => $signature) {
						self::assertArrayHasKey($name, $actual[$kind], $class . '::' . $name);
						self::assertSame($signature, $actual[$kind][$name], $class . '::' . $name);
					}
				}
			} else {
				// Declaring new model members can break subclass names as well as default JSON.
				self::assertSame($expected, $actual, $class);
			}
		}
	}

	public function testCustomerDeclaredSwitchbackNamesKeepReleasedPayloadHydration(): void {
		$data = self::experimentInput();
		$data->randomizationType = 'unit';
		$data->switchbackPeriodMs = 3600000;
		$experiment = new class($data) extends Experiment {
			public string $randomizationType = 'customer-default';
			public int $switchbackPeriodMs = 17;
		};
		self::assertSame('unit', $experiment->randomizationType);
		self::assertSame(3600000, $experiment->switchbackPeriodMs);
	}

	/** Released hydration assigns customer-declared properties with PHP's weak coercion, not the switchback normalizer. */
	public function testCustomerDeclaredSwitchbackNamesKeepReleasedWeakCoercion(): void {
		$data = self::experimentInput();
		$data->randomizationType = 123;
		$data->switchbackPeriodMs = '3600000';
		$experiment = new class($data) extends Experiment {
			public string $randomizationType = 'customer';
			public int $switchbackPeriodMs = 17;
		};
		self::assertSame(['123', 3600000], [$experiment->randomizationType, $experiment->switchbackPeriodMs]);
	}

	public function testCustomerSubclassesReservingSwitchbackNamesStillConstruct(): void {
		// Only old payload fields: asserting behavior absent from the release would not be a compatibility test.
		$experiment = new class(self::experimentInput()) extends Experiment {
			public const SWITCHBACK_FIELDS = 'customer';
			public int $randomizationType = 17;
			public string $switchbackClusters = 'customer';
			public int $switchbackClusterValues = 18;
			public string $switchbackStartAt = 'customer';
			public string $switchbackPeriodMs = 'customer';
			public string $switchbackCarryoverDurationMs = 'customer';
			public string $switchbackBurnInDurationMs = 'customer';
			public string $switchbackBurnOutDurationMs = 'customer';
			public int $switchbackDesignType = 19;
			public int $switchbackEstimand = 20;
			public function setSwitchbackField(int $field): int { return $field; }
			public function sdkSwitchback(int $value): int { return $value; }
		};
		foreach ([
			'randomizationType' => 17, 'switchbackClusters' => 'customer', 'switchbackClusterValues' => 18,
			'switchbackStartAt' => 'customer', 'switchbackPeriodMs' => 'customer',
			'switchbackCarryoverDurationMs' => 'customer', 'switchbackBurnInDurationMs' => 'customer',
			'switchbackBurnOutDurationMs' => 'customer', 'switchbackDesignType' => 19, 'switchbackEstimand' => 20,
		] as $name => $value) {
			self::assertSame($value, $experiment->{$name}, $name);
		}
		self::assertSame('customer', $experiment::SWITCHBACK_FIELDS);
		self::assertSame(7, $experiment->setSwitchbackField(7));
		self::assertSame(8, $experiment->sdkSwitchback(8));

		$assignment = new class extends Assignment {
			public string $switchback = 'customer';
			public int $switchbackSlot = 21;
			public string $switchbackPeriod = 'customer';
			public string $switchbackServing = 'customer';
			public function sdkSwitchback(int $value): int { return $value; }
		};
		self::assertSame('customer', $assignment->switchback);
		self::assertSame(21, $assignment->switchbackSlot);
		self::assertSame('customer', $assignment->switchbackPeriod);
		self::assertSame('customer', $assignment->switchbackServing);
		self::assertSame(9, $assignment->sdkSwitchback(9));
		$contextData = new class([], []) extends ContextData {
			public string $switchbacks = 'customer';
			public string $switchback = 'customer';
		};
		self::assertSame('customer', $contextData->switchbacks);
		self::assertSame('customer', $contextData->switchback);
	}

	// SHA-256 of serialize(get_object_vars($model)); captures float/int and nested class distinctions JSON loses.
	private const PUBLIC_VARS_SHA256 = [
		'assignment-empty' => 'bb6d9987831544327432f9d8e2aac4ffcb81536cbefe6711a4680a6580d42a09',
		'assignment-populated' => 'b7a7b78ec9256e62165a58cc957d428c52f40b3c17f30fdf1029abe51cbc0c49',
		'experiment-minimal' => '174fdeaed75aa1352d1e69c89186b8ccc0e63d59f89f2a84bb746dcf7bef2c28',
		'experiment-rich' => '22d3cf18ee8dfc3453fbe0c77ebd2b09bbf5073f0e0f835173f7077fae7f8b9a',
		'experiment-holdout' => '34e01102cc94dbd35b09f1a0031fefec0322cbe8c6825a102e04b1076dd6f5f6',
		'data-empty' => 'c4acfb93371edf743347346c00adce95d6fa14a7e28e66010e96c4ac53f5c6ac',
		'data-raw' => '279aece7a7eb83057c25bfc92e7e5be6b56935a2ff7e3eeb7d736188607cad04',
		'variables-empty' => '35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3',
		'variables-populated' => '13d5b948b4ebde8e8b084631932b76f2c0036f1f415b471b776f4fff64f41cb5',
		'exposure-empty' => '35dcfeeca2b0b75f2030d44cdfd6d59cfc84d4e3707c1264677a76eea9cb1783',
		'exposure-populated' => '7fdde728b426a5c2eb93928d8bcca3bc1893793c35e6aa933cf3953c338444b0',
		'goal' => '7ddb89b87b36f4162852a1e704336f8d557c0cfa6abb0cd2bca9f3648c00f01c',
		'assignment-resolved' => 'b7d9fdba8bb7fe0bc6ac70fc4682e0ff8920cd8cd81351cd7877516cccf480a8',
	];

	private const PUBLISH_VARS_JSON = <<<'JSON'
{"publishedAt":1700000000123,"hashed":true,"exposures":[{"id":71,"name":"released_unit","unit":"user_id","variant":1,"exposedAt":1700000000123,"assigned":true,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":false}],"goals":[{"name":"checkout","achievedAt":1700000000123,"properties":{"amount":12.5,"currency":"EUR"}}]}
JSON;

	private const PUBLISH_WIRE_JSON = <<<'JSON'
{"hashed":true,"publishedAt":1700000000123,"goals":[{"name":"checkout","achievedAt":1700000000123,"properties":{"amount":12.5,"currency":"EUR"}}],"exposures":[{"id":71,"name":"released_unit","unit":"user_id","variant":1,"exposedAt":1700000000123,"assigned":true,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":false}],"units":[{"type":"user_id","uid":"dPFgQNg4a-iMcTP8wtyVYA"}],"attributes":[{"name":"country","value":"PT","setAt":1700000000000}]}
JSON;

	private const MODEL_JSON = [
		'assignment-empty' => <<<'JSON'
{"id":0,"iteration":0,"fullOnVariant":0,"name":"","unitType":null,"trafficSplit":[],"variant":0,"assigned":false,"overridden":false,"eligible":false,"fullOn":false,"custom":false,"ruleOverride":false,"ruleVariant":null,"isRuleMissingUnit":false,"isUnitPinnedMissing":false,"ruleKey":"","audienceMismatch":false,"exposed":false,"attrsSeq":0}
JSON
		,
		'assignment-populated' => <<<'JSON'
{"id":71,"iteration":3,"fullOnVariant":0,"name":"released_unit","unitType":"user_id","trafficSplit":[0,1],"variant":1,"assigned":true,"overridden":false,"eligible":true,"fullOn":false,"custom":false,"ruleOverride":false,"ruleVariant":null,"isRuleMissingUnit":false,"isUnitPinnedMissing":false,"ruleKey":"","audienceMismatch":false,"variables":{"banner":"blue","enabled":true},"exposed":false,"attrsSeq":0}
JSON
		,
		'experiment-minimal' => <<<'JSON'
{"id":71,"name":"released_unit","unitType":"user_id","iteration":3,"seedHi":11,"seedLo":22,"split":[0,1],"trafficSeedHi":33,"trafficSeedLo":44,"trafficSplit":[0,1],"fullOnVariant":0,"audience":null,"audienceStrict":false,"variants":[],"customFieldValues":null,"assignmentRules":null,"holdoutIds":null,"holdoutType":null}
JSON
		,
		'experiment-rich' => <<<'JSON'
{"id":71,"name":"released_unit","unitType":"user_id","iteration":3,"seedHi":11,"seedLo":22,"split":[0,1],"trafficSeedHi":33,"trafficSeedLo":44,"trafficSplit":[0,1],"fullOnVariant":0,"audience":{"filter":["eq",{"var":"country"},"PT"]},"audienceStrict":true,"applications":[{"name":"shop"}],"variants":[{"name":"A","config":null},{"name":"B","config":"{\"banner\":\"blue\"}"}],"customFieldValues":[{"name":"owner","type":"string","value":"sdk"}],"assignmentRules":"{\"rules\":[]}","holdoutIds":null,"holdoutType":null}
JSON
		,
		'experiment-holdout' => <<<'JSON'
{"id":71,"name":"released_unit","unitType":"user_id","iteration":3,"seedHi":11,"seedLo":22,"split":[0,1],"trafficSeedHi":33,"trafficSeedLo":44,"trafficSplit":[0,1],"fullOnVariant":0,"audience":null,"audienceStrict":false,"variants":[],"customFieldValues":null,"assignmentRules":null,"holdoutIds":[19,20],"holdoutType":"full"}
JSON
		,
		'data-empty' => <<<'JSON'
{"experiments":[],"holdouts":[]}
JSON
		,
		'data-raw' => <<<'JSON'
{"experiments":[{"id":71,"name":"released_unit","unitType":"user_id","iteration":3,"seedHi":11,"seedLo":22,"split":[0,1],"trafficSeedHi":33,"trafficSeedLo":44,"trafficSplit":[0,1],"fullOnVariant":0,"variants":[]}],"holdouts":[{"id":19,"split":[0.5,0.5]}]}
JSON
		,
		'variables-empty' => <<<'JSON'
{}
JSON
		,
		'variables-populated' => <<<'JSON'
{"data":{"id":71,"name":"released_unit","unitType":"user_id","iteration":3,"seedHi":11,"seedLo":22,"split":[0,1],"trafficSeedHi":33,"trafficSeedLo":44,"trafficSplit":[0,1],"fullOnVariant":0,"audience":null,"audienceStrict":false,"variants":[],"customFieldValues":null,"assignmentRules":null,"holdoutIds":null,"holdoutType":null},"variables":{"banner":["blue","green"]}}
JSON
		,
		'exposure-empty' => <<<'JSON'
{"ruleOverride":false}
JSON
		,
		'exposure-populated' => <<<'JSON'
{"id":71,"name":"released_unit","unit":"user_id","variant":1,"exposedAt":1700000000123,"assigned":true,"eligible":true,"overridden":false,"fullOn":false,"custom":false,"audienceMismatch":false,"ruleOverride":false}
JSON
		,
		'goal' => <<<'JSON'
{"name":"checkout","achievedAt":1700000000123,"properties":{"amount":12.5,"currency":"EUR"}}
JSON
		,
		'assignment-resolved' => <<<'JSON'
{"id":71,"iteration":3,"fullOnVariant":0,"name":"released_unit","unitType":"user_id","trafficSplit":[0,1],"variant":1,"assigned":true,"overridden":false,"eligible":true,"fullOn":false,"custom":false,"ruleOverride":false,"ruleVariant":null,"isRuleMissingUnit":false,"isUnitPinnedMissing":false,"ruleKey":"","audienceMismatch":false,"exposed":true,"attrsSeq":0,"served":true,"suppressed":false,"holdouts":null,"holdoutAssignments":null,"holdoutArmCount":0}
JSON
		,
	];
	private const RELEASED_SIGNATURES = [
		\ABSmartly\SDK\Assignment::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Assignment extends ABSmartly\\SDK\\DynamicModel",
    "properties": {
        "assigned": "public bool = false",
        "attrsSeq": "public int = 0",
        "audienceMismatch": "public bool = false",
        "custom": "public bool = false",
        "eligible": "public bool = false",
        "exposed": "public bool = false",
        "fullOn": "public bool = false",
        "fullOnVariant": "public int = 0",
        "id": "public int = 0",
        "isRuleMissingUnit": "public bool = false",
        "isUnitPinnedMissing": "public bool = false",
        "iteration": "public int = 0",
        "name": "public string = \"\"",
        "overridden": "public bool = false",
        "ruleKey": "public string = \"\"",
        "ruleOverride": "public bool = false",
        "ruleVariant": "public ?int = null",
        "trafficSplit": "public array = []",
        "unitType": "public ?string = null",
        "variables": "public stdClass uninitialized",
        "variant": "public int = 0"
    },
    "methods": []
}
JSON
		,
		\ABSmartly\SDK\Experiment::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Experiment extends ABSmartly\\SDK\\DynamicModel",
    "properties": {
        "applications": "public array uninitialized",
        "assignmentRules": "public ?string = null",
        "audience": "public ?object uninitialized",
        "audienceStrict": "public bool = false",
        "customFieldValues": "public ?array = null",
        "fullOnVariant": "public int uninitialized",
        "id": "public int uninitialized",
        "iteration": "public int uninitialized",
        "name": "public string uninitialized",
        "seedHi": "public int uninitialized",
        "seedLo": "public int uninitialized",
        "split": "public array uninitialized",
        "trafficSeedHi": "public int uninitialized",
        "trafficSeedLo": "public int uninitialized",
        "trafficSplit": "public array uninitialized",
        "unitType": "public string uninitialized",
        "variants": "public array uninitialized"
    },
    "methods": {
        "__construct": "public (object $data)"
    }
}
JSON
		,
		\ABSmartly\SDK\ExperimentVariables::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\ExperimentVariables",
    "properties": {
        "data": "public ABSmartly\\SDK\\Experiment uninitialized",
        "variables": "public array uninitialized"
    },
    "methods": []
}
JSON
		,
		\ABSmartly\SDK\Exposure::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Exposure",
    "properties": {
        "assigned": "public bool uninitialized",
        "audienceMismatch": "public bool uninitialized",
        "custom": "public bool uninitialized",
        "eligible": "public bool uninitialized",
        "exposedAt": "public float uninitialized",
        "fullOn": "public bool uninitialized",
        "id": "public int uninitialized",
        "name": "public string uninitialized",
        "overridden": "public bool uninitialized",
        "ruleOverride": "public bool = false",
        "unit": "public ?string uninitialized",
        "variant": "public int uninitialized"
    },
    "methods": {
        "create": "public static (int $id, string $name, ?string $unit, int $variant, float $exposedAt, bool $assigned, bool $eligible, bool $overridden, bool $fullOn, bool $custom, bool $audienceMismatch): ABSmartly\\SDK\\Exposure",
        "ingestAssignment": "public (ABSmartly\\SDK\\Assignment $assignment): void"
    }
}
JSON
		,
		\ABSmartly\SDK\Context\ContextData::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Context\\ContextData extends ABSmartly\\SDK\\DynamicModel",
    "properties": {
        "experiments": "public array uninitialized"
    },
    "methods": {
        "__construct": "public (array $experiments = [], array $holdouts = [])"
    }
}
JSON
		,
		\ABSmartly\SDK\GoalAchievement::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\GoalAchievement",
    "properties": {
        "achievedAt": "public int uninitialized",
        "name": "public string uninitialized",
        "properties": "public ?object uninitialized"
    },
    "methods": {
        "__construct": "public (string $name, int $achievedAt, ?object $properties)",
        "jsonSerialize": "public (): stdClass"
    }
}
JSON
		,
		\ABSmartly\SDK\PublishEvent::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\PublishEvent",
    "properties": {
        "attributes": "protected array = []",
        "exposures": "public array = []",
        "goals": "public array = []",
        "hashed": "public bool = false",
        "publishedAt": "public int uninitialized",
        "units": "protected array = []"
    },
    "methods": {
        "__construct": "public ()",
        "hashUnit": "public (string $unit): string",
        "jsonSerialize": "public (): object",
        "setAttributes": "public (array $attributes): void",
        "setUnits": "public (array $units): void"
    }
}
JSON
		,
		\ABSmartly\SDK\Context\Context::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Context\\Context",
    "properties": {
        "maxRetainedEvents": "public int = 10000"
    },
    "methods": {
        "close": "public (): void",
        "createFromContextConfig": "public static (ABSmartly\\SDK\\SDK $sdk, ABSmartly\\SDK\\Context\\ContextConfig $contextConfig, ABSmartly\\SDK\\Context\\ContextDataProvider $dataProvider, ABSmartly\\SDK\\Context\\ContextEventHandler $handler, ?ABSmartly\\SDK\\Context\\ContextData $contextData = null): ABSmartly\\SDK\\Context\\Context",
        "createPending": "public static (ABSmartly\\SDK\\SDK $sdk, ABSmartly\\SDK\\Context\\ContextConfig $contextConfig, ABSmartly\\SDK\\Context\\ContextDataProvider $dataProvider, ABSmartly\\SDK\\Context\\ContextEventHandler $handler): ABSmartly\\SDK\\Context\\Context",
        "customFieldValue": "public (string $experimentName, string $fieldName)",
        "finalize": "public (): void",
        "flush": "public (): void",
        "getAttribute": "public (string $name)",
        "getAttributes": "public (): array",
        "getContextData": "public (): ABSmartly\\SDK\\Context\\ContextData",
        "getCustomAssignment": "public (string $experimentName)",
        "getCustomFieldKeys": "public (): array",
        "getCustomFieldValueType": "public (string $experimentName, string $key): ?string",
        "getExperiment": "public (string $experimentName): ?ABSmartly\\SDK\\ExperimentVariables",
        "getExperiments": "public (): array",
        "getOverride": "public (string $experimentName): ?int",
        "getPendingCount": "public (): int",
        "getTime": "public static (): int",
        "getTreatment": "public (string $experimentName): int",
        "getUnit": "public (string $unitType): ?string",
        "getUnits": "public (): array",
        "getVariableKeys": "public (): array",
        "getVariableValue": "public (string $key, $defaultValue = null)",
        "isClosed": "public (): bool",
        "isFailed": "public (): bool",
        "isFinalized": "public (): bool",
        "isFinalizing": "public (): bool",
        "isReady": "public (): bool",
        "peekTreatment": "public (string $experimentName): int",
        "peekVariableValue": "public (string $key, $default)",
        "pending": "public (): int",
        "publish": "public (): void",
        "readyError": "public (): ?Throwable",
        "refresh": "public (): void",
        "setAttribute": "public (string $name, string $value): ABSmartly\\SDK\\Context\\Context",
        "setAttributeValue": "public (string $name, $value): ABSmartly\\SDK\\Context\\Context",
        "setAttributeValues": "public (array $attributes): ABSmartly\\SDK\\Context\\Context",
        "setAttributes": "public (array $attributes): ABSmartly\\SDK\\Context\\Context",
        "setContextData": "public (ABSmartly\\SDK\\Context\\ContextData $contextData): void",
        "setContextDataFailed": "public (Throwable $error): void",
        "setCustomAssignment": "public (string $experimentName, int $variant): ABSmartly\\SDK\\Context\\Context",
        "setCustomAssignments": "public (array $customAssignments): ABSmartly\\SDK\\Context\\Context",
        "setOverride": "public (string $experimentName, int $variant): ABSmartly\\SDK\\Context\\Context",
        "setOverrides": "public (array $overrides): ABSmartly\\SDK\\Context\\Context",
        "setUnit": "public (string $unitType, string $uid): ABSmartly\\SDK\\Context\\Context",
        "setUnits": "public (array $units): ABSmartly\\SDK\\Context\\Context",
        "track": "public (string $goalName, ?object $properties = null): void",
        "trackWithProperties": "public (string $goalName, $properties = null): void"
    }
}
JSON
		,
		\ABSmartly\SDK\Context\ContextConfig::class => <<<'JSON'
{
    "class": "ABSmartly\\SDK\\Context\\ContextConfig",
    "properties": [],
    "methods": {
        "getAttribute": "public (string $name)",
        "getAttributes": "public (): array",
        "getCustomAssignment": "public (string $experimentName): ?int",
        "getCustomAssignments": "public (): array",
        "getEventLogger": "public (): ?ABSmartly\\SDK\\Context\\ContextEventLogger",
        "getOverride": "public (string $experimentName): ?int",
        "getOverrides": "public (): array",
        "getPublishDelay": "public (): int",
        "getRefreshInterval": "public (): int",
        "getUnit": "public (string $unitType): ?string",
        "getUnits": "public (): array",
        "setAttribute": "public (string $name, $value): ABSmartly\\SDK\\Context\\ContextConfig",
        "setAttributes": "public (array $attributes): ABSmartly\\SDK\\Context\\ContextConfig",
        "setCustomAssignment": "public (string $experimentName, int $variant): ABSmartly\\SDK\\Context\\ContextConfig",
        "setCustomAssignments": "public (array $customAssignments): ABSmartly\\SDK\\Context\\ContextConfig",
        "setEventHandler": "public (ABSmartly\\SDK\\Context\\ContextEventHandler $eventHandler): ABSmartly\\SDK\\Context\\ContextConfig",
        "setEventLogger": "public (ABSmartly\\SDK\\Context\\ContextEventLogger $eventLogger): ABSmartly\\SDK\\Context\\ContextConfig",
        "setOverride": "public (string $experimentName, int $variant): ABSmartly\\SDK\\Context\\ContextConfig",
        "setOverrides": "public (array $overrides): ABSmartly\\SDK\\Context\\ContextConfig",
        "setPublishDelay": "public (int $publishDelay): ABSmartly\\SDK\\Context\\ContextConfig",
        "setRefreshInterval": "public (int $refreshInterval): ABSmartly\\SDK\\Context\\ContextConfig",
        "setUnit": "public (string $unitType, string $uid): ABSmartly\\SDK\\Context\\ContextConfig",
        "setUnits": "public (array $units): ABSmartly\\SDK\\Context\\ContextConfig"
    }
}
JSON
		,
		\ABSmartly\SDK\SDK::class => <<<'JSON'
{
    "class": "final ABSmartly\\SDK\\SDK",
    "properties": [],
    "methods": {
        "__construct": "public (ABSmartly\\SDK\\Config $config)",
        "close": "public (): void",
        "createContext": "public (ABSmartly\\SDK\\Context\\ContextConfig $contextConfig): ABSmartly\\SDK\\Context\\Context",
        "createContextAsync": "public (ABSmartly\\SDK\\Context\\ContextConfig $contextConfig): React\\Promise\\PromiseInterface",
        "createContextPending": "public (ABSmartly\\SDK\\Context\\ContextConfig $contextConfig): array",
        "createContextWithData": "public (ABSmartly\\SDK\\Context\\ContextConfig $contextConfig, ABSmartly\\SDK\\Context\\ContextData $contextData): ABSmartly\\SDK\\Context\\Context",
        "createSimple": "public static (string $endpoint, string $apiKey, string $application, string $environment, int $retries = 5, int $timeout = 3000, ?callable $eventLogger = null): ABSmartly\\SDK\\SDK",
        "createWithDefaults": "public static (string $endpoint, string $apiKey, string $environment, string $application, int $retries = 5, int $timeout = 3000, ?callable $eventLogger = null): ABSmartly\\SDK\\SDK",
        "getClient": "public (): ABSmartly\\SDK\\Client\\Client"
    }
}
JSON
		,
	];
}
