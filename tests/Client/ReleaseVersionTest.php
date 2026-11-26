<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version Client sends in step with the CHANGELOG: it must equal the newest released heading, i.e. the top
 * heading, or the one below it while the top is "Unreleased". This catches a release whose heading and version
 * disagree, and a version bumped before its heading is. A release that renames neither still passes here; only the
 * tag check in the release workflow catches that.
 */
class ReleaseVersionTest extends TestCase {
	public function testSdkVersionMatchesTheNewestReleasedChangelogHeading(): void {
		$changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
		self::assertGreaterThan(0, preg_match_all('/^## (.+)$/m', $changelog, $matches));

		$headings = array_map('trim', $matches[1]);
		if ($headings[0] === 'Unreleased') {
			array_shift($headings);
		}

		self::assertNotEmpty($headings, 'CHANGELOG has no released version heading');
		self::assertSame($headings[0], Client::SDK_VERSION);
	}
}
