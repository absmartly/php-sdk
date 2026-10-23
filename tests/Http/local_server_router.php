<?php
/**
 * Router for the PHP built-in server used by LocalServerIntegrationTest.
 *
 * It records every request (method, path, query, headers, body) as a JSON line
 * in the file named by the ABSMARTLY_TEST_LOG env var, then returns the minimal
 * responses the SDK expects: {"experiments":[]} on GET, {} on PUT.
 */

$logFile = getenv('ABSMARTLY_TEST_LOG');

$headers = [];
foreach ($_SERVER as $key => $value) {
	if (strpos($key, 'HTTP_') === 0) {
		$name = strtolower(str_replace('_', '-', substr($key, 5)));
		$headers[$name] = $value;
	}
}
if (isset($_SERVER['CONTENT_TYPE'])) {
	$headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
$body = file_get_contents('php://input');

if ($logFile) {
	$record = json_encode([
		'method' => $method,
		'path' => $path,
		'query' => $query,
		'headers' => $headers,
		'body' => $body,
	]);
	file_put_contents($logFile, $record . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
if ($method === 'GET') {
	echo '{"experiments":[]}';
} else {
	echo '{}';
}
