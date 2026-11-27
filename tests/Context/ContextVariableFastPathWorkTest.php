<?php

namespace ABSmartly\SDK\Tests\Context;

use PHPUnit\Framework\TestCase;

/** Counts ordinary walk entries in a disposable source copy; never adds a timing threshold or a production hook. */
class ContextVariableFastPathWorkTest extends TestCase {
	public function testWarmedSingleCandidateReadsDoNotEnterTheVariableWalk(): void {
		$root = dirname(__DIR__, 2);
		$source = file_get_contents($root . '/src/Context/Context.php');
		$start = strpos($source, 'private function resolveVariableValue(');
		$end = strpos($source, 'private function resolveSwitchbackVariableValue(', $start);
		$method = substr($source, $start, $end - $start);
		$method = str_replace('foreach ($walk as $index => $experimentVariables) {',
			'foreach ($walk as $index => $experimentVariables) { ++$GLOBALS["variableWalkEntries"];', $method, $count);
		self::assertSame(1, $count, 'the work probe must instrument exactly the ordinary walk');
		$source = substr_replace($source, $method, $start, $end - $start);
		$file = tempnam(sys_get_temp_dir(), 'sdk-fastpath-');
		file_put_contents($file, $source);
		$runner = tempnam(sys_get_temp_dir(), 'sdk-fastpath-run-');
		$code = <<<'PHP'
<?php
$GLOBALS['variableWalkEntries'] = 0;
require $argv[1];
require $argv[2] . '/vendor/autoload.php';
require $argv[2] . '/tests/Context/SwitchbackTestSupport.php';
class FastPathProbe { use \ABSmartly\SDK\Tests\Context\SwitchbackTestSupport;
 public function run(): void {
  $this->setUp();
  try {
   $context = $this->createContext(self::dataOf(self::unitExperiment()));
   $context->getVariableValue('sb.arm', -1);
   $GLOBALS['variableWalkEntries'] = 0;
   for ($i = 0; $i < 1000; ++$i) {
    $context->getVariableValue('sb.arm', -1);
    $context->peekVariableValue('sb.arm', -1);
   }
   echo $GLOBALS['variableWalkEntries'];
  } finally { $this->tearDown(); }
 }
}
(new FastPathProbe())->run();
PHP;
		file_put_contents($runner, $code);
		try {
			$process = proc_open([PHP_BINARY, $runner, $file, $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			self::assertSame(0, proc_close($process), $error);
			self::assertSame('', $error);
			self::assertSame('0', $output, 'warmed get and peek must bypass the ordinary candidate walk');
		}
		finally {
			unlink($file);
			unlink($runner);
		}
	}
}
