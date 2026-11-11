<?php

namespace ABSmartly\SDK\Tests\Context;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\SDK;
use ABSmartly\SDK\Tests\Mocks\ContextDataProviderMock;
use ABSmartly\SDK\Tests\Mocks\ContextEventHandlerMock;
use PHPUnit\Framework\TestCase;

/**
 * Covers the §4 behaviours of the "SDKs must evaluate audiences with application + environment"
 * brief: the client's application and environment names are injected into the attribute map that
 * audience filters read, user-set attributes overlay them (including after the context is created,
 * invalidating a cached assignment), empty config values are skipped, nothing new is published, and
 * app_version is gone.
 */
class ContextSystemAttributesTest extends TestCase {

	private array $units = [
		'session_id' => 'e791e240fcd3df7d238cfc285f475e8152fcc0ec',
		'user_id' => '123456789',
	];

	private ContextDataProviderMock $dataProvider;
	private ContextEventHandlerMock $eventHandler;

	/**
	 * Builds a ready context whose client config carries explicit application and environment names,
	 * mirroring a real deployment configured for application "website" in environment "production".
	 */
	private function createReadyContext(
		string $source = 'system_attributes_context.json',
		string $application = 'website',
		string $environment = 'production'
	): Context {
		$clientConfig = new ClientConfig('https://demo.absmartly.io/v1', '', $application, $environment);
		$client = new Client($clientConfig);
		$config = new Config($client);

		$this->dataProvider = new ContextDataProviderMock($client);
		$this->dataProvider->setSource($source);
		$config->setContextDataProvider($this->dataProvider);

		$this->eventHandler = new ContextEventHandlerMock($client);
		$config->setContextEventHandler($this->eventHandler);

		$contextConfig = new ContextConfig();
		$contextConfig->setUnits($this->units);

		return (new SDK($config))->createContext($contextConfig);
	}

	// §4.1 Strict audience on the environment matches.
	public function testStrictAudienceOnEnvironmentMatches(): void {
		$context = $this->createReadyContext();

		self::assertSame(1, $context->getTreatment('exp_environment_match'));

		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertSame('exp_environment_match', $event->exposures[0]->name);
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	// §4.2 Strict audience on the application matches.
	public function testStrictAudienceOnApplicationMatches(): void {
		$context = $this->createReadyContext();

		self::assertSame(1, $context->getTreatment('exp_application_match'));

		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertFalse($event->exposures[0]->audienceMismatch);
	}

	// §4.3 No match: an environment filter for "staging" does not match in "production".
	public function testStrictAudienceNoMatchReturnsControlVariant(): void {
		$context = $this->createReadyContext();

		self::assertSame(0, $context->getTreatment('exp_environment_staging'));

		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	// §4.4 A user-set value overrides the system value: setting environment=staging before any
	// assignment makes the staging filter match and the production filter mismatch.
	public function testUserAttributeOverridesSystemValue(): void {
		$context = $this->createReadyContext();
		$context->setAttribute('environment', 'staging');

		self::assertSame(1, $context->getTreatment('exp_environment_staging'));
		self::assertSame(0, $context->getTreatment('exp_environment_match'));
	}

	// §4.4 Re-evaluation path: an assignment cached before the attribute is set is invalidated
	// once the user overrides the value.
	public function testCachedAssignmentInvalidatedWhenAttributeSetLater(): void {
		$context = $this->createReadyContext();

		// Cache both assignments while the system value (production) still applies:
		// the production filter matches, the staging filter mismatches.
		self::assertSame(1, $context->peekTreatment('exp_environment_match'));
		self::assertSame(0, $context->peekTreatment('exp_environment_staging'));

		// Override after the fact: the cached assignments must be invalidated and recomputed.
		$context->setAttribute('environment', 'staging');

		// The production filter now mismatches (strict -> control variant).
		self::assertSame(0, $context->peekTreatment('exp_environment_match'));
		// The staging filter now matches.
		self::assertSame(1, $context->peekTreatment('exp_environment_staging'));
	}

	// §4.6 Missing config: with no environment configured, the key is absent from the map, there is
	// no crash, and a filter on environment does not match.
	public function testMissingEnvironmentConfigLeavesKeyOut(): void {
		$context = $this->createReadyContext('system_attributes_context.json', 'website', '');

		self::assertSame(0, $context->getTreatment('exp_environment_match'));

		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}

	// §4.7 Nothing extra published: the serialized payload carries no application/environment/app_version
	// attribute entries injected by the SDK.
	public function testNoSystemAttributesPublished(): void {
		$context = $this->createReadyContext();

		self::assertSame(1, $context->getTreatment('exp_environment_match'));
		$context->publish();

		$event = $this->eventHandler->submitted[0];
		$attributeNames = [];
		if (!empty($event->attributes)) {
			foreach ($event->attributes as $attribute) {
				$attributeNames[] = $attribute->name;
			}
		}

		self::assertNotContains('application', $attributeNames);
		self::assertNotContains('environment', $attributeNames);
		self::assertNotContains('app_version', $attributeNames);
	}

	// §4.7 A user-set attribute is still published; only the injected system values are withheld.
	public function testUserAttributesStillPublished(): void {
		$context = $this->createReadyContext();
		$context->setAttribute('age', '21');

		self::assertSame(1, $context->getTreatment('exp_environment_match'));
		$context->publish();

		$event = $this->eventHandler->submitted[0];
		$attributeNames = [];
		foreach ($event->attributes as $attribute) {
			$attributeNames[] = $attribute->name;
		}

		self::assertSame(['age'], $attributeNames);
	}

	// §4.8 app_version is gone: a filter on app_version does not match even though the SDK configures
	// an application version header internally.
	public function testAppVersionFilterDoesNotMatch(): void {
		$context = $this->createReadyContext();

		self::assertSame(0, $context->getTreatment('exp_app_version'));

		$context->publish();
		$event = $this->eventHandler->submitted[0];
		self::assertTrue($event->exposures[0]->audienceMismatch);
	}
}
