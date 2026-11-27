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
 * Released sections are pinned by hash so they stay as shipped. Headings are read as plain "## " lines, so Markdown
 * that could hide or fake a level-2 heading is rejected rather than parsed: code fences, HTML tags and comments, lines
 * of only "-" or "=" (underlined headings and thematic breaks), indented "#" headings, "##" not followed by a space,
 * a byte order mark and bare CR line endings.
 *
 * Pre-releases are limited to a lowercase alpha, beta or rc with an optional ".number" (as in 1.3.0-rc.1), and each
 * number to five digits. Composer, and so Packagist, rejects other semver pre-releases such as 1.3.0-1 or
 * 1.3.0-preview.1, reads a major version of more than five digits as a date (or not at all), and orders forms like
 * 1.3.0-beta2 or 1.3.0-RC.1 differently from semver.
 */
class ReleaseVersionTest extends TestCase {
	/** Semver 2.0.0 without build metadata, pre-releases as above; check-release-version.php uses it too. */
	private const VERSION = '(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})(?:-(?:alpha|beta|rc)(?:\.(?:0|[1-9]\d{0,4}))?)?';

	/** Lines that could hide or fake a "## " heading, each with the reason it is rejected. */
	private const UNREAD_MARKDOWN = [
		'/^\s*(```|~~~)/' => 'a code fence',
		'/^\s*<(!--|\/?[A-Za-z][A-Za-z0-9-]*(\s|\/?>|$))/' => 'an HTML tag or comment',
		'/^ {0,3}((-[ \t]*)+|(=[ \t]*)+)$/' => 'a line of only "-" or "=", which underlines a heading or breaks a section',
		'/^\s+#{1,6}(\s|$)/' => 'an indented heading',
		'/^##([^ #]|$)/' => 'a "##" not followed by a space',
	];

	/**
	 * sha256 of each released section: its lines, from the heading up to the next heading, joined by "\n".
	 * On release, add the new section's hash, which the failing test prints.
	 */
	private const RELEASED_SECTIONS = [
		'1.3.0' => '286f09a083af5311653cc60db8408104ce8b040ed05738c98239275ca5bf5dd2',
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
		self::assertSame("'/^v(" . self::VERSION . ")\$/D'", self::tagPattern($script));
		// A Windows checkout may have CRLF line endings, whichever this one has.
		$crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $script));
		self::assertSame("'/^v(" . self::VERSION . ")\$/D'", self::tagPattern($crlf));
	}

	public function testTagPatternReadsTheOneLiveCheck(): void {
		$check = "if (preg_match('/^v(1)\$/D', \$tag, \$match) !== 1) {";
		self::assertSame("'/^v(1)\$/D'", self::tagPattern("<?php\n// $check\n$check\n"));
		self::assertNull(self::tagPattern("<?php\n// $check\n"));
		self::assertNull(self::tagPattern("<?php\n$check\n$check\n"));
		self::assertNull(self::tagPattern("<?php\n"));
	}

	public function testOtherPreReleaseTagDiffersFromAPreReleaseVersion(): void {
		self::assertSame('v1.3.0-rc.99999', self::otherPreReleaseTag('1.3.0-rc.1'));
		self::assertSame('v1.3.0-rc.99999', self::otherPreReleaseTag('1.3.0'));
		self::assertSame('v1.3.0-rc.99998', self::otherPreReleaseTag('1.3.0-rc.99999'));
	}

	public function testReleaseScriptEscapesTheTagItReports(): void {
		self::assertStringContainsString('Release tag "v1\\"2\\\\\\t" is not', self::runScript("v1\"2\\\t")[2]);
	}

	/** @dataProvider releaseTags */
	public function testReleaseScript(string $tag, string $expected): void {
		[$status, $stdout, $stderr] = self::runScript($tag);

		if ($expected === 'ok') {
			self::assertSame(0, $status, $stderr);
			self::assertSame('Release version ' . Client::SDK_VERSION . " OK\n", $stdout);
		}
		else {
			self::assertSame(1, $status, $stdout);
			self::assertStringContainsString($expected === 'format'
				? 'is not a release version: vMAJOR.MINOR.PATCH, optionally followed by -alpha, -beta or -rc and an optional'
					. ' .number (as in v1.3.0-rc.1), with numbers of at most five digits, no leading zeros and no build metadata'
				: 'does not match Client::SDK_VERSION ' . Client::SDK_VERSION . '; bump it before tagging', $stderr);
			// The tag is escaped, so the message is one line of printable text.
			self::assertSame(1, substr_count($stderr, "\n"), $stderr);
			self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F\x7F]/', $stderr);
		}
	}

	public static function releaseTags(): array {
		$version = Client::SDK_VERSION;
		return [
			'current version' => ["v$version", 'ok'],
			'other version' => ['v0.0.1', 'mismatch'],
			'pre-release of the current version' => [self::otherPreReleaseTag($version), 'mismatch'],
			'no v' => [$version, 'format'],
			'capital V' => ["V$version", 'format'],
			'trailing space' => ["v$version ", 'format'],
			'trailing newline' => ["v$version\n", 'format'],
			'leading zero' => ['v01.2.0', 'format'],
			'six-digit major' => ['v100000.0.0', 'format'],
			'six-digit minor' => ['v1.100000.0', 'format'],
			'six-digit patch' => ['v1.0.100000', 'format'],
			'six-digit pre-release number' => ['v1.2.0-rc.100000', 'format'],
			'control character' => ["v$version\x07", 'format'],
			'delete character' => ["v$version\x7F", 'format'],
			'build metadata' => ["v$version+build", 'format'],
			'pre-release Composer cannot read' => ["v$version-preview.1", 'format'],
			'undotted pre-release number' => ["v$version-rc1", 'format'],
			'empty' => ['', 'format'],
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
			'pre-releases in precedence order' => ["## 1.3.0-rc.10\n\n## 1.3.0-rc.2\n\n## 1.3.0-rc\n\n## 1.3.0-beta.1\n\n## 1.3.0-beta\n\n## 1.3.0-alpha.0\n", '1.3.0-rc.10'],
			'zero components' => ["## 1.0.0-alpha.0\n\n## 0.10.0\n\n## 0.9.0\n", '1.0.0-alpha.0'],
			'five-digit numbers' => ["## 99999.99999.99999-rc.99999\n\n## 1.1.0\n", '99999.99999.99999-rc.99999'],
			'two spaces after the hashes' => ["##  1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'wrapped bullet starting with an issue number' => ["## 1.2.0\n\n- Fixed a crash\n  #42 in refresh.\n\n## 1.1.0\n", '1.2.0'],
			'autolinks' => ["## 1.2.0\n\n- See\n  <https://example.com>\n<https://example.com>\n\n## 1.1.0\n", '1.2.0'],
			'long CHANGELOG' => [self::longChangelog(''), '1.2.0'],
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
			'six-digit number' => ["## 100000.0.0\n\n## 1.1.0\n", '100000.0.0'],
			'version spelled differently' => ["## 1.2.0\n\n## 1.1.0\n", 'v1.2.0'],
			'leading zero in a pre-release' => ["## 1.2.0-rc.01\n\n## 1.1.0\n", '1.2.0-rc.01'],
			'empty pre-release identifier' => ["## 1.2.0-rc..1\n\n## 1.1.0\n", '1.2.0-rc..1'],
			'pre-release Composer cannot read' => ["## 1.2.0-preview.1\n\n## 1.1.0\n", '1.2.0-preview.1'],
			'undotted pre-release number' => ["## 1.2.0-beta2\n\n## 1.1.0\n", '1.2.0-beta2'],
			'uppercase pre-release' => ["## 1.2.0-RC.1\n\n## 1.1.0\n", '1.2.0-RC.1'],
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
			'pre-release above its release' => ["## 1.3.0-rc.1\n\n## 1.3.0\n", '1.3.0-rc.1'],
			'pre-releases out of order' => ["## 1.3.0-rc.2\n\n## 1.3.0-rc.10\n", '1.3.0-rc.2'],
			'backtick fence' => ["## 1.2.0\n```\n## 1.1.5\n```\n## 1.1.0\n", '1.2.0'],
			'indented backtick fence' => ["## 1.2.0\n  ```\ncode\n  ```\n## 1.1.0\n", '1.2.0'],
			'tilde fence' => ["## 1.2.0\n  ~~~\ntext\n~~~\n## 1.1.0\n", '1.2.0'],
			'fence at the end of a long CHANGELOG' => [self::longChangelog("```\n"), '1.2.0'],
			'html comment' => ["## 1.2.0\n<!-- note -->\n## 1.1.0\n", '1.2.0'],
			'html block' => ["## 1.2.0\n<details>\n\n## 1.1.5\n\n</details>\n## 1.1.0\n", '1.2.0'],
			'html closing tag' => ["## 1.2.0\n\n## 1.1.5\n</details>\n## 1.1.0\n", '1.2.0'],
			'underlined heading' => ["1.2.0\n-----\n\n## 1.1.0\n", '1.1.0'],
			'indented underline' => ["1.2.0\n   ---\n\n## 1.1.0\n", '1.1.0'],
			'equals-underlined heading' => ["1.2.0\n=====\n\n## 1.1.0\n", '1.1.0'],
			'equals underline with a trailing space' => ["1.2.0\n=== \n\n## 1.1.0\n", '1.1.0'],
			'thematic break' => ["## 1.2.0\n\n---\n\n## 1.1.0\n", '1.2.0'],
			'spaced thematic break' => ["## 1.2.0\n\n- - -\n\n## 1.1.0\n", '1.2.0'],
			'indented heading' => ["## 1.2.0\n\n   ## 1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'heading without a space' => ["## 1.2.0\n\n##1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'bare hashes' => ["## 1.2.0\n\n##\n\n## 1.1.0\n", '1.2.0'],
			'no-break space after the hashes' => ["## 1.2.0\n\n##\u{A0}1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'byte order mark hiding the newest heading' => ["\u{FEFF}## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'byte order mark before the newest heading' => ["\u{FEFF}## 1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'cr line ending hiding the newest heading' => ["Notes\r## 1.2.0\n\n## 1.1.0\n", '1.1.0'],
			'cr line ending before the newest heading' => ["Notes\r## 1.2.0\n\n## 1.1.0\n", '1.2.0'],
			'indented h3' => ["## 1.2.0\n\n  ### 1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'tab-indented heading' => ["## 1.2.0\n\n\t## 1.1.5\n\n## 1.1.0\n", '1.2.0'],
			'uppercase html block' => ["## 1.2.0\n<DETAILS>\n\n## 1.1.5\n\n## 1.1.0\n", '1.2.0'],
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
			'lowercase unreleased' => ["## unreleased\n## 1.1.0\n\n- one\n", false],
		];
	}

	public function testMissingHashNamesTheHashToRecord(): void {
		$problem = self::sectionProblem("## 1.2.0\n\n- two\n## 1.1.0\n", ['1.1.0' => hash('sha256', "## 1.1.0\n")]);
		self::assertStringContainsString(hash('sha256', "## 1.2.0\n\n- two"), (string) $problem);
	}

	/** The script's tag pattern, as written in its source with LF or CRLF line endings, or null unless there is one. */
	private static function tagPattern(string $script): ?string {
		$script = str_replace("\r\n", "\n", $script);
		if (preg_match_all('/^if \(preg_match\((\'.*\'), \$tag, \$match\) !== 1\) \{$/m', $script, $matches) !== 1) {
			return null;
		}
		return $matches[1][0];
	}

	/** A valid tag for a pre-release of $version's release that is not $version itself. */
	private static function otherPreReleaseTag(string $version): string {
		$release = explode('-', $version)[0];
		return $version === "$release-rc.99999" ? "v$release-rc.99998" : "v$release-rc.99999";
	}

	/**
	 * Runs the release script with $tag. An argument list runs PHP without a shell, so the tag reaches the script
	 * unchanged on Windows too.
	 *
	 * @return array{0: int, 1: string, 2: string} exit status, stdout and stderr
	 */
	private static function runScript(string $tag): array {
		$process = proc_open([PHP_BINARY, self::root() . self::SCRIPT, $tag], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process);
		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $stdout, $stderr];
	}

	/** A released CHANGELOG with 300 lines of notes and then $tail. */
	private static function longChangelog(string $tail): string {
		return "## 1.2.0\n\n" . str_repeat("- note\n", 300) . "\n## 1.1.0\n" . $tail;
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
			foreach (self::UNREAD_MARKDOWN as $pattern => $what) {
				if (preg_match($pattern, $line) === 1) {
					return 'CHANGELOG line ' . ($n + 1) . " is $what, which this check does not read: \"$line\"";
				}
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
