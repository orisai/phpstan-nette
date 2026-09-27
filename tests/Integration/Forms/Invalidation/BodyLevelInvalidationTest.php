<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * Group C - the edits that leave every exported node byte-identical. This is where the hole lived:
 * PHPStan's result cache re-queues a changed file's dependents only when its exported nodes move,
 * and every field a Forms consumer reads is added inside a method body.
 *
 * No scenario here carries a maxReanalysed bound. Closing this class of edit costs a whole-cache
 * invalidation by construction - a ResultCacheMetaExtension is the only in-contract channel and it
 * has no per-file granularity - so bounding the reanalysed count here would be asserting the fix
 * cannot exist. The bounds live on the inverse controls instead, which is where they discriminate.
 */
final class BodyLevelInvalidationTest extends FormsInvalidationMatrixCase
{

	public function testFieldAddedToAFormSubclassConstructorBody(): void
	{
		$this->assertScenario('c1-field-added', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource("\t\t\$this->addText('ctorField');\n\t\t\$this->addText('addedField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: string, addedField: string, childField: string, pairField: string',
					'ctorField: string, addedField: string, sharedField: string',
					'ctorField: string, addedField: string, grandField: string',
					'ctorField: string, addedField: string, traitField: string',
					'ctorField: string, addedField: string, factoryField: string',
				),
			],
		]);
	}

	/**
	 * The production reproduction in miniature, and the only scenario that runs its own revert:
	 * step 0 pins the stale NEGATIVE (a warm run that keeps a shape whose field is gone, and with
	 * it swallows a real method.notFound), step 1 pins the stale POSITIVE in the other direction.
	 */
	public function testFieldRemovedFromAFormSubclassConstructorBodyAndPutBack(): void
	{
		$this->assertScenario('c2-field-removed', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource(''),
				),
				'expect' => array_merge(
					$this->dumps(
						'childField: string, pairField: string',
						'sharedField: string',
						'grandField: string',
						'traitField: string',
						'factoryField: string',
					),
					[$this->undefinedSetRequired()],
				),
			],
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource(),
				),
				'expect' => $this->seedErrors(),
			],
		]);
	}

	public function testFieldRetypedInAFormSubclassConstructorBody(): void
	{
		$this->assertScenario('c3-field-retyped', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource("\t\t\$this->addCheckbox('ctorField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: bool, childField: string, pairField: string',
					'ctorField: bool, sharedField: string',
					'ctorField: bool, grandField: string',
					'ctorField: bool, traitField: string',
					'ctorField: bool, factoryField: string',
				),
			],
		]);
	}

	// The condition reads $_SERVER so PHPStan cannot decide it: a decidable one would either fold
	// the branch away (no edit at all) or add a notIdentical.alwaysTrue line that has nothing to do
	// with invalidation. What the extension answers for an undecided add is the whole shape going
	// OPEN, which is why the no-longer-certain field's consumer reports method.notFound here too.
	public function testStatementWrappedInAConditional(): void
	{
		$this->assertScenario('c4-conditional', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource(
						"\t\tif (isset(\$_SERVER['SCRATCH_FLAG'])) {\n"
						. "\t\t\t\$this->addText('ctorField');\n"
						. "\t\t}\n",
					),
				),
				'expect' => array_merge(
					$this->dumps(
						'childField: string, pairField: string, ...<mixed>',
						'sharedField: string, ...<mixed>',
						'grandField: string, ...<mixed>',
						'traitField: string, ...<mixed>',
						'factoryField: string, ...<mixed>',
					),
					[$this->undefinedSetRequired()],
				),
			],
		]);
	}

	public function testBodyEditInAnInheritedFactoryDeclaredInTheParentClass(): void
	{
		$this->assertScenario('c5-parent-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchParentControl.php',
					$this->parentSource("\t\t\$form->addCheckbox('sharedField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: string, childField: string, pairField: string',
					'ctorField: string, sharedField: bool',
					'ctorField: string, grandField: string',
					'ctorField: string, traitField: string',
					'ctorField: string, factoryField: string',
				),
			],
		]);
	}

	public function testBodyEditInATraitDeclaredFactory(): void
	{
		$this->assertScenario('c6-trait-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchFactoryTrait.php',
					$this->factoryTraitSource("\t\t\$form->addCheckbox('traitField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: string, childField: string, pairField: string',
					'ctorField: string, sharedField: string',
					'ctorField: string, grandField: string',
					'ctorField: string, traitField: bool',
					'ctorField: string, factoryField: string',
				),
			],
		]);
	}

	/**
	 * The delegate hop: the edited body is ScratchFormFactory::create(), which the consumer never
	 * names. Its answer has to travel factory -> ScratchChildControl::createComponentFactoryMade()
	 * -> the consumer's $this['factoryMade'] read, i.e. two files.
	 */
	public function testBodyEditInADelegateFactoryTwoFilesFromTheConsumer(): void
	{
		$this->assertScenario('c7-delegate-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchFormFactory.php',
					$this->formFactorySource("\t\t\$form->addCheckbox('factoryField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: string, childField: string, pairField: string',
					'ctorField: string, sharedField: string',
					'ctorField: string, grandField: string',
					'ctorField: string, traitField: string',
					'ctorField: string, factoryField: bool',
				),
			],
		]);
	}

	public function testBodyEditTwoClassesUpTheHierarchy(): void
	{
		$this->assertScenario('c8-grandparent-body', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchGrandParentControl.php',
					$this->grandParentSource("\t\t\$form->addCheckbox('grandField');\n"),
				),
				'expect' => $this->dumps(
					'ctorField: string, childField: string, pairField: string',
					'ctorField: string, sharedField: string',
					'ctorField: string, grandField: bool',
					'ctorField: string, traitField: string',
					'ctorField: string, factoryField: string',
				),
			],
		]);
	}

}
