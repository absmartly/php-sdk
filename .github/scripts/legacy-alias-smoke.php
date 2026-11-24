<?php

// Checks that the released ABSmartly\SDK\SDK class and its ABSmartly\SDK alias both resolve when they are the first SDK
// class referenced, which is what happens in applications installed with `composer dump-autoload --classmap-authoritative`.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!class_exists('ABSmartly\\SDK\\SDK') || !class_exists('ABSmartly\\SDK')) {
	fwrite(STDERR, "ABSmartly\\SDK\\SDK or its ABSmartly\\SDK alias is not loadable with the authoritative classmap\n");
	exit(1);
}

$sdk = \ABSmartly\SDK\SDK::createWithDefaults('https://demo.absmartly.io/v1', 'key', 'env', 'app');

if (get_class($sdk) !== 'ABSmartly\\SDK\\SDK' || !$sdk instanceof \ABSmartly\SDK) {
	fwrite(STDERR, "ABSmartly\\SDK\\SDK is not the real class, or ABSmartly\\SDK does not alias it\n");
	exit(1);
}

echo "Class name and alias OK\n";
