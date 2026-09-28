<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use function assert;
use function is_array;

final class TestGuard
{

	public static function of(Container $container): ConfigurationGuard
	{
		$guard = $container->getService('orisaiNette.configurationGuard');
		assert($guard instanceof ConfigurationGuard);

		return $guard;
	}

	public static function withContainerLoader(Container $container, string $loaderFile): ConfigurationGuard
	{
		$config = $container->getParameter('orisaiNette');
		assert(is_array($config));
		$config['dic']['containerLoader'] = $loaderFile;

		$fileExtensions = $container->getParameter('fileExtensions');
		assert(is_array($fileExtensions));

		return new ConfigurationGuard(
			$config, // @phpstan-ignore argument.type
			$fileExtensions, // @phpstan-ignore argument.type
			$container->getByType(ReflectionProvider::class),
		);
	}

}
