<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use function getenv;
use function is_string;

final class VendorDirectory
{

	public static function name(): string
	{
		$vendorDir = getenv('COMPOSER_VENDOR_DIR');

		return is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : 'vendor';
	}

}
