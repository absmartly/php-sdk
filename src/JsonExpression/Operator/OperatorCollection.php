<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression\Operator;

final class OperatorCollection {
	public OperatorInterface $and;
	public OperatorInterface $or;
	public OperatorInterface $value;
	public OperatorInterface $var;
	public OperatorInterface $null;
	public OperatorInterface $not;
	public OperatorInterface $in;
	public OperatorInterface $match;
	public OperatorInterface $eq;
	public OperatorInterface $gt;
	public OperatorInterface $gte;
	public OperatorInterface $lt;
	public OperatorInterface $lte;
	public OperatorInterface $semver_eq;
	public OperatorInterface $semver_gt;
	public OperatorInterface $semver_gte;
	public OperatorInterface $semver_lt;
	public OperatorInterface $semver_lte;

	public function __construct() {
		$this->null = new NullOperator();
		$this->value = new ValueOperator();
		$this->not = new NotOperator();
		$this->and = new AndOperator();
		$this->gt = new GtOperator();
		$this->gte = new GteOperator();
		$this->var = new VarOperator();
		$this->eq = new EqOperator();
		$this->or = new OrOperator();
		$this->lt = new LtOperator();
		$this->lte = new LteOperator();
		$this->semver_eq = new SemverEqOperator();
		$this->semver_gt = new SemverGtOperator();
		$this->semver_gte = new SemverGteOperator();
		$this->semver_lt = new SemverLtOperator();
		$this->semver_lte = new SemverLteOperator();
		$this->in = new InOperator();
		$this->match = new MatchOperator();
	}
}
