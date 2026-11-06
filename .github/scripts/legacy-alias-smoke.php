<?php

// Checks that the legacy ABSmartly\SDK\SDK name resolves when it is the first SDK class referenced, which is what
// happens in applications installed with `composer dump-autoload --classmap-authoritative`.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!class_exists('ABSmartly\\SDK\\SDK')) {
	fwrite(STDERR, "ABSmartly\\SDK\\SDK is not loadable with the authoritative classmap\n");
	exit(1);
}

$sdk = \ABSmartly\SDK\SDK::createWithDefaults('https://demo.absmartly.io/v1', 'key', 'env', 'app');

if (!$sdk instanceof \ABSmartly\SDK) {
	fwrite(STDERR, "ABSmartly\\SDK\\SDK does not alias ABSmartly\\SDK\n");
	exit(1);
}

echo "Legacy alias OK\n";
