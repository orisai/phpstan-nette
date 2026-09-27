<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * Group B - edits that DO move an exported node. PHPStan propagates these itself, which is exactly
 * why they belong in the matrix: they are the control that pins the mechanism the body-level group
 * fails on. If one of these ever goes red, the defect is not the one this task closed.
 */
final class SignatureLevelInvalidationTest extends FormsInvalidationMatrixCase
{

	private const HELPER_DOCBLOCK_SEED = "\n\t/**\n\t * @return \Nette\Forms\Controls\TextInput\n\t */";

	private const HELPER_DOCBLOCK_WIDENED = "\n\t/**\n\t * @return \Nette\Forms\Controls\BaseControl\n\t */";

	// Seeded WITHOUT the inherited factory, so the step is a genuine method ADDITION.
	public function testFactoryMethodAdded(): void
	{
		$this->assertScenario(
			'b1-method-added',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchParentControl.php',
						$this->parentSource(),
					),
					'expect' => $this->seedErrors(),
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				'ScratchParentControl.php',
				$this->parentSourceWithoutSharedFactory(),
			),
			$this->unresolvedSharedErrors(),
		);
	}

	public function testFactoryMethodRemoved(): void
	{
		$this->assertScenario('b2-method-removed', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchParentControl.php',
					$this->parentSourceWithoutSharedFactory(),
				),
				'expect' => $this->unresolvedSharedErrors(),
			],
		]);
	}

	public function testPropertyAddedAndRemoved(): void
	{
		$this->assertScenario('b3-property', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchForm.php',
					$this->formSource("\t\t\$this->addText('ctorField');\n", "\n\tpublic string \$note = '';\n"),
				),
				'expect' => array_merge($this->seedErrors(), [
					'ScratchForm.php:8 :: shipmonk.deadProperty.neverRead :: '
					. 'Property ScratchForm::$note is never read',
				]),
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

	// The shared add*() helper's declared return type is what the value projection reads, so
	// widening it is the signature edit with the largest reach in this corpus: pairField loses its
	// type AND the whole shape opens, in every consumer that goes through ScratchForm.
	public function testSharedHelperReturnTypeWidened(): void
	{
		$this->assertScenario('b4-return-type', [
			[
				'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
					'ScratchSharedMethods.php',
					$this->sharedMethodsSource('$name', ': \Nette\Forms\Controls\BaseControl'),
				),
				'expect' => $this->dumps(
					'ctorField: string, childField: string, pairField: mixed, ...<mixed>',
					'ctorField: string, sharedField: string',
					'ctorField: string, grandField: string',
					'ctorField: string, traitField: string',
					'ctorField: string, factoryField: string',
				),
			],
		]);
	}

	// The same widening as b4 expressed in a DOCBLOCK instead of a native return type, so the only
	// bytes that move are inside a PHPDoc.
	public function testSharedHelperReturnDocblockWidened(): void
	{
		$this->assertScenario(
			'b5-return-docblock',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchSharedMethods.php',
						$this->sharedMethodsSource('$name', '', self::HELPER_DOCBLOCK_WIDENED),
					),
					'expect' => $this->dumps(
						'ctorField: string, childField: string, pairField: mixed, ...<mixed>',
						'ctorField: string, sharedField: string',
						'ctorField: string, grandField: string',
						'ctorField: string, traitField: string',
						'ctorField: string, factoryField: string',
					),
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				'ScratchSharedMethods.php',
				$this->sharedMethodsSource('$name', '', self::HELPER_DOCBLOCK_SEED),
			),
		);
	}

	/**
	 * @return list<string>
	 */
	private function unresolvedSharedErrors(): array
	{
		return [
			$this->valuesDump(self::FORM_DUMP_LINE, 'ctorField: string, childField: string, pairField: string'),
			$this->rawDump(self::SHARED_DUMP_LINE, 'mixed~null'),
			$this->valuesDump(self::GRAND_DUMP_LINE, 'ctorField: string, grandField: string'),
			$this->valuesDump(self::TRAIT_DUMP_LINE, 'ctorField: string, traitField: string'),
			$this->valuesDump(self::FACTORY_DUMP_LINE, 'ctorField: string, factoryField: string'),
		];
	}

}
