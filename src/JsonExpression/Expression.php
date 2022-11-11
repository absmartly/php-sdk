<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression;

use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;

final class Expression {

	private function getOperators(): OperatorCollection {
		return new OperatorCollection();
	}

	public function evaluateBooleanExpr($expression, object $vars): bool {
		return Evaluator::booleanConvert($this->evaluateExpr($expression, $vars));
	}

	public function evaluateExpr($expression, object $vars) {
		return (new Evaluator($this->getOperators(), $vars))->evaluate($expression);
	}
}
