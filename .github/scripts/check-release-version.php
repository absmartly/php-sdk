<?php

// Checks that a release tag matches the SDK version sent in the X-Agent header. Packagist imports a tag as soon as it
// is pushed, so run this before pushing the tag; the Release workflow re-runs it but cannot unpublish a bad tag.
// Usage: php .github/scripts/check-release-version.php vX.Y.Z

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$tag = $argv[1] ?? '';
// Semver 2.0.0 without build metadata, limited to pre-releases Composer imports and orders as semver does; the
// CHANGELOG headings are checked with the same grammar by tests/Client/ReleaseVersionTest.php.
if (preg_match('/^v((?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})(?:-(?:alpha|beta|rc)(?:\.(?:0|[1-9]\d{0,4}))?)?)$/D', $tag, $match) !== 1) {
	fwrite(STDERR, 'Release tag "' . addcslashes($tag, "\0..\37\177\\\"") . '" is not a release version: vMAJOR.MINOR.PATCH,'
		. " optionally followed by -alpha, -beta or -rc and an optional .number (as in v1.3.0-rc.1), with numbers of at"
		. " most five digits, no leading zeros and no build metadata\n");
	exit(1);
}

$version = \ABSmartly\SDK\Client\Client::SDK_VERSION;
if ($match[1] !== $version) {
	fwrite(STDERR, "Release tag $tag does not match Client::SDK_VERSION $version; bump it before tagging\n");
	exit(1);
}

echo "Release version $version OK\n";
