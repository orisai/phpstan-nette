<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use function getenv;
use function is_string;
use function strlen;
use function strpos;
use function substr;

final class VendorDirectory
{

	public static function name(): string
	{
		$vendorDir = getenv('COMPOSER_VENDOR_DIR');

		return is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : 'vendor';
	}

	public static function composerFile(): ?string
	{
		$name = self::name();

		return strpos($name, 'vendor-') === 0 ? 'composer.' . substr($name, strlen('vendor-')) . '.json' : null;
	}

}
