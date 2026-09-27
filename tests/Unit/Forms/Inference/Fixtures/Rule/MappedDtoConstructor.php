<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class MappedDtoConstructor
{

	public string $name;

	public ?int $age;

	public function __construct(string $name, ?int $age)
	{
		$this->name = $name;
		$this->age = $age;
	}

}
