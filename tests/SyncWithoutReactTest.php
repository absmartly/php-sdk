<?php

namespace ABSmartly\SDK\Tests;

use PHPUnit\Framework\TestCase;

class SyncWithoutReactTest extends TestCase {
	public function testSynchronousLifecycleWithoutReactAutoloading(): void {
		$source = <<<'PHP'
$root = $argv[1];
spl_autoload_register(function ($class) use ($root) {
    if ($class === 'ABSmartly\\SDK') {
        require $root . '/src/SDK.php';
        return;
    }
    $prefix = 'ABSmartly\\SDK\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
if (interface_exists('React\\Promise\\PromiseInterface') || function_exists('React\\Promise\\resolve')) {
    throw new RuntimeException('React must not be loaded');
}
class SyncTransport implements \ABSmartly\SDK\Http\HttpClientInterface {
    public int $gets = 0;
    public int $puts = 0;
    public function get(string $url, array $query = [], array $headers = []): \ABSmartly\SDK\Http\Response {
        ++$this->gets;
        $response = new \ABSmartly\SDK\Http\Response();
        $response->status = 200;
        $response->content = '{"experiments":[]}';
        return $response;
    }
    public function put(string $url, array $query = [], array $headers = [], string $body = ''): \ABSmartly\SDK\Http\Response {
        ++$this->puts;
        $response = new \ABSmartly\SDK\Http\Response();
        $response->status = 200;
        $response->content = '{}';
        return $response;
    }
    public function post(string $url, array $query = [], array $headers = [], string $body = ''): \ABSmartly\SDK\Http\Response {
        throw new RuntimeException('Unexpected POST');
    }
    public function close(): void {}
}
\ABSmartly\SDK::createSimple('https://example.com', 'key', 'app', 'env')->close();
$transport = new SyncTransport();
$client = new \ABSmartly\SDK\Client\Client(new \ABSmartly\SDK\Client\ClientConfig('https://example.com', 'key', 'app', 'env'), $transport);
$sdk = new \ABSmartly\SDK(new \ABSmartly\SDK\Config($client));
$config = (new \ABSmartly\SDK\Context\ContextConfig())->setUnit('user_id', '123');
$context = $sdk->createContext($config);
if (!$context->isReady() || $context->isFailed() || $transport->gets !== 1) {
    throw new RuntimeException('Synchronous context failed');
}
$context->track('purchase');
$context->publish();
if ($transport->puts !== 1) {
    throw new RuntimeException('Synchronous publish failed');
}
$contextWithData = $sdk->createContextWithData($config, new \ABSmartly\SDK\Context\ContextData());
if (!$contextWithData->isReady() || $contextWithData->isFailed() || $transport->gets !== 1) {
    throw new RuntimeException('Context with data failed');
}
if (\ABSmartly\SDK\Utils::isPromise(new stdClass())) {
    throw new RuntimeException('Ordinary object was treated as a promise');
}
$sdk->close();
PHP;
		$command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($source) . ' ' . escapeshellarg(dirname(__DIR__)) . ' 2>&1';
		exec($command, $output, $status);
		self::assertSame(0, $status, implode("\n", $output));
	}
}
