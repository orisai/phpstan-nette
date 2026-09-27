<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use PHPStan\Type\Type;

final class ChoiceModel
{

	private bool $multi;

	private Type $keyDomain;

	private ?Type $extra;

	public function __construct(bool $multi, Type $keyDomain, ?Type $extra)
	{
		$this->multi = $multi;
		$this->keyDomain = $keyDomain;
		$this->extra = $extra;
	}

	public function isMulti(): bool
	{
		return $this->multi;
	}

	public function getKeyDomain(): Type
	{
		return $this->keyDomain;
	}

	public function isOpen(): bool
	{
		return $this->extra !== null;
	}

	public function getExtra(): ?Type
	{
		return $this->extra;
	}

}
