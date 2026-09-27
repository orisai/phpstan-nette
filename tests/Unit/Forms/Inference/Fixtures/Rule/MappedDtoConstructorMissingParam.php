<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class MappedDtoConstructorMissingParam
{

	public string $name;

	public function __construct(string $name, string $missing)
	{
		$this->name = $name;
	}

}
