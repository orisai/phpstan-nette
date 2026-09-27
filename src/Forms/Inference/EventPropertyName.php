<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use function strlen;
use function strncmp;

final class EventPropertyName
{

	public static function matches(string $name): bool
	{
		if (strncmp($name, 'on', 2) !== 0 || strlen($name) <= 2) {
			return false;
		}

		$third = $name[2];

		return $third >= 'A' && $third <= 'Z';
	}

}
