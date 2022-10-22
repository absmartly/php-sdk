<?php
declare(strict_types=1);

namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

interface OperatorInterface {
	public function evaluate(Evaluator $evaluator);
}
