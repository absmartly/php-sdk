<?php
namespace ABSmartly\SDK\JsonExpression\Operator;
final class SemverGtOperator extends SemverOperator { protected function matches(int $comparison): bool { return $comparison > 0; } }
