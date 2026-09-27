<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

/**
 * The property-held form channel's own rows of the matrix, over its own corpus.
 *
 * It needs them because that channel introduced a new PERSISTED interprocedural key
 * (InterproceduralShapeKey::forClassProperty, `prop:<fqcn>#<name>`) whose entry outlives the result
 * cache — cold() deletes resultCache.php alone, and the shape store lives beside it under the same
 * tmpDir. Cross-file state cached per file is the bug class this project has already paid for three
 * times, and no assertion over the component-slot key can speak for a key that did not exist when
 * they were written.
 *
 * The corpus is shaped so the builder and the consumer sit in DIFFERENT FILES, which for a property
 * channel takes a trait: a private property is legally touchable only from the declaring class's own
 * bodies, and a trait method is one of those while living in a file of its own. So
 * ScratchPropertyBuilder::build() binds and configures the form, ScratchPropertyForm's own
 * constructor contributes a field one file further out, and ScratchPropertyHolder declares the
 * property and reads it. Every edit below leaves every exported node of the edited file
 * byte-identical except the visibility one, which is a declaration edit on purpose.
 */
final class PropertyFormInvalidationTest extends FormsInvalidationMatrixCase
{

	private const HOLDER_DUMP_LINE = 14;

	private const HOLDER_SET_REQUIRED_LINE = 15;

	/**
	 * The row that matters: a control added to the BUILDER'S BODY, in the file the consumer never
	 * names. Step 1 edits the held form CLASS's constructor body instead, one file further out — the
	 * `prop:` entry has to depend on that file too, since the fold reads the constructed class's own
	 * adds through it.
	 */
	public function testControlAddedToTheBuilderBody(): void
	{
		$this->assertScenario('p1-builder-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyBuilder.php',
					$this->builderSource(
						"\t\t\$this->heldForm->addText('builtField');\n"
						. "\t\t\$this->heldForm->addText('addedField');\n",
					),
				),
				'expect' => [$this->heldDump('ctorField: string, builtField: string, addedField: string')],
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyForm.php',
					$this->heldFormSource("\t\t\$this->addCheckbox('ctorField');\n"),
				),
				'expect' => [$this->heldDump('ctorField: bool, builtField: string, addedField: string')],
			],
		]);
	}

	/**
	 * The stale NEGATIVE in the other direction: when the builder stops adding the field, the
	 * consumer's `$this->heldForm['builtField']->setRequired()` becomes a real method.notFound that a
	 * warm run must not go on swallowing.
	 */
	public function testControlRemovedFromTheBuilderBody(): void
	{
		$this->assertScenario('p2-builder-removal', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyBuilder.php',
					$this->builderSource(''),
				),
				'expect' => [
					$this->heldDump('ctorField: string'),
					$this->undefinedSetRequiredOnHeldForm(),
				],
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyBuilder.php',
					$this->builderSource(),
				),
				'expect' => $this->seedErrors(),
			],
		]);
	}

	/**
	 * The openness decision is read off the property DECLARATION, so flipping its visibility has to
	 * move the shape from closed to open and back — on a warm run, from a stored `prop:` entry the
	 * declaration edit alone has to invalidate. The consumer stays in the same file as the
	 * declaration (nothing else can read a private property), so what this row discriminates is not
	 * whether PHPStan re-queues the consumer but whether the SHAPE it recomputes with is a fresh one.
	 */
	public function testPropertyVisibilityFlipsTheShapeOpen(): void
	{
		$this->assertScenario('p3-visibility', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyHolder.php',
					$this->holderSource('public'),
				),
				'expect' => [$this->heldDump('ctorField: string, builtField: string, ...<mixed>')],
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyHolder.php',
					$this->holderSource(),
				),
				'expect' => $this->seedErrors(),
			],
		]);
	}

	/**
	 * The inverse control, and the only reason the rows above prove anything: a comment-only edit to
	 * the very file whose body edit moves the shape must leave the cache restored and granular. A fix
	 * that bought cold == warm by invalidating the property channel coarsely passes every assertion
	 * above and fails here — it discards the cache wholesale (isCacheRestored) or reanalyses the
	 * corpus (the bound).
	 *
	 * The held form's file is where this discriminates hardest: it carries an `addText` so it is in
	 * the salt's subset, and step 1 of the builder-body row proves an edit to it really does move the
	 * consumer's shape, yet a COMMENT there must reach nobody. The bound is 1 — itself and nothing
	 * else.
	 */
	public function testCommentOnlyEditInTheHeldFormFileStaysGranular(): void
	{
		$this->assertScenario('p4-comment-only-form', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyForm.php',
					$this->heldFormSource(
						"\t\t// the field every consumer of this form reads\n"
						. "\t\t\$this->addText('ctorField');\n",
					),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	/**
	 * The same control on the BUILDER's file, whose floor is 2 rather than 1 for a reason that is
	 * PHPStan's and not this extension's: a trait's cached exported nodes hold an ExportedTraitNode,
	 * and ResultCacheManager::restore() queues a changed file's dependents whenever one is present —
	 * unconditionally, since a trait body has no exported representation to compare. So the holder
	 * comes along however inert the edit was. What is still asserted here is everything this channel
	 * owns: the cache is RESTORED (a raw-content salt would have thrown it away) and the third file
	 * of the corpus is not touched.
	 */
	public function testCommentOnlyEditInTheBuilderFileStaysGranular(): void
	{
		$this->assertScenario('p5-comment-only-builder', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchPropertyBuilder.php',
					$this->builderSource(
						"\t\t// the field the holder's consumer reads\n"
						. "\t\t\$this->heldForm->addText('builtField');\n",
					),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 2,
			],
		]);
	}

	/**
	 * @return list<string>
	 */
	protected function seedErrors(): array
	{
		return [$this->heldDump('ctorField: string, builtField: string')];
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('ScratchPropertyForm.php', $this->heldFormSource());
		$scenario->write('ScratchPropertyBuilder.php', $this->builderSource());
		$scenario->write('ScratchPropertyHolder.php', $this->holderSource());
	}

	private function heldDump(string $shape): string
	{
		return $this->valuesDump(self::HOLDER_DUMP_LINE, $shape, 'ScratchPropertyHolder.php');
	}

	private function undefinedSetRequiredOnHeldForm(): string
	{
		return 'ScratchPropertyHolder.php:' . self::HOLDER_SET_REQUIRED_LINE
			. ' :: method.notFound :: Call to an undefined method '
			. 'Nette\ComponentModel\IComponent::setRequired().';
	}

	private function heldFormSource(string $body = "\t\t\$this->addText('ctorField');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class ScratchPropertyForm extends \Nette\Application\UI\Form
{

	public function __construct()
	{
		parent::__construct();
$body	}

}

PHP;
	}

	// The binder lives in a trait so that a body edit here is an edit to a file the consumer never
	// names, while still being a body of the class that declares the private property.
	private function builderSource(string $body = "\t\t\$this->heldForm->addText('builtField');\n"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

trait ScratchPropertyBuilder
{

	private function build(): void
	{
		\$this->heldForm = new ScratchPropertyForm();
$body	}

}

PHP;
	}

	// The visibility keyword is a single-line substitution, so no row here can shift the two asserted
	// consumer line numbers.
	private function holderSource(string $visibility = 'private'): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchPropertyHolder
{

	use ScratchPropertyBuilder;

	$visibility ScratchPropertyForm \$heldForm;

	public function probe(): void
	{
		dumpFormValues(\$this->heldForm);
		\$this->heldForm['builtField']->setRequired();
	}

}

PHP;
	}

}
