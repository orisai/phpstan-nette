<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Forms;

use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use function array_slice;
use function count;
use function preg_match;
use function preg_replace;
use function strlen;
use function substr;
use function trim;

// The component an existence check names - {ifset $form['x']} / n:ifset="$form['x']" - read from
// the check's own argument text, and the suffix rule that decides which references it covers.
final class ExistenceGuard
{

	// $form[...] / $container[...] - a variable with an offset is the only spelling that can be a
	// component existence check. The variable's own name is not compared: {form X} binds $form, but
	// a nested {formContainer} body may legitimately check through a differently named local.
	private const RECEIVER_PATTERN = '~^\\$[a-zA-Z_][a-zA-Z0-9_]*(?=\\s*\\[)~';

	private const OFFSET_PATTERN = '~^\\s*\\[\\s*(?:\'([^\']*)\'|"([^"]*)")\\s*\\]~';

	private function __construct()
	{
	}

	// {ifset $var} / {ifset #block} / n:ifset="$item->x" check something that is not a component
	// of the enclosing form, so they guard nothing.
	public static function isComponentCheck(string $expression): bool
	{
		return preg_match(self::RECEIVER_PATTERN, trim($expression)) === 1;
	}

	// The literal component path the check names, relative to the form: $form['a']['b'] and
	// $form['a-b'] both read ['a', 'b'], because Container::getComponent() explodes the offset the same
	// way. Null when the path cannot be read (a computed offset, a compound condition) - the
	// conservative reading, which suppresses everything the guard encloses rather than guessing.

	/**
	 * @return list<string>|null
	 */
	public static function pathOf(string $expression): ?array
	{
		$rest = trim((string) preg_replace(self::RECEIVER_PATTERN, '', trim($expression), 1));

		$path = [];
		while ($rest !== '') {
			if (preg_match(self::OFFSET_PATTERN, $rest, $matches) !== 1) {
				return null;
			}

			$literal = ($matches[2] ?? '') !== '' ? $matches[2] : $matches[1];
			$name = ComponentNameSyntax::literalName($literal);
			if ($name === null) {
				return null;
			}

			foreach (ComponentPath::split($name) as $segment) {
				$path[] = $segment;
			}

			$rest = trim((string) substr($rest, strlen($matches[0])));
		}

		return $path === [] ? null : $path;
	}

	// A guard covers a reference when it names the same component: the guard's path must be a suffix of
	// the reference's own full path, so {ifset $form['x']} covers {input x} and, inside
	// {formContainer c}, an $x['street'] check covers c-street. A guard naming a DIFFERENT component
	// covers nothing - that is the whole scope of the suppression.

	/**
	 * @param list<list<string>|null> $guards
	 * @param list<string> $containerPath
	 */
	public static function covers(array $guards, array $containerPath, ?string $name): bool
	{
		$reference = $name === null ? null : [...$containerPath, ...ComponentPath::split($name)];

		foreach ($guards as $guard) {
			if ($guard === null) {
				return true;
			}

			if ($reference === null || count($guard) > count($reference)) {
				continue;
			}

			if (array_slice($reference, -count($guard)) === $guard) {
				return true;
			}
		}

		return false;
	}

}
