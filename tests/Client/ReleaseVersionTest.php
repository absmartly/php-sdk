<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\JsonExpression\SemverComparator;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version Client sends in step with the CHANGELOG. Below an optional leading "Unreleased", every level-2
 * heading must be a version in the grammar check-release-version.php accepts, each newer by semver precedence than the
 * one below it, and the newest must equal Client::SDK_VERSION. This catches a release whose heading and version
 * disagree, a version bumped before its heading is, and a malformed, misplaced, downgraded or duplicated heading.
 * A release that renames neither still passes here; only .github/scripts/check-release-version.php catches that, run
 * before tagging and again by the release workflow. Released sections are also pinned by hash so they stay as shipped.
 */
class ReleaseVersionTest extends TestCase {
	private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D';

	/** sha256 of each released section, heading line included, with "\n" line endings. Add the new one on release. */
	private const RELEASED_SECTIONS = [
		'1.2.0' => 'e7bbb0ce3f724c7c636ba36928a16eb20e30b78475733ea1c6e97b1f518debba',
		'1.1.0' => '99de204d75f1213bb75af4ea8e1961604efe54a54da015006b68e571d29c60b2',
	];

	public function testChangelogHeadingsMatchTheSdkVersion(): void {
		self::assertNull(self::problem(self::changelog(), Client::SDK_VERSION));
	}

	public function testReleasedChangelogSectionsAreUnchanged(): void {
		$sections = self::sections(self::changelog());
		foreach ($sections as $heading => $text) {
			if ($heading === 'Unreleased') {
				continue;
			}
			self::assertArrayHasKey($heading, self::RELEASED_SECTIONS, "Released CHANGELOG section $heading has no recorded hash");
			self::assertSame(self::RELEASED_SECTIONS[$heading], hash('sha256', $text), "Released CHANGELOG section $heading was edited");
		}
		foreach (array_keys(self::RELEASED_SECTIONS) as $heading) {
			self::assertArrayHasKey($heading, $sections, "Released CHANGELOG section $heading is missing");
		}
	}

	/** @dataProvider acceptedChangelogs */
	public function testAccepts(string $changelog, string $version): void {
		self::assertNull(self::problem($changelog, $version));
	}

	/** @dataProvider rejectedChangelogs */
	public function testRejects(string $changelog, string $version): void {
		self::assertNotNull(self::problem($changelog, $version));
	}

	public function acceptedChangelogs(): array {
		return [
			'released' => ["## 1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'unreleased above the current version' => ["## Unreleased\n\n## 1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'unreleased before the bump' => ["## Unreleased\n\n## 1.1.0\n", '1.1.0'],
			'single release' => ["## 1.0.0\n", '1.0.0'],
			'crlf and trailing space' => ["## 1.2.0 \r\n\r\n## 1.1.0\r\n", '1.2.0'],
			'closing hashes' => ["## 1.2.0 ##\n\n## 1.1.0\n", '1.2.0'],
			'release above its pre-release' => ["## 1.3.0\n\n## 1.3.0-rc.1\n", '1.3.0'],
			'numeric pre-release' => ["## 1.3.0\n\n## 1.3.0-1\n", '1.3.0'],
			'pre-releases in precedence order' => ["## 1.3.0-rc.10\n\n## 1.3.0-rc.2\n\n## 1.3.0-beta\n\n## 1.3.0-1\n", '1.3.0-rc.10'],
			'backtick fence example' => ["## 1.2.0\n```\n## 9.9.9\n```\n## 1.1.0\n", '1.2.0'],
			'indented long tilde fence example' => ["## 1.2.0\n   ~~~~ text\n## 9.9.9\n~~~\n   ~~~~\n## 1.1.0\n", '1.2.0'],
			'html comment example' => ["## 1.2.0\n<!--\n## 9.9.9\n-->\n## 1.1.0\n", '1.2.0'],
			'level 1 and 3 headings ignored' => ["# Changelog\n\n## 1.2.0\n\n### Added\n\n## 1.1.0\n", '1.2.0'],
			'list items and thematic breaks' => ["## 1.2.0\n\n- one\n\n---\n\n## 1.1.0\n", '1.2.0'],
		];
	}

	public function rejectedChangelogs(): array {
		return [
			'no headings' => ["# Changelog\n", '1.2.0'],
			'only unreleased' => ["## Unreleased\n", '1.2.0'],
			'bumped while unreleased' => ["## Unreleased\n\n## 1.1.0\n", '1.2.0'],
			'forgotten bump' => ["## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'early bump' => ["## Unreleased\n\n## 1.2.0\n\n## 1.1.0\n", '1.3.0'],
			'v prefix' => ["## v1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'brackets' => ["## [1.2.0]\n\n## 1.1.0\n", '1.2.0'],
			'date suffix' => ["## 1.2.0 - 2026-11-26\n\n## 1.1.0\n", '1.2.0'],
			'two parts' => ["## 1.2\n\n## 1.1.0\n", '1.2'],
			'lowercase unreleased' => ["## unreleased\n\n## 1.1.0\n", '1.1.0'],
			'two unreleased' => ["## Unreleased\n\n## Unreleased\n\n## 1.1.0\n", '1.1.0'],
			'unreleased below a release' => ["## 1.2.0\n\n## Unreleased\n\n## 1.1.0\n", '1.2.0'],
			'non-version heading' => ["## 1.2.0\n\n## 1.1.0\n\n## Notes\n", '1.2.0'],
			'malformed but ordered heading deeper' => ["## 1.2.0\n\n## v1.1.0\n\n## 1.0\n", '1.2.0'],
			'downgrade at the top' => ["## 1.0.9\n\n## 1.1.0\n", '1.0.9'],
			'duplicate at the top' => ["## 1.1.0\n\n## 1.1.0\n", '1.1.0'],
			'downgrade deeper' => ["## 1.2.0\n\n## 1.1.0\n\n## 1.1.5\n", '1.2.0'],
			'duplicate deeper' => ["## 1.2.0\n\n## 1.1.0\n\n## 1.1.0\n", '1.2.0'],
			'numeric pre-release above its release' => ["## 1.3.0-1\n\n## 1.3.0\n", '1.3.0-1'],
			'named pre-release above its release' => ["## 1.3.0-preview.1\n\n## 1.3.0\n", '1.3.0-preview.1'],
			'pre-releases out of order' => ["## 1.3.0-rc.2\n\n## 1.3.0-rc.10\n", '1.3.0-rc.2'],
			'setext heading' => ["1.2.0\n-----\n\n## 1.1.0\n", '1.1.0'],
			'indented heading' => ["   ## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'heading after a closed fence' => ["## 1.2.0\n````\n```\n````\n## 1.2.5\n", '1.2.0'],
		];
	}

	private static function changelog(): string {
		return (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
	}

	/** Returns why the CHANGELOG disagrees with $version, or null when it agrees. */
	private static function problem(string $changelog, string $version): ?string {
		$headings = array_column(self::headingLines($changelog), 0);
		if (($headings[0] ?? null) === 'Unreleased') {
			array_shift($headings);
		}
		if ($headings === []) {
			return 'CHANGELOG has no released version heading';
		}

		foreach ($headings as $i => $heading) {
			if (preg_match(self::VERSION_PATTERN, $heading) !== 1) {
				return "CHANGELOG heading \"$heading\" is not a release version";
			}
			if ($i > 0 && SemverComparator::compare($headings[$i - 1], $heading) !== 1) {
				return "CHANGELOG heading {$headings[$i - 1]} is not newer than $heading";
			}
		}

		if ($headings[0] !== $version) {
			return "Client::SDK_VERSION $version differs from the newest released CHANGELOG heading {$headings[0]}";
		}
		return null;
	}

	/** @return array<string, string> each level-2 section's text, from its heading line to the next one */
	private static function sections(string $changelog): array {
		$lines = explode("\n", str_replace("\r\n", "\n", $changelog));
		$headings = self::headingLines($changelog);
		$sections = [];
		foreach ($headings as $i => [$heading, $start]) {
			$end = $headings[$i + 1][1] ?? count($lines);
			$sections[$heading] = implode("\n", array_slice($lines, $start, $end - $start));
		}
		return $sections;
	}

	/**
	 * Level-2 headings and their line numbers, in order. Lines inside fenced code blocks and HTML comments are
	 * examples, not headings, and a line underlined with dashes is a heading too (Markdown's setext form).
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	private static function headingLines(string $changelog): array {
		$lines = explode("\n", str_replace("\r\n", "\n", $changelog));
		$headings = [];
		$fence = null;
		$inComment = false;
		foreach ($lines as $n => $line) {
			if ($fence !== null) {
				if (preg_match('/^ {0,3}(' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',})[ \t]*$/', $line) === 1) {
					$fence = null;
				}
				continue;
			}
			if ($inComment) {
				$inComment = strpos($line, '-->') === false;
				continue;
			}
			if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
				$fence = $match[1];
				continue;
			}
			if (preg_match('/^ {0,3}<!--/', $line) === 1) {
				$inComment = strpos($line, '-->') === false;
				continue;
			}

			$heading = null;
			if (preg_match('/^ {0,3}##(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $match) === 1) {
				$heading = $match[1] ?? '';
			}
			elseif (preg_match('/^ {0,3}-+[ \t]*$/', $line) === 1 && $n > 0 && trim($lines[$n - 1]) !== ''
				&& preg_match('/^ {0,3}(#|[-*+][ \t]|\d+[.)][ \t]|>)/', $lines[$n - 1]) !== 1) {
				$heading = trim($lines[$n - 1]);
				$n--;
			}
			if ($heading !== null) {
				$headings[] = [$heading, $n];
			}
		}
		return $headings;
	}
}
