<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures;

use LogicException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Extensions\DIExtension;
use Nette\DI\Extensions\ExtensionsExtension;
use function get_class;
use function sprintf;

final class FixtureContainerFactory
{

	public const AlphaClassName = 'Container_5011b2e17d';

	public const BetaClassName = 'Container_6a5cfd235d';

	public const ClassNames = [
		'alpha' => self::AlphaClassName,
		'beta' => self::BetaClassName,
	];

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

		if (get_class($container) !== (self::ClassNames[$profile] ?? null)) {
			throw new LogicException(sprintf('Unexpected container class %s for profile %s.', $className, $profile));
		}

		return $container;
	}

}
