<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

final class Certainty
{

	public const HAPPENS = 'happens';

	public const MAYBE = 'maybe';

	public const NEVER = 'never';

	public const UNKNOWN = 'unknown';

	private function __construct()
	{
	}

	public static function join(string $a, string $b): string
	{
		if ($a === self::UNKNOWN || $b === self::UNKNOWN) {
			return self::UNKNOWN;
		}

		return $a === $b ? $a : self::MAYBE;
	}

}
