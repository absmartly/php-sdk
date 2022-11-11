<?php
declare(strict_types=1);

namespace ABSmartly\SDK\JsonExpression\Operator;

use ABSmartly\SDK\JsonExpression\Evaluator;

interface OperatorInterface {
	public function evaluate(Evaluator $evaluator);
}
