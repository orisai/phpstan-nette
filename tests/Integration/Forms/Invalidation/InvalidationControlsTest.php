<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * Groups D and E - the half of the matrix that stops the fix from being bought with a blanket
 * invalidation. Every row here asserts, BEFORE any error-set comparison, that the result cache was
 * genuinely RESTORED and that the edit stayed granular; a salt over raw file content, or over the
 * whole analysed tree, satisfies every cold == warm assertion in the other three classes and fails
 * here. Without this class the matrix would accept the forbidden fix.
 *
 * The one config row is the exception that carries no bound: a parameter change is SUPPOSED to
 * discard the cache (PHPStan's own projectConfig meta), so bounding it would assert the opposite of
 * the contract.
 */
final class InvalidationControlsTest extends FormsInvalidationMatrixCase
{

	private const FACTORY_DOCBLOCK = "\n\t/**\n\t * Builds the form this control's factory forwards to.\n\t */";

	private const FACTORY_DOCBLOCK_REINDENTED
		= "\n\t/**\n\t *    Builds the form this control's factory     forwards to.\n\t */";

	public function testCommentOnlyEditInAFormBearingFile(): void
	{
		$this->assertScenario('e1-comment-only', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource(
						"\t\t// the field every consumer in this corpus reads\n"
						. "\t\t\$this->addText('ctorField');\n",
					),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	public function testWhitespaceOnlyEditInAFormBearingFile(): void
	{
		$this->assertScenario('e2-whitespace-only', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource("\n\t\t\$this->addText('ctorField');\n\n"),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
		]);
	}

	/**
	 * Doc comments are IN the salt - `@form-*` annotations and PHPDoc types are shape inputs - so
	 * this row pins the one normalisation that keeps them affordable: their internal whitespace is
	 * collapsed before hashing. The bound is 2 rather than 1 because a changed PHPDoc IS an exported
	 * node change, so PHPStan itself queues the one file that depends on this one.
	 */
	public function testDocCommentReindentInAFormBearingFile(): void
	{
		$this->assertScenario(
			'e3-doccomment-reindent',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchFormFactory.php',
						$this->formFactorySource(
							"\t\t\$form->addText('factoryField');\n",
							self::FACTORY_DOCBLOCK_REINDENTED,
						),
					),
					'expect' => $this->seedErrors(),
					'maxReanalysed' => 2,
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				'ScratchFormFactory.php',
				$this->formFactorySource("\t\t\$form->addText('factoryField');\n", self::FACTORY_DOCBLOCK),
			),
		);
	}

	/**
	 * The only control with POSITIVE detection power: step 1 demands a brand-new finding in the
	 * edited file IN THE SAME RUN that reanalyses at most one file. A control that only asserts
	 * absence would stay green against an analysis that had stopped working altogether.
	 */
	public function testUnrelatedFileBodyEditStaysGranular(): void
	{
		$this->assertScenario('e4-unrelated-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchUnrelated.php',
					$this->unrelatedSource('2'),
				),
				'expect' => $this->seedErrors(),
				'maxReanalysed' => 1,
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchUnrelated.php',
					$this->unrelatedSource("'not an int'"),
				),
				'expect' => array_merge($this->seedErrors(), [
					'ScratchUnrelated.php:8 :: return.type :: '
					. 'Method ScratchUnrelated::value() should return int but returns string.',
				]),
				'maxReanalysed' => 1,
			],
		]);
	}

	// Switching the extension off takes the five dumps away, and with them the offset resolver, so the
	// setRequired() consumer is left with the vendor IComponent answer.
	public function testFormsParameterChangeReachesTheConsumers(): void
	{
		$this->assertScenario('d1-parameter', [
			[
				'mutate' => function (): void {
					$this->setParameter('orisaiNette.forms.enabled', false);
				},
				'expect' => [$this->undefinedSetRequired()],
			],
			[
				'mutate' => function (): void {
					$this->setParameter('orisaiNette.forms.enabled', true);
				},
				'expect' => $this->seedErrors(),
			],
		]);
	}

}
