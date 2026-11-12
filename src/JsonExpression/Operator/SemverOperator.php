<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;
use ABSmartly\SDK\JsonExpression\Evaluator;
use ABSmartly\SDK\JsonExpression\SemverComparator;
abstract class SemverOperator extends BinaryOperator { abstract protected function matches(int $comparison): bool; public function binary(Evaluator $evaluator,$lhs,$rhs): ?bool { $c=SemverComparator::compare($lhs,$rhs); return $c===null?null:$this->matches($c); } }
