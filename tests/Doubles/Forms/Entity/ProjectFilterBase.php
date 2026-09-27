<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Entity;

final class ProjectFilterBase
{

	/**
	 * @return array
	 */
	public static function getEmploymentTypes()
	{
		return [
			0 => 'Smlouva',
			1 => 'Dohoda',
			2 => 'Smlouva nebo dohoda',
		];
	}

}
