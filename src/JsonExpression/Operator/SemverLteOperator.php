<?php
namespace ABSmartly\SDK\JsonExpression\Operator;
final class SemverLteOperator extends SemverOperator { protected function matches(int $comparison): bool { return $comparison <= 0; } }
