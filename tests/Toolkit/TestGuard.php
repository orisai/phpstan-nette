<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PHPStan\DependencyInjection\Container;
use function assert;

final class TestGuard
{

	public static function of(Container $container): ConfigurationGuard
	{
		$guard = $container->getService('orisaiNette.configurationGuard');
		assert($guard instanceof ConfigurationGuard);

		return $guard;
	}

}
