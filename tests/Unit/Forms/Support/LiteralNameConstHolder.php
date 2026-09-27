<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

final class LiteralNameConstHolder
{

	public const FIELD_NAME = 'email';

	public const NOT_A_STRING = ['a', 'b'];

}
