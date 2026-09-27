<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use function PHPStan\dumpType;

final class PropertyFormNonFormService
{

	public function getValue(): int
	{
		return 1;
	}

}

/**
 * Properties holding something that is not a container, and the reason the PropertyFetch root is
 * gated on the receiver's type. The `$this->rows['a']->getValue()` row is the one that matters:
 * FormControlGetValueTypeResolver fires on EVERY no-arg getValue() call in the analysed universe,
 * with no receiver-type gate of its own, and hands the offset's parent — here a plain array
 * property — to the shape resolver. Ungated, every such property would pay a full enumerate-and-
 * locate sweep of its class's methods before the fold could report a miss.
 */
final class PropertyFormNonFormObjectUnchanged
{

	private PropertyFormNonFormService $service;

	/** @var array<string, PropertyFormNonFormService> */
	private array $rows;

	public function __construct()
	{
		$this->service = new PropertyFormNonFormService();
		$this->rows = ['a' => $this->service];
	}

	public function go(): void
	{
		dumpType($this->service); // => Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\PropertyFormNonFormService
		dumpType($this->rows['a']->getValue()); // => int
	}

}
