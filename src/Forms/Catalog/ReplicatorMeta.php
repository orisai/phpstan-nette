<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

final class ReplicatorMeta
{

	private int $factoryArgPosition;

	private ?string $containerClass;

	public function __construct(int $factoryArgPosition, ?string $containerClass)
	{
		$this->factoryArgPosition = $factoryArgPosition;
		$this->containerClass = $containerClass;
	}

	public function getFactoryArgPosition(): int
	{
		return $this->factoryArgPosition;
	}

	public function getContainerClass(): ?string
	{
		return $this->containerClass;
	}

}
