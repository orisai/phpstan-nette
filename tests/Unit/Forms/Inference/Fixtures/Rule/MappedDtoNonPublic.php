<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class MappedDtoNonPublic
{

	public string $name;

	private ?int $age;

}
