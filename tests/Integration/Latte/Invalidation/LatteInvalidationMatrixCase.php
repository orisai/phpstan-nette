<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function array_key_exists;
use function dirname;
use function implode;

// The Latte half of the cold-vs-warm invalidation matrix. Every scenario in every subclass runs the
// same shape - seed and settle, then for each step: mutate, settle again, and compare the settled
// WARM error set against a COLD one taken at the byte-identical tree - over the same corpus, so the
// only variable is the EDIT. That is what makes the matrix a matrix: a scenario passing tells you
// the edit class it names propagates, not merely that some analysis agreed with itself.
//
// The corpus pairs its template through the CONVENTION channel (a `return X::class;` inside
// formatTemplateClass(), PhpRenderWalk::CONVENTION_HOOK_METHODS) rather than through a class
// docblock, precisely because the convention hook is a pure METHOD BODY fact: an edit to it leaves
// every signature byte-identical, which is the class of edit PHPStan's own result cache does not
// propagate to dependents (ResultCacheManager branch (E) needs exportedNodesChanged() !== null).
// A docblock-paired corpus would move an exported node on every edit and could never tell a holed
// consumer from a safe one.
//
// Every scenario states the FULL error set it must settle on. A matrix whose scenarios only assert
// "cold == warm" is worthless: an analysis that reports nothing at all agrees with itself perfectly.
abstract class LatteInvalidationMatrixCase extends BaseTestCase
{

	protected const BASE_TEMPLATE = 'ScratchBaseTemplate';

	protected const OTHER_TEMPLATE = 'ScratchOtherTemplate';

	protected const CONTROL = 'ScratchControl';

	protected const SECOND_CONTROL = 'ScratchSecondControl';

	private const REAL_CONFIG_PATH = __DIR__ . '/../Integration/Fixtures/integration.neon';

	/** @var array<string, bool|int|string|list<string>> */
	private array $extraParameters = [];

	/**
	 * @param list<array{mutate: callable(InvalidationScenario): mixed, expect: list<string>, maxReanalysed?: int}> $steps
	 * each step's `expect` is the FULL error set the analysis must settle on after its mutation;
	 * `maxReanalysed` bounds the FIRST post-mutation warm run's reanalysed-file count - the inverse
	 * controls' half of the matrix, because a fix that bought cold == warm by invalidating coarsely
	 * blows that bound (or discards the cache outright) while every error-set assertion still passes
	 * @param (callable(InvalidationScenario): mixed)|null $extraSeed applied after the standard corpus
	 * @param list<string> $expectedSeedErrors the seeded corpus's own settled error set
	 */
	protected function assertScenario(
		string $name,
		array $steps,
		?callable $extraSeed = null,
		array $expectedSeedErrors = []
	): void
	{
		$scenario = $this->newScenario($name);

		try {
			$this->seed($scenario);

			if ($extraSeed !== null) {
				$extraSeed($scenario);
			}

			$seeded = $scenario->settle();
			self::assertSame(
				self::errorText($expectedSeedErrors),
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

	protected function mismatchError(
		string $pairedTemplate,
		string $templateRelPath = 'shared.latte',
		string $renderer = self::CONTROL,
		string $declaredTemplate = self::BASE_TEMPLATE,
		int $line = 1
	): string
	{
		return $templateRelPath . ':' . $line . ' :: ' . TemplateTypeChecker::MISMATCH_IDENTIFIER
			. ' :: Template declares {templateType ' . $declaredTemplate . '} but renderer ' . $renderer
			. ' pairs ' . $pairedTemplate . '.';
	}

	/**
	 * @param bool|int|string|list<string> $value
	 */
	protected function setParameter(string $name, $value): void
	{
		$this->extraParameters[$name] = $value;
	}

	protected function newScenario(string $name): InvalidationScenario
	{
		$projectRoot = dirname(__DIR__, 4);

		return InvalidationScenario::create(
			$projectRoot,
			'latte-inval-' . $name,
			fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				[
					'orisai.nette.latte.discovery.enabled' => true,
					'orisai.nette.latte.discovery.storePath' => $paths[0] . '/discovery',
					'orisai.nette.latte.firstPartyPaths' => [$paths[0]],
				] + $this->extraParameters,
				[dirname($paths[0]) . '/corpus-autoload.php'],
			)->getConfigPath(),
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$this->writeCorpusAutoloader($scenario);

		$scenario->write(self::BASE_TEMPLATE . '.php', $this->templateSource(self::BASE_TEMPLATE));
		$scenario->write(self::OTHER_TEMPLATE . '.php', $this->templateSource(self::OTHER_TEMPLATE));
		$scenario->write(self::CONTROL . '.php', $this->controlSource(self::CONTROL, self::BASE_TEMPLATE));
		$scenario->write('shared.latte', $this->templateFileSource(self::BASE_TEMPLATE));

		DiscoveryStore::bootstrap(
			$scenario->getSourceDir() . '/discovery',
			[$scenario->projectRelative('shared.latte')],
		);
	}

	protected function templateFileSource(?string $templateTypeClass, string $body = "<p>shared</p>\n"): string
	{
		return ($templateTypeClass === null ? '' : '{templateType ' . $templateTypeClass . "}\n") . $body;
	}

	// Two unrelated siblings of the same vendor base by default: neither is a subtype of the other,
	// so the widening allowance in checkTemplateTypeMismatch() can never absorb a swap between them.
	protected function templateSource(
		string $className,
		string $parent = '\Nette\Bridges\ApplicationLatte\Template',
		string $members = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class $className extends $parent
{
$members
}

PHP;
	}

	// formatTemplateClass() rather than getTemplateClass(): both are convention hooks to
	// PhpRenderWalk, but only this one OVERRIDES a vendor method, which is what keeps
	// shipmonk.deadMethod out of every expected error set in the matrix.
	protected function controlSource(
		string $className,
		?string $pairedTemplate,
		string $templateRelPath = 'shared.latte',
		string $parent = '\Nette\Application\UI\Control'
	): string
	{
		$hook = $pairedTemplate === null
			? ''
			: <<<PHP

	public function formatTemplateClass(): ?string
	{
		return $pairedTemplate::class;
	}

PHP;

		return <<<PHP
<?php declare(strict_types = 1);

class $className extends $parent
{
$hook
	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/$templateRelPath');
	}

}

PHP;
	}

	/**
	 * @param list<string> $errors
	 */
	private static function errorText(array $errors): string
	{
		return $errors === [] ? '(no errors)' : implode("\n", $errors);
	}

	// {templateType X} resolution goes through class_exists(), i.e. the RUNTIME autoloader, so a
	// scratch corpus is invisible to it without this - and an unresolvable {templateType} degrades
	// the corpus into one where the template carries no class dependency edge at all, which is
	// exactly the edge the signature-level control scenarios have to exercise. Glob-based so a
	// scenario that ADDS or REMOVES a class file needs no second edit here. Lazy rather than an
	// eager require sweep: a corpus whose classes extend each other has no load order a directory
	// listing can produce, while an autoloader resolves each parent on demand. The glob fallback is
	// for the one scenario that deliberately breaks the name-to-file mapping (a class MOVED into a
	// file named after another class).
	private function writeCorpusAutoloader(InvalidationScenario $scenario): void
	{
		$autoloader = dirname(__DIR__, 4) . '/tests/autoload.php';

		FileSystem::write(
			$scenario->getScratchDir() . '/corpus-autoload.php',
			<<<PHP
<?php declare(strict_types = 1);

require_once '$autoloader';

spl_autoload_register(static function (string \$class): void {
	\$direct = __DIR__ . '/src/' . \$class . '.php';
	if (is_file(\$direct)) {
		require_once \$direct;

		return;
	}

	foreach (glob(__DIR__ . '/src/*.php') ?: [] as \$file) {
		require_once \$file;
	}
});

PHP,
		);
	}

}
