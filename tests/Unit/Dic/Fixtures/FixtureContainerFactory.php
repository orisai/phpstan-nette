<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Fixtures;

use LogicException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Extensions\DIExtension;
use Nette\DI\Extensions\ExtensionsExtension;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function basename;
use function get_class;
use function is_file;
use function md5;
use function preg_match;
use function sprintf;
use function str_replace;
use function uniqid;

final class FixtureContainerFactory
{

	public const ClassNamePattern = '~^Container_[0-9a-f]+(?:_[0-9a-f]+)?$~';

	public static function className(string $profile): string
	{
		return get_class((new self())->create($profile));
	}

	public static function receiverFixture(string $template): string
	{
		$alpha = self::className('alpha');
		$beta = self::className('beta');
		$contents = str_replace(['{{alpha}}', '{{beta}}'], [$alpha, $beta], FileSystem::read($template));

		$file = __DIR__ . '/../../../../var/tmp/DicReceiverFixtures/' . md5($alpha . $beta . $template) . '/' . basename($template, '.tpl');
		if (!is_file($file) || FileSystem::read($file) !== $contents) {
			$tmp = $file . '.' . uniqid('', true) . '.tmp';
			FileSystem::write($tmp, $contents);
			FileSystem::rename($tmp, $file);
		}

		return $file;
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
			['dic-fixture', $profile, VendorDirectory::name()],
		);

		$container = new $className([]);
		if (!$container instanceof Container) {
			throw new LogicException(sprintf('Class %s is not a container.', $className));
		}

		if (preg_match(self::ClassNamePattern, get_class($container)) !== 1) {
			throw new LogicException(sprintf('Unexpected container class %s for profile %s.', $className, $profile));
		}

		return $container;
	}

}
