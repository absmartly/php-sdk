<?php

namespace ABSmartly\SDK\Tests\JsonExpression;

use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\Expression;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase {
	private array $fixtures = [];
	private Expression $expression;

	public function setUp(): void {
		$this->expression = new Expression();

		$this->fixtures['John'] = (object) ["age" =>  20, "language" => "en-US", "returning" => false];
		$this->fixtures['Terry'] = (object) ["age" =>  20, "language" => "en-GB", "returning" => true];
		$this->fixtures['Kate'] = (object) ["age" =>  50, "language" => "es-ES", "returning" => false];
		$this->fixtures['Maria'] = (object) ["age" =>  52, "language" => "pt-PT", "returning" => true];


		$this->fixtures['AgeTwentyAndUS'] = [
			$this->binaryOp("eq", $this->varFor("age"), $this->valueFor(20)),
			$this->binaryOp("eq", $this->varFor("language"), $this->valueFor('en-US')),
		];

		$this->fixtures['AgeOverFifty'] = $this->binaryOp("gte", $this->varFor("age"), $this->valueFor(50));

		$this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'] = (object) [
			'or' => [
				$this->fixtures['AgeOverFifty'],
				$this->fixtures['AgeTwentyAndUS'],
			]
		];

		$this->fixtures['Returning'] = $this->varFor('returning');

		$this->fixtures['Returning_And_AgeTwentyAndUS_Or_AgeOverFifty'] = [
			$this->fixtures['Returning'],
			$this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'],
		];

		$this->fixtures['NotReturning_And_Spanish'] = [
			$this->unaryOp("not", $this->fixtures['Returning']),
			$this->binaryOp("eq", $this->varFor('language'), $this->valueFor('es-ES')),
		];
	}

	private function unaryOp(string $op, $arg): \stdClass {
		return (object) [$op => $arg];
	}

	private function binaryOp(string $op, $lhs, $rhs): \stdClass {
		return (object) [$op => [$lhs, $rhs]];
	}

	private function varFor(string $path): \stdClass {
		return (object) ["var" => ['path' => $path]];
	}

	private function valueFor($value): \stdClass {
		return (object) ["value" => $value];
	}


	public function testEvaluatorReturnsNullOnUnknownOperators(): void {
		$args = new \stdClass();
		$args->fooBar = 1;
		$evaluator = new Evaluator(new OperatorCollection(), new \stdClass());
		self::assertNull($evaluator->evaluate($args));
	}


	public function testReturning(): void {
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning'] , $this->fixtures['John']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['Returning'] , $this->fixtures['Terry']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning'] , $this->fixtures['Kate']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['Returning'] , $this->fixtures['Maria']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning'] , new \stdClass()));
	}

	public function testNotReturning_And_Spanish(): void {
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['NotReturning_And_Spanish'], $this->fixtures['John']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['NotReturning_And_Spanish'], $this->fixtures['Terry']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['NotReturning_And_Spanish'], $this->fixtures['Kate']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['NotReturning_And_Spanish'], $this->fixtures['Maria']));
	}

	public function testAgeOverFifty(): void {
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeOverFifty'], $this->fixtures['John']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeOverFifty'], $this->fixtures['Terry']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeOverFifty'], $this->fixtures['Kate']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeOverFifty'], $this->fixtures['Maria']));
	}

	public function testAgeTwentyAndUS(): void {
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS'], $this->fixtures['John']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS'], $this->fixtures['Terry']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS'], $this->fixtures['Kate']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS'], $this->fixtures['Maria']));
	}

	public function testAgeTwentyAndUS_Or_AgeOverFifty(): void {
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['John']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Terry']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Kate']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Maria']));
	}

	public function testReturning_And_AgeTwentyAndUS_Or_AgeOverFifty(): void {
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning_And_AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['John']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning_And_AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Terry']));
		self::assertFalse($this->expression->evaluateBooleanExpr($this->fixtures['Returning_And_AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Kate']));
		self::assertTrue($this->expression->evaluateBooleanExpr($this->fixtures['Returning_And_AgeTwentyAndUS_Or_AgeOverFifty'], $this->fixtures['Maria']));
	}
}
