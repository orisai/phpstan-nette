<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use ArrayAccess;
use Nette\ComponentModel\ArrayAccess as ComponentArrayAccess;
use Nette\ComponentModel\Container;

/**
 * The bare vendor composition Nette's own component paths are made of: a
 * Nette\ComponentModel\Container plus the ArrayAccess trait that delegates offsetGet() to
 * getComponent(). Deliberately NOT a Nette\Forms\Container - ComponentPathTest drives real instances
 * of this to establish what the runtime does, and a forms container would drag the whole Forms
 * bridge into a test whose subject is the component model alone.
 *
 * @implements ArrayAccess<string|int, mixed>
 */
final class PathContainer extends Container implements ArrayAccess
{

	use ComponentArrayAccess;

}
