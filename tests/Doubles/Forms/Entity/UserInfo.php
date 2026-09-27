<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Entity;

final class UserInfo
{

	/**
	 * @return array<int, string>
	 */
	public static function getTobaccoProductsElectricTypeEnum(): array
	{
		return [
			1 => 'IQOS',
			2 => 'LIL Solis',
			3 => 'Jiné',
		];
	}

}
