<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Dic\Invalidation;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_key_exists;
use function dirname;
use function file_exists;
use function filemtime;
use function implode;
use function sort;
use function str_replace;
use function touch;

/**
 * The DIC half of the cold-vs-warm invalidation matrix. Every scenario runs the same shape - seed and
 * settle, then for each step: mutate, settle again, and compare the settled WARM error set against a
 * COLD one taken at the byte-identical tree - over the same corpus, so the only variable is the EDIT.
 *
 * The DIC extension's cross-file state is not a body-level fact the way Forms' and Latte's are: every
 * answer comes from the COMPILED Nette DI container reached through %dic.containerLoader%, and the
 * whole result cache is salted on it by ContainerResultCacheMetaExtension. So the corpus is built
 * around the salt's INPUTS rather than around file hops: a scenario-owned loader, one neon config per
 * profile, and a consumer whose every probe states the whole answer -
 *
 *  - getService('foo')->gone()  : the RESOLVED SERVICE CLASS (ServiceReturnTypeExtension)
 *  - getService('absent')       : orisaiNette.dic.serviceNotFound, carrying the PROFILE NAME LIST
 *  - getByType() / findByTag()  : orisaiNette.dic.typeNotFound / orisaiNette.dic.tagNotFound, same list
 *  - hasService('foo')          : orisaiNette.dic.hasServiceAlwaysTrue, same list
 *  - parameters['absentParam']  : the whole PARAMETER SHAPE (ParametersPropertyTypeExtension)
 *
 * so a scenario cannot quietly stop discriminating. The trait- and parent-declared probes put two of
 * those consumers in a file the container never names.
 */
abstract class DicInvalidationMatrixCase extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/../Fixtures/invalidation.neon';

	/**
	 * @param list<array{mutate: callable(InvalidationScenario): mixed, expect: list<string>, maxReanalysed?: int}> $steps
	 * each step's `expect` is the FULL error set the analysis must settle on after its mutation;
	 * `maxReanalysed` bounds the FIRST post-mutation warm run's reanalysed-file count - the inverse
	 * controls' half of the matrix, because a fix that bought cold == warm by invalidating coarsely
	 * blows that bound (or discards the cache outright) while every error-set assertion still passes
	 */
	protected function assertScenario(string $name, array $steps): void
	{
		$scenario = $this->newScenario($name);

		try {
			$this->seed($scenario);

			$seeded = $scenario->settle();
			self::assertSame(
				self::errorText($this->seedErrors()),
				$seeded->getErrorText(),
				'the seeded corpus must reach its stated state before any mutation',
			);

			foreach ($steps as $index => $step) {
				$step['mutate']($scenario);

				$firstWarm = $scenario->run();
				if (array_key_exists('maxReanalysed', $step)) {
					self::assertTrue(
						$firstWarm->isCacheRestored(),
						"step $index: the result cache must still be RESTORED after this edit, not thrown "
						. 'away wholesale: ' . $firstWarm->getDiagnostics(),
					);
					self::assertLessThanOrEqual(
						$step['maxReanalysed'],
						$firstWarm->getReanalysedFiles(),
						"step $index: this edit must stay granular - reanalysing more than "
						. $step['maxReanalysed'] . ' files means agreement was bought by invalidating coarsely',
					);
				}

				$warm = $scenario->settle();
				$cold = $scenario->cold();

				self::assertSame($warm->getErrorText(), $cold->getErrorText(), "step $index: cold == warm");
				self::assertSame(
					self::errorText($step['expect']),
					$warm->getErrorText(),
					"step $index: the settled state must be the stated one",
				);
			}
		} finally {
			$scenario->cleanup();
		}
	}

	/**
	 * @param bool $autoloadCorpus mirrors a project whose `bootstrapFiles` registers the application
	 * autoloader, which is therefore registered in the main process AND in every worker. Passing false is not a variant of the corpus but a probe:
	 * a DIC answer that changes with it is an answer read out of runtime autoload state.
	 */
	protected function settledErrorText(string $name, bool $autoloadCorpus): string
	{
		$scenario = $this->newScenario($name, $autoloadCorpus);

		try {
			$this->seed($scenario);

			return $scenario->settle()->getErrorText();
		} finally {
			$scenario->cleanup();
		}
	}

	protected function newScenario(string $name, bool $autoloadCorpus = true): InvalidationScenario
	{
		return InvalidationScenario::create(
			self::projectRoot(),
			'dic-inval-' . $name,
			static function (array $paths, string $tmpDir) use ($autoloadCorpus): string {
				$scratchDir = dirname($tmpDir);
				FileSystem::createDir($tmpDir);
				$configPath = $tmpDir . '/wrapper.neon';
				$parameters = [
					'tmpDir' => $tmpDir,
					'paths!' => $paths,
				];

				if ($autoloadCorpus) {
					$parameters['bootstrapFiles'] = [self::writeCorpusAutoloader($scratchDir, $paths)];
				}

				$parameters['orisaiNette'] = ['dic' => ['containerLoader' => $scratchDir . '/dic/loader.php']];
				FileSystem::write($configPath, Neon::encode(
					[
						'includes' => [self::REAL_CONFIG_PATH],
						'parameters' => $parameters,
					],
					true,
				));

				return $configPath;
			},
		);
	}

	/**
	 * @param list<string> $paths
	 */
	private static function writeCorpusAutoloader(string $scratchDir, array $paths): string
	{
		$path = $scratchDir . '/dic/autoload.php';
		$sourceDir = $paths[0];

		// Content, not mtime, decides whether PHPStan's result cache survives a bootstrap file, so
		// rewriting this on every spawn costs nothing.
		FileSystem::write($path, <<<PHP
<?php declare(strict_types = 1);

spl_autoload_register(static function (string \$class): void {
	\$file = '$sourceDir/' . \$class . '.php';

	if (is_file(\$file)) {
		require \$file;
	}
});

PHP);

		return $path;
	}

	protected static function projectRoot(): string
	{
		return dirname(__DIR__, 4);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('FooService.php', $this->fooService());
		$scenario->write('BarService.php', $this->barService());
		$scenario->write('MissingService.php', $this->plainService('MissingService'));
		$scenario->write('TaggedService.php', $this->plainService('TaggedService'));
		$scenario->write('SetupService.php', $this->setupService());
		$scenario->write('ParentService.php', $this->parentService());
		$scenario->write('ChildService.php', $this->childService());
		$scenario->write('HelperBase.php', $this->helperBase());
		$scenario->write('Helper.php', $this->helper());
		$scenario->write('HelperHolder.php', $this->helperHolder());
		$scenario->write('Unrelated.php', $this->unrelated('1'));
		$scenario->write('ConsumerTrait.php', $this->consumerTrait());
		$scenario->write('BaseConsumer.php', $this->baseConsumer());
		$scenario->write('Consumer.php', $this->consumer());

		$this->writeLoader($scenario, "\t'alpha' => 'alpha',\n\t'beta' => 'beta',");
		$this->writeConfig($scenario, 'alpha', $this->profileConfig('alpha'));
		$this->writeConfig($scenario, 'beta', $this->profileConfig('beta'));
	}

	/**
	 * The corpus's own container loader: the file %dic.containerLoader% points at. Its `profile name
	 * => config suffix` map is the seam the profile-identity rows move, because two profiles can share
	 * one compiled container file.
	 */
	protected function writeLoader(InvalidationScenario $scenario, string $profileMap): void
	{
		$projectRoot = dirname($scenario->getScratchDir(), 3);

		// A nowdoc with placeholders rather than an interpolating heredoc: the generated code contains
		// `new $className([])`, and an escaped `\$` inside a heredoc is tokenised as a namespace
		// separator by the coding standard.
		$loader = <<<'PHP'
<?php declare(strict_types = 1);

require_once '{{ROOT}}/tests/autoload.php';

$dir = __DIR__;
$src = dirname(__DIR__) . '/src';

// Registered only for the compilation, then withdrawn: a leaked autoloader is invoked by PHPStan's
// AutoloadSourceLocator under a stream-wrapper trap, which a project's own container loader has to
// guard against for its application autoloader too.
$autoloader = static function (string $class) use ($src): void {
	$file = $src . '/' . $class . '.php';

	if (is_file($file)) {
		require $file;
	}
};
spl_autoload_register($autoloader);

$profiles = [
{{PROFILES}}
];

$loader = new Nette\DI\ContainerLoader($dir . '/cache', true);
$containers = [];

foreach ($profiles as $profile => $suffix) {
	$className = $loader->load(
		static function (Nette\DI\Compiler $compiler) use ($dir, $suffix): void {
			$compiler->addExtension('extensions', new Nette\DI\Extensions\ExtensionsExtension());
			$compiler->addExtension('di', new Nette\DI\Extensions\DIExtension());
			$compiler->loadConfig($dir . '/config-' . $suffix . '.neon');
		},
		['dic-inval', $suffix],
	);

	$containers[$profile] = new $className([]);
}

spl_autoload_unregister($autoloader);

return $containers;

PHP;

		self::writeTouched(
			$this->dicDir($scenario) . '/loader.php',
			str_replace(['{{ROOT}}', '{{PROFILES}}'], [$projectRoot, $profileMap], $loader),
		);
	}

	protected function writeConfig(InvalidationScenario $scenario, string $profile, string $contents): void
	{
		self::writeTouched($this->dicDir($scenario) . '/config-' . $profile . '.neon', $contents);
	}

	protected function readConfig(InvalidationScenario $scenario, string $profile): string
	{
		return FileSystem::read($this->dicDir($scenario) . '/config-' . $profile . '.neon');
	}

	protected function dicDir(InvalidationScenario $scenario): string
	{
		return $scenario->getScratchDir() . '/dic';
	}

	/**
	 * Nette's DependencyChecker compares whole-second filemtime() values with !==, so a config
	 * rewritten inside the same second as the previous compile is invisible to autoRebuild and the
	 * step would score a pass whose maximum possible result was a pass.
	 */
	private static function writeTouched(string $path, string $contents): void
	{
		// Seeded from this file's own mtime rather than the wall clock, so every write moves the mtime
		// forward regardless of the clock's resolution.
		$previous = file_exists($path) ? (int) filemtime($path) : (int) filemtime(__FILE__);
		FileSystem::write($path, $contents);
		touch($path, $previous + 1);
	}

	/**
	 * @param string|null $sharedParam retypes the shared parameter without touching the file SET or any
	 * container path, so a salt that hashes paths only cannot see the edit
	 * @param bool $setupCall drops the setup call whose only observable channel is shipmonk.deadMethod
	 */
	protected function profileConfig(string $profile, ?string $sharedParam = null, bool $setupCall = true): string
	{
		$shared = $sharedParam ?? "$profile-value";
		$setup = $setupCall ? "\n\t\tsetup:\n\t\t\t- configure()" : '';

		return <<<NEON
parameters:
	sharedParam: $shared
	profileParam: $profile-only

services:
	foo: FooService
	bar: BarService
	child:
		factory: ChildService
		setup:
			- inherited()
	tagged:
		factory: TaggedService
		tags:
			probe.tag: true
	setupSvc:
		factory: SetupService$setup
	helperHolder: HelperHolder(Helper())
	fooAlias: @foo

NEON;
	}

	/**
	 * The seeded corpus's settled error set, parameterised by the two things the matrix moves: the
	 * profile name list every rule message prints, and the parameter shape.
	 *
	 * The shipmonk.deadMethod half is stated rather than ignored: it is the only channel that can see
	 * DicUsageProvider, whose answer for a container-constructed class is not a pure function of the
	 * container files. Two of the corpus's methods are alive purely through it - SetupService::configure
	 * (called directly on the constructed class) and ParentService::inherited (called on a DESCENDANT
	 * the container constructs) - so their absence from this list is the assertion.
	 *
	 * @return list<string>
	 */
	protected function seedErrors(
		string $profiles = 'alpha, beta',
		string $parameterShape = 'array{sharedParam: string, profileParam: string}'
	): array
	{
		return [
			'BarService.php:6 :: shipmonk.deadMethod :: Unused BarService::used',
			'BaseConsumer.php:6 :: shipmonk.deadMethod :: Unused BaseConsumer::baseProbe',
			'ChildService.php:6 :: shipmonk.deadMethod :: Unused ChildService::own',
			'Consumer.php:8 :: shipmonk.deadMethod :: Unused Consumer::probe',
			'Consumer.php:19 :: shipmonk.deadMethod :: Unused Consumer::parametersProbe',
			'Consumer.php:24 :: shipmonk.deadMethod :: Unused Consumer::serviceTypeProbe',
			'ConsumerTrait.php:6 :: shipmonk.deadMethod :: Unused ConsumerTrait::traitProbe',
			'FooService.php:6 :: shipmonk.deadMethod :: Unused FooService::used',
			'HelperHolder.php:13 :: shipmonk.deadMethod :: Unused HelperHolder::get',
			'MissingService.php:6 :: shipmonk.deadMethod :: Unused MissingService::used',
			'TaggedService.php:6 :: shipmonk.deadMethod :: Unused TaggedService::used',
			'Unrelated.php:6 :: shipmonk.deadMethod :: Unused Unrelated::value',
			'BaseConsumer.php:8 :: method.notFound :: Call to an undefined method ChildService::baseGone().',
			'Consumer.php:10 :: method.notFound :: Call to an undefined method FooService::gone().',
			'Consumer.php:11 :: method.notFound :: Call to an undefined method FooService::aliasGone().',
			"Consumer.php:12 :: orisaiNette.dic.serviceNotFound :: Service 'absent' is not registered in any "
				. "analysed container ($profiles).",
			'Consumer.php:13 :: orisaiNette.dic.typeNotFound :: Type MissingService is not registered in any '
				. "analysed container ($profiles).",
			"Consumer.php:14 :: orisaiNette.dic.tagNotFound :: Tag 'absent.tag' is not present in any analysed "
				. "container ($profiles).",
			"Consumer.php:16 :: orisaiNette.dic.hasServiceAlwaysTrue :: Service 'foo' is registered in every "
				. "analysed container ($profiles); hasService() always returns true.",
			"Consumer.php:21 :: offsetAccess.notFound :: Offset 'absentParam' does not exist on "
				. "$parameterShape.",
			'Consumer.php:26 :: return.type :: Method Consumer::serviceTypeProbe() should return int '
				. 'but returns string.',
			'ConsumerTrait.php (in context of class Consumer):8 :: method.notFound :: Call to an '
				. 'undefined method BarService::traitGone().',
		];
	}

	protected function fooService(string $body = "\t\treturn 1;"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class FooService
{

	public function used(): int
	{
$body
	}

}

PHP;
	}

	protected function barService(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class BarService
{

	public function used(): int
	{
		return 2;
	}

}

PHP;
	}

	protected function plainService(string $name): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class $name
{

	public function used(): int
	{
		return 3;
	}

}

PHP;
	}

	protected function setupService(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class SetupService
{

	public function configure(): void
	{
	}

}

PHP;
	}

	protected function parentService(string $body = "\t\treturn 10;"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

abstract class ParentService
{

	public function inherited(): int
	{
$body
	}

}

PHP;
	}

	protected function childService(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class ChildService extends ParentService
{

	public function own(): int
	{
		return 11;
	}

}

PHP;
	}

	/**
	 * Helper is CONSTRUCTED by the container (as an inline argument of helperHolder) but is not a
	 * service, so it is absent from the compiled $wiring - which is what makes its hierarchy the one
	 * cross-file fact a container-file digest provably cannot see. An `extends` edit on a registered
	 * service moves the container bytes, because $wiring carries every ancestor of every service.
	 */
	protected function helperBase(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

abstract class HelperBase
{

	public function __construct()
	{
	}

}

PHP;
	}

	protected function helper(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class Helper extends HelperBase
{

}

PHP;
	}

	// The same constructed class, detached from the ancestor that declared the constructor the
	// container calls. `new Helper` either way, so not one container byte moves.
	protected function detachedHelper(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class Helper
{

	public function __construct()
	{
	}

}

PHP;
	}

	protected function helperHolder(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class HelperHolder
{

	private object $helper;

	public function __construct(object $helper)
	{
		$this->helper = $helper;
	}

	public function get(): object
	{
		return $this->helper;
	}

}

PHP;
	}

	protected function unrelated(string $returned): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class Unrelated
{

	public function value(): int
	{
		return $returned;
	}

}

PHP;
	}

	// The trait hop: a DIC consumer whose call site lives in a file the container never names.
	protected function consumerTrait(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

trait ConsumerTrait
{

	public function traitProbe(\Nette\DI\Container $container): void
	{
		$container->getService('bar')->traitGone();
	}

}

PHP;
	}

	// The parent-class hop.
	protected function baseConsumer(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

abstract class BaseConsumer
{

	public function baseProbe(\Nette\DI\Container $container): void
	{
		$container->getService('child')->baseGone();
	}

}

PHP;
	}

	// probe() sits FIRST on purpose: every line number is asserted, so no row that adds a member to
	// this class may be able to shift them.
	protected function consumer(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class Consumer extends BaseConsumer
{

	use ConsumerTrait;

	public function probe(\Nette\DI\Container $container): void
	{
		$container->getService('foo')->gone();
		$container->getService('fooAlias')->aliasGone();
		$container->getService('absent');
		$container->getByType(MissingService::class);
		$container->findByTag('absent.tag');
		$container->findByTag('probe.tag');
		$container->hasService('foo');
	}

	public function parametersProbe(\Nette\DI\Container $container): int
	{
		return $container->parameters['absentParam'];
	}

	public function serviceTypeProbe(\Nette\DI\Container $container): int
	{
		return $container->getServiceType('foo');
	}

}

PHP;
	}

	/**
	 * @param list<string> $errors
	 */
	protected static function errorText(array $errors): string
	{
		sort($errors);

		return $errors === [] ? '(no errors)' : implode("\n", $errors);
	}

}
