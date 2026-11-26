<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version Client sends in step with the CHANGELOG. Below an optional leading "Unreleased", every heading
 * must be a version in the grammar check-release-version.php accepts, each newer than the one below it, and the
 * newest must equal Client::SDK_VERSION. This catches a release whose heading and version disagree, a version bumped
 * before its heading is, and a malformed, downgraded or duplicated heading. A release that renames neither still
 * passes here; only .github/scripts/check-release-version.php catches that, run before tagging and again by the
 * release workflow.
 */
class ReleaseVersionTest extends TestCase {
	private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D';

	public function testSdkVersionMatchesTheNewestReleasedChangelogHeading(): void {
		$changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
		// A "## " line inside a fenced code block is an example, not a heading.
		$changelog = (string) preg_replace('/^```.*?^```[^\n]*$/ms', '', $changelog);
		self::assertGreaterThan(0, preg_match_all('/^## (.+)$/m', $changelog, $matches), 'CHANGELOG has no "## " headings');

		$headings = array_map('trim', $matches[1]);
		if ($headings[0] === 'Unreleased') {
			array_shift($headings);
		}

		self::assertNotEmpty($headings, 'CHANGELOG has no released version heading');
		foreach ($headings as $i => $heading) {
			self::assertMatchesRegularExpression(self::VERSION_PATTERN, $heading, 'CHANGELOG heading is not a release version');
			if ($i > 0) {
				self::assertTrue(version_compare($headings[$i - 1], $heading, '>'), "CHANGELOG heading {$headings[$i - 1]} is not newer than $heading");
			}
		}
		// The CHANGELOG declares the release, so its heading is the expected value.
		self::assertSame($headings[0], Client::SDK_VERSION, 'Client::SDK_VERSION differs from the newest released CHANGELOG heading');
	}
}
