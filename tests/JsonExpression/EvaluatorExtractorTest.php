<?php

namespace Absmartly\SDK\Tests\JsonExpression;

use Absmartly\SDK\JsonExpression\Evaluator;
use Absmartly\SDK\JsonExpression\Operator\OperatorCollection;
use PHPUnit\Framework\TestCase;

class EvaluatorExtractorTest extends TestCase {
	public function testSimpleExtraction(): void {
		$data = new \stdClass();
		$data->foo = 'foo-value';
		$data->bar = 'bar-value';

		$evaluator = new Evaluator(new OperatorCollection(), $data);
		self::assertSame('foo-value', $evaluator->extractVar('foo'));
		self::assertSame('bar-value', $evaluator->extractVar('bar'));
		self::assertNotSame('foo-value', $evaluator->extractVar('bar'));
		self::assertNull($evaluator->extractVar('qux'));

		self::assertNull($evaluator->extractVar(''));
	}

	public function testCompositeExtraction(): void {
		$vars = new \stdClass();
		$vars->a = 1;
		$vars->b = true;
		$vars->c = false;
		$vars->d = [1, 2, 3];
		$vars->e = [1, (object) ['z' => 2], 3];
		$vars->f = (object) ['y' => (object) ['x' => 3, 0 => 10]];

		$evaluator = new Evaluator(new OperatorCollection(), $vars);

		self::assertSame(1, $evaluator->extractVar('a'));
		self::assertTrue($evaluator->extractVar('b'));
		self::assertFalse($evaluator->extractVar('c'));
		self::assertSame([1, 2, 3], $evaluator->extractVar('d'));
		self::assertEquals([1, (object) ['z' => 2], 3], $evaluator->extractVar('e'));
		self::assertEquals((object) ['y' => (object) ['x' => 3, 0 => 10]], $evaluator->extractVar('f'));

		self::assertNull($evaluator->extractVar('a/0'));
		self::assertNull($evaluator->extractVar('a/b'));
		self::assertNull($evaluator->extractVar('b/0'));
		self::assertNull($evaluator->extractVar('b/e'));

		self::assertSame(1, $evaluator->extractVar('d/0'));
		self::assertSame(2, $evaluator->extractVar('d/1'));
		self::assertSame(3, $evaluator->extractVar('d/2'));
		self::assertNull($evaluator->extractVar('d/3'));

		self::assertSame(1, $evaluator->extractVar('e/0'));
		self::assertSame(2, $evaluator->extractVar('e/1/z'));
		self::assertSame(3, $evaluator->extractVar('e/2'));
		self::assertNull($evaluator->extractVar('e/1/0'));

		self::assertSame($vars->f->y, $evaluator->extractVar('f/y'));
		self::assertEquals((object) ['x' => 3, 0 => 10], $evaluator->extractVar('f/y'));
		self::assertEquals(3, $evaluator->extractVar('f/y/x'));
		self::assertEquals(10, $evaluator->extractVar('f/y/0'));
	}

	public function testEmptyVarExtraction(): void {
		$evaluator = new Evaluator(new OperatorCollection(), new \stdClass());
		self::assertNull($evaluator->extractVar('anything'));
	}

	public function testDeeplyNestedExtractionThrows(): void {
		$accessor = str_repeat('a/', 100);
		$evaluator = new Evaluator(new OperatorCollection(), new \stdClass());
		$this->expectException(\InvalidArgumentException::class);
		$evaluator->extractVar($accessor);
	}
}
