<?php

namespace ABSmartly\SDK;

// Keep the source parseable by PHP 7.4 while suppressing PHP 8.2 dynamic-property deprecations.
if (PHP_VERSION_ID >= 80200) {
	eval('namespace ABSmartly\\SDK; #[\\AllowDynamicProperties] class DynamicModel {}');
}
else {
	class DynamicModel {}
}
