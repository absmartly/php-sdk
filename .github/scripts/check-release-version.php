<?php

// Checks that a release tag matches the SDK version sent in the X-Agent header, so Packagist never publishes a
// release that reports a different version. Usage: php .github/scripts/check-release-version.php v2.0.0

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$tag = $argv[1] ?? '';
if (preg_match('/^v(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/D', $tag, $match) !== 1) {
	fwrite(STDERR, "Release tag \"$tag\" is not of the form vMAJOR.MINOR.PATCH[-PRERELEASE]\n");
	exit(1);
}

$version = \ABSmartly\SDK\Client\Client::SDK_VERSION;
if ($match[1] !== $version) {
	fwrite(STDERR, "Release tag $tag does not match Client::SDK_VERSION $version; bump it before tagging\n");
	exit(1);
}

echo "Release version $version OK\n";
