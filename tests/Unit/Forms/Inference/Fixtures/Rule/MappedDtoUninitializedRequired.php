<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class MappedDtoUninitializedRequired
{

	public string $name;

	public ?int $age;

	public int $missing;

}
