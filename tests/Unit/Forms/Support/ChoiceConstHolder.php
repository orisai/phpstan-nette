<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

final class ChoiceConstHolder
{

	public const STRING_KEYS = ['draft' => 'Draft', 'live' => 'Live'];

	public const INT_KEYS = [1 => 'Low', 2 => 'High'];

	public const STRING_VALUES = ['x', 'y', 'z'];

	public const NOT_AN_ARRAY = 'scalar';

}
