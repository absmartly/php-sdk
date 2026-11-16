<?php

namespace ABSmartly\SDK\Tests;

use ABSmartly\SDK\Exposure;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class ExposureTest extends TestCase {
	/**
	 * A customer subclass written against v1.0.3. An incompatible override is a fatal error at class load, so it is
	 * declared in a separate process (and outside tests/, which Composer scans).
	 */
	private const V103_SUBCLASS = <<<'PHP'
namespace Customer\App {
require AUTOLOAD;

class CustomerExposure extends \ABSmartly\SDK\Exposure {
	public static function create(
		int $id, string $name, ?string $unit, int $variant, float $exposedAt, bool $assigned, bool $eligible,
		bool $overridden, bool $fullOn, bool $custom, bool $audienceMismatch
	): \ABSmartly\SDK\Exposure {
		return parent::create($id, $name, $unit, $variant, $exposedAt, $assigned, $eligible, $overridden, $fullOn, $custom, $audienceMismatch);
	}
}

echo get_class(CustomerExposure::create(1, 'a', null, 0, 0.0, true, true, false, false, false, false));
}
PHP;

	public function testV103SubclassOverridingCreateStillLoads(): void {
		$script = str_replace('AUTOLOAD', var_export(dirname(__DIR__) . '/vendor/autoload.php', true), self::V103_SUBCLASS);

		$file = tempnam(sys_get_temp_dir(), 'exposure') . '.php';
		file_put_contents($file, "<?php\n" . $script);
		try {
			exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
		}
		finally {
			unlink($file);
		}

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
