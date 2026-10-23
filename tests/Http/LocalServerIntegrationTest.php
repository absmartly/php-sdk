<?php

namespace ABSmartly\SDK\Tests\Http;

use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Http\ReactHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Hermetic integration test: spins up a real local HTTP server (PHP built-in
 * server) on an ephemeral port, points the SDK's client endpoint at it, and
 * drives the PUBLIC SDK API so a REAL HTTP client (the SDK's ReactHttpClient,
 * which supports plain http unlike the HTTPS-locked curl HTTPClient) performs a
 * GET /context (createContext) and a PUT /context (getTreatment + track ->
 * publish). Asserts the wire contract.
 *
 * Requests received by the server are appended to a JSON-lines log file the
 * test reads back after publishing.
 */
class LocalServerIntegrationTest extends TestCase {
	/** @var resource|null */
	private $serverProcess;
	private array $pipes = [];
	private string $logFile;
	private int $port;

	private function findFreePort(): int {
		$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		self::assertIsResource($sock, "failed to allocate port: $errstr");
		$name = stream_socket_get_name($sock, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		fclose($sock);
		return $port;
	}

	protected function setUp(): void {
		$this->logFile = tempnam(sys_get_temp_dir(), 'absmartly_reqs_');
		file_put_contents($this->logFile, '');

		$router = __DIR__ . '/local_server_router.php';
		$this->port = $this->findFreePort();

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$cmd = sprintf(
			'%s -S 127.0.0.1:%d %s',
			escapeshellarg(PHP_BINARY),
			$this->port,
			escapeshellarg($router)
		);

		$env = ['ABSMARTLY_TEST_LOG' => $this->logFile] + $_ENV;
		$this->serverProcess = proc_open($cmd, $descriptors, $this->pipes, null, $env);
		self::assertIsResource($this->serverProcess, 'failed to start PHP built-in server');

		// Wait for the server to accept connections.
		$deadline = microtime(true) + 5.0;
		while (microtime(true) < $deadline) {
			$conn = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 0.2);
			if (is_resource($conn)) {
				fclose($conn);
				return;
			}
			usleep(50000);
		}
		self::fail('local server did not become ready');
	}

	protected function tearDown(): void {
		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		if (is_resource($this->serverProcess)) {
			proc_terminate($this->serverProcess);
			proc_close($this->serverProcess);
		}
		if (isset($this->logFile) && is_file($this->logFile)) {
			unlink($this->logFile);
		}
	}

	private function recordedRequests(): array {
		$lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
		return array_map(static fn($l) => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines);
	}

	public function testRealGetAndPutContext(): void {
		$endpoint = "http://127.0.0.1:{$this->port}";

		// Build the SDK with the SDK's own ReactHttpClient (a real HTTP client
		// that talks plain http) injected into the real Client.
		$clientConfig = new ClientConfig($endpoint, 'test-api-key', 'website', 'dev');
		$client = new Client($clientConfig, new ReactHttpClient());
		$sdk = new ABsmartly(new Config($client));

		$contextConfig = new ContextConfig();
		$contextConfig->setUnit('user_id', '123456789');

		$context = $sdk->createContext($contextConfig);
		self::assertTrue($context->isReady());

		// --- assert the real GET /context ---
		$requests = $this->recordedRequests();
		$get = null;
		foreach ($requests as $r) {
			if ($r['method'] === 'GET') {
				$get = $r;
				break;
			}
		}
		self::assertNotNull($get, 'expected a GET /context');
		self::assertSame('/context', $get['path']);
		self::assertSame('website', $get['query']['application'] ?? null);
		self::assertSame('dev', $get['query']['environment'] ?? null);

		// --- queue an event then publish ---
		$context->track('payment', (object) ['value' => 99]);
		$context->publish();

		$requests = $this->recordedRequests();
		$put = null;
		foreach ($requests as $r) {
			if ($r['method'] === 'PUT') {
				$put = $r;
				break;
			}
		}
		self::assertNotNull($put, 'expected a PUT /context');
		self::assertSame('/context', $put['path']);

		// --- headers (server lowercases header names) ---
		$h = $put['headers'];
		self::assertSame('test-api-key', $h['x-api-key'] ?? null);
		self::assertSame('website', $h['x-application'] ?? null);
		self::assertSame('dev', $h['x-environment'] ?? null);
		self::assertSame('0', $h['x-application-version'] ?? null);
		self::assertNotEmpty($h['x-agent'] ?? null);
		self::assertStringContainsString('application/json', $h['content-type'] ?? '');

		// --- body ---
		$body = json_decode($put['body'], true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayHasKey('hashed', $body);
		self::assertIsArray($body['units']);
		self::assertNotEmpty($body['units']);
		self::assertArrayHasKey('type', $body['units'][0]);
		self::assertArrayHasKey('uid', $body['units'][0]);
		self::assertArrayHasKey('publishedAt', $body);
		self::assertIsInt($body['publishedAt']);
		self::assertArrayHasKey('goals', $body);
		self::assertNotEmpty($body['goals']);
		self::assertSame('payment', $body['goals'][0]['name']);

		$sdk->close();
	}
}
