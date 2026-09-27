<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use function array_key_exists;
use function dirname;
use function implode;
use function sort;

/**
 * The Forms half of the cold-vs-warm invalidation matrix. Every scenario in every subclass runs the
 * same shape - seed and settle, then for each step: mutate, settle again, and compare the settled
 * WARM error set against a COLD one taken at the byte-identical tree - over the corpus below, so the
 * only variable is the EDIT. A subclass whose channel this corpus cannot express (the property-held
 * form needs a class split across files, which only a trait gives) overrides seed() and seedErrors()
 * with its own; everything else it runs is the machinery here.
 *
 * The corpus is built so that every consumer sits in a DIFFERENT file from the definer whose body
 * supplies the field it reads: ScratchChildControl reads four component shapes whose contributors
 * are ScratchForm (a form subclass constructor), ScratchSharedMethods (a shared add*() helper two
 * files away), ScratchParentControl (an inherited factory), ScratchGrandParentControl (two classes
 * up) and ScratchFactoryTrait (a trait-declared factory). Every one of those contributions is a
 * pure METHOD BODY fact: editing it leaves every signature byte-identical, which is exactly the
 * class of edit PHPStan's own result cache does not propagate to dependents
 * (ResultCacheManager::restore() needs exportedNodesChanged() !== null before it looks at
 * dependentFiles). A corpus whose cross-file facts lived in signatures could never tell a holed
 * consumer from a safe one.
 *
 * dumpFormValues() is the consumer of record because its message IS the resolved shape, so every
 * scenario states the whole answer and cannot quietly stop discriminating. The one hand-written
 * `$this['form']['ctorField']->setRequired()` consumer is there because it reproduces the
 * PRODUCTION failure in miniature: when the field goes, PHPStan's own CallMethodsRule reports
 * `Call to an undefined method Nette\ComponentModel\IComponent::setRequired()` - a real error a
 * warm run used to swallow.
 */
abstract class FormsInvalidationMatrixCase extends BaseTestCase
{

	protected const FORM_DUMP_LINE = 12;

	protected const SHARED_DUMP_LINE = 13;

	protected const GRAND_DUMP_LINE = 14;

	protected const TRAIT_DUMP_LINE = 15;

	protected const FACTORY_DUMP_LINE = 16;

	protected const SET_REQUIRED_LINE = 17;

	private const REAL_CONFIG_PATH = __DIR__ . '/../Fixtures/invalidation.neon';

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
		?array $expectedSeedErrors = null
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
				self::errorText($expectedSeedErrors ?? $this->seedErrors()),
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
	 * @return list<string>
	 */
	protected function seedErrors(): array
	{
		return $this->dumps();
	}

	/**
	 * The five consumer dumps, defaulted to the seeded corpus's answer so a scenario states only
	 * the shapes its edit is supposed to move - and, because every one of the five is always
	 * stated, cannot silently stop discriminating on the other four.
	 *
	 * @return list<string>
	 */
	protected function dumps(
		string $form = 'ctorField: string, childField: string, pairField: string',
		string $shared = 'ctorField: string, sharedField: string',
		string $grand = 'ctorField: string, grandField: string',
		string $trait = 'ctorField: string, traitField: string',
		string $factory = 'ctorField: string, factoryField: string'
	): array
	{
		return [
			$this->valuesDump(self::FORM_DUMP_LINE, $form),
			$this->valuesDump(self::SHARED_DUMP_LINE, $shared),
			$this->valuesDump(self::GRAND_DUMP_LINE, $grand),
			$this->valuesDump(self::TRAIT_DUMP_LINE, $trait),
			$this->valuesDump(self::FACTORY_DUMP_LINE, $factory),
		];
	}

	protected function valuesDump(int $line, string $shape, string $file = 'ScratchChildControl.php'): string
	{
		return $this->rawDump($line, 'Nette\Utils\ArrayHash{' . $shape . '}', $file);
	}

	// For the rows whose component stops resolving to a shape at all.
	protected function rawDump(int $line, string $rendered, string $file = 'ScratchChildControl.php'): string
	{
		return $file . ':' . $line . ' :: orisaiNette.forms.dump :: ' . $rendered;
	}

	protected function undefinedSetRequired(): string
	{
		return 'ScratchChildControl.php:' . self::SET_REQUIRED_LINE
			. ' :: method.notFound :: Call to an undefined method '
			. 'Nette\ComponentModel\IComponent::setRequired().';
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
			'forms-inval-' . $name,
			fn (array $paths, string $tmpDir): string => IsolatedPhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				$paths,
				$tmpDir,
				$this->extraParameters,
			)->getConfigPath(),
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('ScratchSharedMethods.php', $this->sharedMethodsSource());
		$scenario->write('ScratchForm.php', $this->formSource());
		$scenario->write('ScratchFactoryTrait.php', $this->factoryTraitSource());
		$scenario->write('ScratchGrandParentControl.php', $this->grandParentSource());
		$scenario->write('ScratchParentControl.php', $this->parentSource());
		$scenario->write('ScratchFormFactory.php', $this->formFactorySource());
		$scenario->write('ScratchChildControl.php', $this->childSource());
		$scenario->write('ScratchUnrelated.php', $this->unrelatedSource('1'));
	}

	// The delegate hop: the consumer reads $this['factoryMade'], whose factory only forwards to
	// this class, so the answer is a property of a file the consumer never names.
	protected function formFactorySource(
		string $body = "\t\t\$form->addText('factoryField');\n",
		string $docBlock = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class ScratchFormFactory
{
$docBlock
	public function create(): ScratchForm
	{
		\$form = new ScratchForm();
$body
		return \$form;
	}

}

PHP;
	}

	/**
	 * The shared add*() helper: two files away from the consumer, and the only contributor whose
	 * edit has to travel trait -> form subclass -> factory -> consumer. Its mutation renames the
	 * added field rather than retyping it: the value type comes from the DECLARED return type, so
	 * a retype would have to move the signature too - i.e. stop being the body-only edit this row
	 * exists to test - while the field NAME is read out of the body alone.
	 */
	protected function sharedMethodsSource(
		string $addedName = '$name',
		string $returnType = ': \Nette\Forms\Controls\TextInput',
		string $docBlock = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

trait ScratchSharedMethods
{
$docBlock
	public function addPair(string \$name)$returnType
	{
		return \$this->addText($addedName);
	}

}

PHP;
	}

	protected function formSource(
		string $body = "\t\t\$this->addText('ctorField');\n",
		string $members = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class ScratchForm extends \Nette\Application\UI\Form
{

	use ScratchSharedMethods;
$members
	public function __construct()
	{
		parent::__construct();
$body	}

}

PHP;
	}

	protected function factoryTraitSource(string $body = "\t\t\$form->addText('traitField');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

trait ScratchFactoryTrait
{

	protected function createComponentTraitForm(): ScratchForm
	{
		\$form = new ScratchForm();
$body
		return \$form;
	}

}

PHP;
	}

	protected function grandParentSource(string $body = "\t\t\$form->addText('grandField');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

abstract class ScratchGrandParentControl extends \Nette\Application\UI\Control
{

	protected function createComponentGrand(): ScratchForm
	{
		\$form = new ScratchForm();
$body
		return \$form;
	}

}

PHP;
	}

	protected function parentSource(
		string $body = "\t\t\$form->addText('sharedField');\n",
		string $extraMembers = '',
		string $returnType = ': ScratchForm',
		string $docBlock = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

abstract class ScratchParentControl extends ScratchGrandParentControl
{
$extraMembers$docBlock
	protected function createComponentShared()$returnType
	{
		\$form = new ScratchForm();
$body
		return \$form;
	}

}

PHP;
	}

	protected function parentSourceWithoutSharedFactory(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

abstract class ScratchParentControl extends ScratchGrandParentControl
{

}

PHP;
	}

	protected function extraControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchExtraControl extends \Nette\Application\UI\Control
{

	public function probe(): void
	{
		dumpFormValues($this['extra']);
	}

	protected function createComponentExtra(): ScratchForm
	{
		$form = new ScratchForm();
		$form->addText('extraField');

		return $form;
	}

}

PHP;
	}

	// ScratchUnrelated and ScratchExtraControl in ONE file: the "class moved between two existing
	// files" row's destination. The dump lands on line 20 of this layout.
	protected function unrelatedWithExtraControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchUnrelated
{

	public function value(): int
	{
		return 1;
	}

}

final class ScratchExtraControl extends \Nette\Application\UI\Control
{

	public function probe(): void
	{
		dumpFormValues($this['extra']);
	}

	protected function createComponentExtra(): ScratchForm
	{
		$form = new ScratchForm();
		$form->addText('extraField');

		return $form;
	}

}

PHP;
	}

	// probe() sits FIRST on purpose: every dump line number is asserted, so no scenario that adds a
	// member to this class may be able to shift them.
	protected function childSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchChildControl extends ScratchParentControl
{

	use ScratchFactoryTrait;

	public function probe(): void
	{
		dumpFormValues($this['form']);
		dumpFormValues($this['shared']);
		dumpFormValues($this['grand']);
		dumpFormValues($this['traitForm']);
		dumpFormValues($this['factoryMade']);
		$this['form']['ctorField']->setRequired();
	}

	protected function createComponentForm(): ScratchForm
	{
		$form = new ScratchForm();
		$form->addText('childField');
		$form->addPair('pairField');

		return $form;
	}

	protected function createComponentFactoryMade(): ScratchForm
	{
		$factory = new ScratchFormFactory();

		return $factory->create();
	}

}

PHP;
	}

	// Deliberately free of every construct FormFactSalt treats as shape-affecting, so it is OUTSIDE
	// the salted subset - which is what makes "a body edit here must not discard the cache" a real
	// control rather than a restatement of the fix.
	protected function unrelatedSource(string $returned, string $comment = ''): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class ScratchUnrelated
{
$comment
	public function value(): int
	{
		return $returned;
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
