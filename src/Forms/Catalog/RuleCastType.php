<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use PHPStan\Type\Type;

final class RuleCastType
{

	private Type $readType;

	private string $writeSetSpec;

	public function __construct(Type $readType, string $writeSetSpec)
	{
		$this->readType = $readType;
		$this->writeSetSpec = $writeSetSpec;
	}

	public function getReadType(): Type
	{
		return $this->readType;
	}

	public function getWriteSetSpec(): string
	{
		return $this->writeSetSpec;
	}

}
