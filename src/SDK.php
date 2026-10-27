<?php

namespace ABSmartly\SDK;

/**
 * @deprecated Use ABsmartly instead. This class will be removed in a future version.
 */
final class SDK extends ABsmartly {
	/**
	 * @deprecated Use ABsmartly::createSimple() instead.
	 */
	public static function createWithDefaults(
		string $endpoint,
		string $apiKey,
		string $environment,
		string $application,
		int $retries = 5,
		int $timeout = 3000,
		?callable $eventLogger = null
	): SDK {
		return new SDK(static::buildConfig($endpoint, $apiKey, $application, $environment, $retries, $timeout, $eventLogger));
	}
}
