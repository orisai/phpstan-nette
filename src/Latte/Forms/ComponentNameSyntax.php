<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Forms;

use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use function strlen;
use function substr;
use function trim;

// A form macro argument is a component NAME only if every NameSeparator segment of it is one:
// getComponent() explodes on '-' and applies Container::NameRegexp per part, so 'a-b' is a legal
// (nested) reference while 'a|b', 'foo()' and '$x' are not names at all. Asked of ComponentPath
// rather than spelled as a regex of its own, so a relaxed NameRegexp upstream moves both channels.
final class ComponentNameSyntax
{

	private function __construct()
	{
	}

	public static function literalName(?string $word): ?string
	{
		if ($word === null) {
			return null;
		}

		$word = self::dequote(trim($word));
		foreach (ComponentPath::split($word) as $segment) {
			if (!ComponentPath::isValidSegment($segment)) {
				return null;
			}
		}

		return $word;
	}

	public static function dequote(string $value): string
	{
		$length = strlen($value);
		if ($length >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[$length - 1] === $value[0]) {
			return (string) substr($value, 1, -1);
		}

		return $value;
	}

}
