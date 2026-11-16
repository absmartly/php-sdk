<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\Exposure;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;

class ExposureTest extends TestCase {
	// Declaring an incompatible override is a compile-time fatal, so the subclass is loaded in a separate process.
	public function testV103SubclassOverridingCreateStillLoads(): void {
		$script = sprintf(
			'require %s; require %s; echo get_class(\Customer\App\CustomerExposure::create(1, "a", null, 0, 0.0, true, true, false, false, false, false));',
			var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
			var_export(__DIR__ . '/Fixtures/Legacy/CustomerExposure.php.inc', true)
		);

		exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

		self::assertSame(0, $exitCode, implode("\n", $output));
		self::assertSame(['ABSmartly\SDK\Exposure'], $output);
	}

	public function testCreateKeepsTheV103Signature(): void {
		$method = new \ReflectionMethod(Exposure::class, 'create');

		self::assertSame(11, $method->getNumberOfParameters());
	}

	public function testCreatedExposureSerializesRuleOverrideFalse(): void {
		$exposure = Exposure::create(1, 'a', 'session_id', 1, 0.0, true, true, false, false, false, false);

		self::assertFalse(json_decode(json_encode($exposure), true)['ruleOverride']);
	}
}
