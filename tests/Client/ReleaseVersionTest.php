<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use PHPUnit\Framework\TestCase;

/**
 * The release check runs after a tag is pushed, when Packagist has already imported it, so a forgotten version
 * bump is caught here instead: the top CHANGELOG heading is either "Unreleased" or the version Client sends.
 */
class ReleaseVersionTest extends TestCase {
	public function testTopChangelogHeadingMatchesTheSdkVersion(): void {
		$changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
		self::assertSame(1, preg_match('/^## (.+)$/m', $changelog, $match));

		$heading = trim($match[1]);
		if ($heading !== 'Unreleased') {
			self::assertSame(Client::SDK_VERSION, $heading);
		}
	}
}
