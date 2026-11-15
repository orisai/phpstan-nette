<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Support;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Parser\LatteRoutingParser;
use PHPStan\Parser\PathRoutingParser;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function dirname;
use function sprintf;

final class PharServiceWiringTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const Resync = 'The %s service of PHPStan\'s conf/%s is not the one this library mirrors. Re-sync config/latte.neon and config/forms.neon'
		. ' (latteInnerAnalysisParser, latteInnerCacheStorage, latteInnerSourceLocatorFactory, the parsers the compile pipeline and Forms use)'
		. ' and docs/internals/phpstan-workarounds.md#service-replacements, then update this test.';

	/**
	 * @return list<string>
	 */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/phpstan-wiring.neon'];
	}

	/**
	 * @return iterable<string, array{string, string, array<string, mixed>}>
	 */
	public static function provideMirroredDefinitions(): iterable
	{
		yield 'defaultAnalysisParser' => ['parsers.neon', 'defaultAnalysisParser', [
			'factory' => '@pathRoutingParser',
			'autowired' => false,
		]];

		yield 'pathRoutingParser' => ['parsers.neon', 'pathRoutingParser', [
			'class' => PathRoutingParser::class,
			'arguments' => [
				'currentPhpVersionRichParser' => '@cachedRichParser',
				'currentPhpVersionSimpleParser' => '@cachedSimpleParser',
				'php8Parser' => '@php8Parser',
				'singleReflectionFile' => '%singleReflectionFile%',
			],
			'autowired' => false,
		]];

		yield 'currentPhpVersionSimpleDirectParser' => ['parsers.neon', 'currentPhpVersionSimpleDirectParser', [
			'class' => 'PHPStan\Parser\SimpleParser',
			'arguments' => ['parser' => '@currentPhpVersionPhpParser'],
			'autowired' => false,
		]];

		yield 'currentPhpVersionRichParser' => ['parsers.neon', 'currentPhpVersionRichParser', [
			'class' => 'PHPStan\Parser\RichParser',
			'arguments' => ['parser' => '@currentPhpVersionPhpParser'],
			'autowired' => false,
		]];

		yield 'cacheStorage' => ['services.neon', 'cacheStorage', [
			'class' => 'PHPStan\Cache\FileCacheStorage',
			'arguments' => ['directory' => '%tmpDir%/cache/PHPStan'],
			'autowired' => false,
		]];

		yield 'betterReflectionSourceLocator' => ['services.neon', 'betterReflectionSourceLocator', [
			'factory' => '@PHPStan\Reflection\BetterReflection\BetterReflectionSourceLocatorFactory::create',
			'autowired' => false,
		]];
	}

	/**
	 * @param array<string, mixed> $expected
	 *
	 * @dataProvider provideMirroredDefinitions
	 */
	public function testPharDefinitionIsTheMirroredOne(string $file, string $service, array $expected): void
	{
		$config = Neon::decode(FileSystem::read(self::pharRoot() . '/conf/' . $file));
		self::assertIsArray($config);
		self::assertIsArray($config['services']);

		self::assertSame($expected, $config['services'][$service] ?? null, sprintf(self::Resync, $service, $file));
	}

	public function testAnalysisParserDelegatesToThePathRoutingParser(): void
	{
		$container = self::getContainer();
		$parser = $container->getService('defaultAnalysisParser');
		self::assertInstanceOf(LatteRoutingParser::class, $parser);

		$pathRoutingParser = $container->getService('pathRoutingParser');
		self::assertInstanceOf(PathRoutingParser::class, $pathRoutingParser);

		$delegate = new ReflectionProperty(LatteRoutingParser::class, 'delegate');
		$delegate->setAccessible(true);

		self::assertSame(
			$pathRoutingParser,
			$delegate->getValue($parser),
			sprintf(self::Resync, 'defaultAnalysisParser', 'parsers.neon'),
		);
	}

	private static function pharRoot(): string
	{
		$fileName = (new ReflectionClass(PathRoutingParser::class))->getFileName();
		self::assertNotFalse($fileName);

		return dirname($fileName, 3);
	}

}
