<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class NestedOuterGood
{

	public string $name;

	public NestedAddressGood $address;

}
