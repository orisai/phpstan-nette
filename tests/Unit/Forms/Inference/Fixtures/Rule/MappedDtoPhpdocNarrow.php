<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class MappedDtoPhpdocNarrow
{

	/** @var non-empty-string */
	public string $name;

	public ?int $age;

}
