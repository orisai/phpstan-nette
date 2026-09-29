<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures;

use LogicException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Extensions\DIExtension;
use Nette\DI\Extensions\ExtensionsExtension;
use function get_class;
use function preg_match;
use function sprintf;

final class FixtureContainerFactory
{

	public static function className(string $profile): string
	{
		return get_class((new self())->create($profile));
	}

	public function create(string $profile): Container
	{
		$configFile = __DIR__ . '/fixture-' . $profile . '.neon';
		$tempDir = __DIR__ . '/../../../../var/tools/PHPUnit.DicFixtures';

		$loader = new ContainerLoader($tempDir, true);
		$className = $loader->load(
			static function (Compiler $compiler) use ($configFile): void {
				$compiler->addExtension('extensions', new ExtensionsExtension());
				$compiler->addExtension('di', new DIExtension());
				$compiler->loadConfig($configFile);
			},
			['dic-fixture', $profile],
		);

		$container = new $className([]);
		if (!$container instanceof Container) {
			throw new LogicException(sprintf('Class %s is not a container.', $className));
		}

		if (preg_match('~^Container_[0-9a-f]+(?:_[0-9a-f]+)?$~', get_class($container)) !== 1) {
			throw new LogicException(sprintf('Unexpected container class %s for profile %s.', $className, $profile));
		}

		return $container;
	}

}
