<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\JsonExpression\SemverComparator;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the version Client sends in step with the CHANGELOG. Below an optional leading "## Unreleased", every "## "
 * heading must be a version in the grammar check-release-version.php accepts, each newer by semver precedence than
 * the one below it, and the newest must equal Client::SDK_VERSION. This catches a release whose heading and version
 * disagree, a version bumped before its heading is, and a malformed, misplaced, downgraded or duplicated heading.
 * A release that renames neither still passes here; only .github/scripts/check-release-version.php catches that, run
 * before tagging and again by the release workflow.
 *
 * Released sections are pinned by hash so they stay as shipped. Headings are read as plain "## " lines, so anything
 * that could hide or fake one is rejected rather than parsed: code fences, HTML, lines of only "-" or "=" (underlined
 * headings and thematic breaks), indented "#" lines, "##" without a following space, a byte order mark and bare CR
 * line endings.
 */
class ReleaseVersionTest extends TestCase {
	/** Semver 2.0.0 without build metadata; check-release-version.php must use the same grammar. */
	private const VERSION = '(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
		. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?';

	/**
	 * sha256 of each released section: its lines, from the heading up to the next heading, joined by "\n".
	 * On release, add the new section's hash, which the failing test prints.
	 */
	private const RELEASED_SECTIONS = [
		'1.2.0' => 'e7bbb0ce3f724c7c636ba36928a16eb20e30b78475733ea1c6e97b1f518debba',
		'1.1.0' => '99de204d75f1213bb75af4ea8e1961604efe54a54da015006b68e571d29c60b2',
	];

	private const SCRIPT = '/.github/scripts/check-release-version.php';

	public function testChangelogHeadingsMatchTheSdkVersion(): void {
		self::assertNull(self::versionProblem(self::changelog(), Client::SDK_VERSION));
	}

	public function testReleasedChangelogSectionsAreUnchanged(): void {
		self::assertNull(self::sectionProblem(self::changelog(), self::RELEASED_SECTIONS));
	}

	public function testReleaseScriptUsesTheSameVersionGrammar(): void {
		$script = (string) file_get_contents(self::root() . self::SCRIPT);
		self::assertStringContainsString("'/^v(" . self::VERSION . ")\$/D'", $script);
	}

	/** @dataProvider releaseTags */
	public function testReleaseScript(string $tag, bool $accepted): void {
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . self::SCRIPT) . ' ' . escapeshellarg($tag) . ' 2>&1', $output, $status);
		self::assertSame($accepted ? 0 : 1, $status, implode("\n", $output));
		if ($accepted) {
			self::assertSame(['Release version ' . Client::SDK_VERSION . ' OK'], $output);
		}
	}

	public static function releaseTags(): array {
		$version = Client::SDK_VERSION;
		return [
			'current version' => ["v$version", true],
			'no v' => [$version, false],
			'capital V' => ["V$version", false],
			'trailing space' => ["v$version ", false],
			'trailing newline' => ["v$version\n", false],
			'other version' => ['v0.0.1', false],
			'pre-release of the current version' => ["v$version-rc.1", false],
			'build metadata' => ["v$version+build", false],
			'empty' => ['', false],
		];
	}

	/** @dataProvider acceptedChangelogs */
	public function testAcceptsChangelog(string $changelog, string $version): void {
		self::assertNull(self::versionProblem($changelog, $version));
	}

	/** @dataProvider rejectedChangelogs */
	public function testRejectsChangelog(string $changelog, string $version): void {
		self::assertNotNull(self::versionProblem($changelog, $version));
	}

	public static function acceptedChangelogs(): array {
		return [
			'released' => ["# Changelog\n\n## 1.2.0\n\n### Added\n\n- one\n\n## 1.1.0\n", '1.2.0'],
			'unreleased above the current version' => ["## Unreleased\n\n## 1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'unreleased before the bump' => ["## Unreleased\n\n## 1.1.0\n", '1.1.0'],
			'single release' => ["## 1.0.0\n", '1.0.0'],
			'crlf and trailing space' => ["## 1.2.0 \r\n\r\n## 1.1.0\r\n", '1.2.0'],
			'release above its pre-release' => ["## 1.3.0\n\n## 1.3.0-rc.1\n", '1.3.0'],
			'pre-releases in precedence order' => ["## 1.3.0-rc.10\n\n## 1.3.0-rc.2\n\n## 1.3.0-beta\n\n## 1.3.0-1\n", '1.3.0-rc.10'],
			'zero components' => ["## 1.0.0-0\n\n## 0.10.0\n\n## 0.9.0\n", '1.0.0-0'],
		];
	}

	public static function rejectedChangelogs(): array {
		return [
			'no headings' => ["# Changelog\n", '1.2.0'],
			'only unreleased' => ["## Unreleased\n", '1.2.0'],
			'bumped while unreleased' => ["## Unreleased\n\n## 1.1.0\n", '1.2.0'],
			'forgotten bump' => ["## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'early bump' => ["## Unreleased\n\n## 1.2.0\n\n## 1.1.0\n", '1.3.0'],
			'v prefix' => ["## v1.2.0\n\n## 1.1.0\n", 'v1.2.0'],
			'brackets' => ["## [1.2.0]\n\n## 1.1.0\n", '[1.2.0]'],
			'date suffix' => ["## 1.2.0 - 2026-11-26\n\n## 1.1.0\n", '1.2.0 - 2026-11-26'],
			'closing hashes' => ["## 1.2.0 ##\n\n## 1.1.0\n", '1.2.0 ##'],
			'two parts' => ["## 1.2\n\n## 1.1.0\n", '1.2'],
			'leading zero' => ["## 01.2.0\n\n## 1.1.0\n", '01.2.0'],
			'empty pre-release identifier' => ["## 1.2.0-rc..1\n\n## 1.1.0\n", '1.2.0-rc..1'],
			'build metadata' => ["## 1.2.0+build\n\n## 1.1.0\n", '1.2.0+build'],
			'lowercase unreleased' => ["## unreleased\n\n## 1.1.0\n", '1.1.0'],
			'two unreleased' => ["## Unreleased\n\n## Unreleased\n\n## 1.1.0\n", '1.1.0'],
			'unreleased below a release' => ["## 1.2.0\n\n## Unreleased\n\n## 1.1.0\n", '1.2.0'],
			'non-version heading deeper' => ["## 1.2.0\n\n## 1.1.0\n\n## Notes\n", '1.2.0'],
			'malformed but ordered heading deeper' => ["## 1.2.0\n\n## v1.1.0\n\n## 1.0.0\n", '1.2.0'],
			'downgrade at the top' => ["## 1.0.9\n\n## 1.1.0\n", '1.0.9'],
			'duplicate at the top' => ["## 1.1.0\n\n## 1.1.0\n", '1.1.0'],
			'downgrade deeper' => ["## 1.2.0\n\n## 1.1.0\n\n## 1.1.5\n", '1.2.0'],
			'duplicate deeper' => ["## 1.2.0\n\n## 1.1.0\n\n## 1.1.0\n", '1.2.0'],
			'numeric pre-release above its release' => ["## 1.3.0-1\n\n## 1.3.0\n", '1.3.0-1'],
			'named pre-release above its release' => ["## 1.3.0-preview.1\n\n## 1.3.0\n", '1.3.0-preview.1'],
			'pre-releases out of order' => ["## 1.3.0-rc.2\n\n## 1.3.0-rc.10\n", '1.3.0-rc.2'],
			'backtick fence' => ["## 1.2.0\n```\n## 9.9.9\n```\n## 1.1.0\n", '1.2.0'],
			'tilde fence' => ["## 1.2.0\n  ~~~\ntext\n~~~\n## 1.1.0\n", '1.2.0'],
			'html comment' => ["## 1.2.0\n<!-- note -->\n## 1.1.0\n", '1.2.0'],
			'html block' => ["## 1.2.0\n<details>\n\n## 9.9.9\n\n</details>\n## 1.1.0\n", '1.2.0'],
			'underlined heading' => ["1.2.0\n-----\n\n## 1.1.0\n", '1.1.0'],
			'equals-underlined heading' => ["1.2.0\n=====\n\n## 1.1.0\n", '1.1.0'],
			'thematic break' => ["## 1.2.0\n\n---\n\n## 1.1.0\n", '1.2.0'],
			'indented heading' => ["## 1.2.0\n\n   ## 1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'heading without a space' => ["## 1.2.0\n\n##1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'byte order mark hiding the newest heading' => ["\u{FEFF}## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'cr line ending hiding the newest heading' => ["Notes\r## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
		];
	}

	/** @dataProvider sectionCases */
	public function testSectionCheck(string $changelog, bool $accepted): void {
		$hashes = ['1.1.0' => hash('sha256', "## 1.1.0\n\n- one\n")];
		$problem = self::sectionProblem($changelog, $hashes);
		if ($accepted) {
			self::assertNull($problem);
		}
		else {
			self::assertNotNull($problem);
		}
	}

	public static function sectionCases(): array {
		return [
			'as released' => ["## Unreleased\n\n- new\n## 1.1.0\n\n- one\n", true],
			'crlf' => ["## Unreleased\r\n## 1.1.0\r\n\r\n- one\r\n", true],
			'edited' => ["## 1.1.0\n\n- One\n", false],
			'trailing space' => ["## 1.1.0\n\n- one \n", false],
			'missing final newline' => ["## 1.1.0\n\n- one", false],
			'missing' => ["## Unreleased\n\n- new\n", false],
			'released section without a hash' => ["## 1.2.0\n\n- two\n## 1.1.0\n\n- one\n", false],
		];
	}

	public function testMissingHashNamesTheHashToRecord(): void {
		$problem = self::sectionProblem("## 1.2.0\n\n- two\n## 1.1.0\n", ['1.1.0' => hash('sha256', "## 1.1.0\n")]);
		self::assertStringContainsString(hash('sha256', "## 1.2.0\n\n- two"), (string) $problem);
	}

	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	private static function changelog(): string {
		return (string) file_get_contents(self::root() . '/CHANGELOG.md');
	}

	/** Returns why the CHANGELOG disagrees with $version, or null when it agrees. */
	private static function versionProblem(string $changelog, string $version): ?string {
		$sections = self::sections($changelog);
		if (is_string($sections)) {
			return $sections;
		}

		$headings = array_column($sections, 0);
		if (($headings[0] ?? null) === 'Unreleased') {
			array_shift($headings);
		}
		if ($headings === []) {
			return 'CHANGELOG has no released version heading';
		}

		foreach ($headings as $i => $heading) {
			if (preg_match('/^' . self::VERSION . '$/D', $heading) !== 1) {
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

	/**
	 * Returns why a released section differs from its recorded hash, or null when all match.
	 *
	 * @param array<string, string> $hashes
	 */
	private static function sectionProblem(string $changelog, array $hashes): ?string {
		$sections = self::sections($changelog);
		if (is_string($sections)) {
			return $sections;
		}

		$found = [];
		foreach ($sections as [$heading, $text]) {
			if ($heading === 'Unreleased') {
				continue;
			}
			$hash = hash('sha256', $text);
			if (!isset($hashes[$heading])) {
				return "Released CHANGELOG section $heading has no recorded hash; record '$heading' => '$hash'";
			}
			if ($hashes[$heading] !== $hash) {
				return "Released CHANGELOG section $heading was edited";
			}
			$found[$heading] = true;
		}

		foreach (array_keys($hashes) as $heading) {
			if (!isset($found[$heading])) {
				return "Released CHANGELOG section $heading is missing";
			}
		}
		return null;
	}

	/**
	 * Each "## " section as [trimmed heading, text from the heading line up to the next one], or a string saying why
	 * the CHANGELOG cannot be read that way.
	 *
	 * @return array<int, array{0: string, 1: string}>|string
	 */
	private static function sections(string $changelog) {
		if (strncmp($changelog, "\u{FEFF}", 3) === 0) {
			return 'CHANGELOG starts with a byte order mark';
		}
		$changelog = str_replace("\r\n", "\n", $changelog);
		if (strpos($changelog, "\r") !== false) {
			return 'CHANGELOG has CR line endings';
		}

		$lines = explode("\n", $changelog);
		foreach ($lines as $n => $line) {
			if (preg_match('/^(\s*(```|~~~|<)| {0,3}((-[ \t]*)+|=+[ \t]*)$|\s+#|##[^ #\n]|##$)/', $line) === 1) {
				return 'CHANGELOG line ' . ($n + 1) . " uses Markdown this check does not read: \"$line\"";
			}
		}

		$sections = [];
		foreach ($lines as $n => $line) {
			if (strncmp($line, '## ', 3) === 0) {
				$sections[] = [trim(substr($line, 3)), $n];
			}
		}
		foreach ($sections as $i => [$heading, $start]) {
			$end = $sections[$i + 1][1] ?? count($lines);
			$sections[$i] = [$heading, implode("\n", array_slice($lines, $start, $end - $start))];
		}
		return $sections;
	}
}
