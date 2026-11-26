<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version Client sends in step with the CHANGELOG: it must equal the newest released heading, i.e. the top
 * heading, or the one below it while the top is "Unreleased", and that heading must be newer than the one below it.
 * This catches a release whose heading and version disagree, a version bumped before its heading is, and a downgraded
 * or duplicated heading. A release that renames neither still passes here; only .github/scripts/check-release-version.php
 * catches that, run before tagging and again by the release workflow.
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
		self::assertSame($headings[0], Client::SDK_VERSION, 'Client::SDK_VERSION differs from the newest released CHANGELOG heading');
		if (isset($headings[1])) {
			self::assertTrue(version_compare($headings[0], $headings[1], '>'), "CHANGELOG heading $headings[0] is not newer than $headings[1]");
		}
	}
}
