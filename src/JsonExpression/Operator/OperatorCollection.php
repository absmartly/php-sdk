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
		$this->in = new InOperator();
		$this->match = new MatchOperator();
	}
}
