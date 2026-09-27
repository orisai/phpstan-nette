<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

use function in_array;
use function strncmp;

/**
 * The add* surface that registers a COMPONENT, as against the vendor add* names that attach a
 * validation rule, an error or a rendering group instead. A name this has never seen still counts:
 * a project's own addPhone() extension registers a control, and treating an unknown add* as
 * something else is how a component the reader can see stops being in the shape.
 */
final class RegisteringMethodName
{

	private const NON_REGISTERING_ADD_METHODS = [
		'addError',
		'addRule',
		'addCondition',
		'addConditionOn',
		'addFilter',
		'addGroup',
	];

	private function __construct()
	{
	}

	public static function matches(string $name): bool
	{
		if ($name === 'offsetSet') {
			return true;
		}

		return strncmp($name, 'add', 3) === 0 && !in_array($name, self::NON_REGISTERING_ADD_METHODS, true);
	}

}
