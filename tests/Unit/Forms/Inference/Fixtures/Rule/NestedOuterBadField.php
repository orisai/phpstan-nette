<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class NestedOuterBadField
{

	public string $name;

	public NestedAddressBadField $address;

}
