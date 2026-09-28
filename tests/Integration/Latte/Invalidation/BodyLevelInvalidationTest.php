<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Matrix group C - BODY-ONLY edits: every signature in the corpus stays byte-identical, so
// PHPStan's exportedNodesChanged() returns null and nothing propagates to the linked template
// through its dependency edge. This is the group the probe reproduced as holed, and the group that
// is RED against a per-file templateTypeMismatch consumer and GREEN against an aggregate-stage one.
final class BodyLevelInvalidationTest extends LatteInvalidationMatrixCase
{

	// Scenario 12 - a statement ADDED inside a method body. The added setFile links a template that
	// was already analysed and already clean to a SECOND renderer whose verdict disagrees with its
	// declaration, so the finding has to appear on a file whose own bytes never moved.
	public function testStatementAddedInsideAMethodBody(): void
	{
		$this->assertScenario(
			'body-add',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->twoTemplateControlSource(self::BASE_TEMPLATE),
					),
					'expect' => [
						$this->mismatchError(
							self::BASE_TEMPLATE,
							'second.latte',
							self::CONTROL,
							self::OTHER_TEMPLATE,
						),
					],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedSecondTemplate($scenario),
		);
	}

	// Scenario 13 - a statement REMOVED. The inverse direction of the same edge: the finding has to
	// DISAPPEAR from a file whose own bytes never moved, which is the stale-positive half of the
	// hole (the probe's RUN 3, the more dangerous direction of the two).
	public function testStatementRemovedFromAMethodBody(): void
	{
		$this->assertScenario(
			'body-remove',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, self::BASE_TEMPLATE),
					),
					'expect' => [],
				],
			],
			function (InvalidationScenario $scenario): void {
				$this->seedSecondTemplate($scenario);
				$scenario->write(self::CONTROL . '.php', $this->twoTemplateControlSource(self::BASE_TEMPLATE));
			},
			[$this->mismatchError(self::BASE_TEMPLATE, 'second.latte', self::CONTROL, self::OTHER_TEMPLATE)],
		);
	}

	// Scenario 14 - the probe's decisive case: one statement EDITED inside the convention hook. The
	// signature line, the return type and the class docblock are all byte-identical afterwards.
	public function testStatementEditedInsideAMethodBody(): void
	{
		$this->assertScenario(
			'body-edit',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, self::OTHER_TEMPLATE),
					),
					'expect' => [$this->mismatchError(self::OTHER_TEMPLATE)],
				],
				// And back again: the stale-POSITIVE direction, where the warm run keeps reporting a
				// mismatch against a class the renderer no longer pairs. The probe found this one
				// survived repeated warm runs at "0 files will be reanalysed", i.e. permanently.
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, self::BASE_TEMPLATE),
					),
					'expect' => [],
				],
			],
		);
	}

	// Scenario 15 - a conditional wrapped around an existing statement. Structurally a different AST
	// shape from a plain replacement (two Return_ nodes in two branches, both walked), still not one
	// byte of signature.
	public function testConditionalWrappedAroundAnExistingStatement(): void
	{
		$this->assertScenario(
			'body-conditional',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->conditionalControlSource(self::OTHER_TEMPLATE),
					),
					'expect' => [$this->mismatchError(self::OTHER_TEMPLATE)],
				],
			],
		);
	}

	// Scenario 16 - the body edit happens in a PARENT class, in a different file, which the renderer
	// only inherits. The renderer's own file is not even re-hashed.
	public function testBodyEditInAParentClass(): void
	{
		$this->assertScenario(
			'body-parent',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchBaseControl.php',
						$this->hookOnlyClassSource('ScratchBaseControl', self::OTHER_TEMPLATE),
					),
					'expect' => [$this->mismatchError(self::OTHER_TEMPLATE)],
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(
					'ScratchBaseControl.php',
					$this->hookOnlyClassSource('ScratchBaseControl', self::BASE_TEMPLATE),
				);
				$scenario->write(
					self::CONTROL . '.php',
					$this->controlSource(self::CONTROL, null, 'shared.latte', 'ScratchBaseControl'),
				);
			},
		);
	}

	// Scenario 17 - the body edit happens in a TRAIT the renderer uses. PHPStan's result cache has a
	// dedicated trait branch (ResultCacheManager (D): a file whose exported nodes are ALL traits
	// reanalyses its using files even when nothing exported changed), so this row could have been
	// the one body-only case core already carries.
	//
	// It is instead vacuous for a reason the assertions state outright: PhpRenderWalk never walks a
	// TRAIT-declared method body at all (isResolvedMethodBody() compares the resolved method's
	// DECLARING class - PHP reports the USING class for a trait method - against the class whose AST
	// the walk is iterating, and the trait's own AST is never iterated), so the trait-using renderer
	// pairs the bare floor and the trait's body was never one of the verdict's inputs to go stale.
	// Both the seed and the post-edit state say so, which is what turns a scenario that cannot
	// discriminate into a pin: teach the walk about traits and this test goes red, and whoever does
	// it has to answer the invalidation question at the same time. The walk gap itself is a
	// SOUNDNESS finding, out of this task's scope - reported, not fixed.
	public function testBodyEditInATraitTheRendererUsesMovesNothingBecauseTraitBodiesAreNotWalked(): void
	{
		$floorMismatch = $this->mismatchError('Nette\Application\UI\Template');

		$this->assertScenario(
			'body-trait',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateClassTrait.php',
						$this->traitSource(self::OTHER_TEMPLATE),
					),
					'expect' => [$floorMismatch],
					'maxReanalysed' => 2,
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write('ScratchTemplateClassTrait.php', $this->traitSource(self::BASE_TEMPLATE));
				$scenario->write(self::CONTROL . '.php', $this->traitUsingControlSource());
			},
			[$floorMismatch],
		);
	}

	// Scenario 18 - MULTI-HOP: the hook lives two files up the hierarchy, so the renderer's verdict
	// depends on a body PhpRenderWalk reaches only after resolving through an intermediate class
	// that is itself untouched.
	public function testBodyEditTwoClassesUpTheHierarchy(): void
	{
		$this->assertScenario(
			'body-multihop',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchBaseControl.php',
						$this->hookOnlyClassSource('ScratchBaseControl', self::OTHER_TEMPLATE),
					),
					'expect' => [$this->mismatchError(self::OTHER_TEMPLATE)],
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(
					'ScratchBaseControl.php',
					$this->hookOnlyClassSource('ScratchBaseControl', self::BASE_TEMPLATE),
				);
				$scenario->write(
					'ScratchMiddleControl.php',
					"<?php declare(strict_types = 1);\n\nclass ScratchMiddleControl extends ScratchBaseControl\n{\n\n}\n",
				);
				$scenario->write(
					self::CONTROL . '.php',
					$this->controlSource(self::CONTROL, null, 'shared.latte', 'ScratchMiddleControl'),
				);
			},
		);
	}

	private function seedSecondTemplate(InvalidationScenario $scenario): void
	{
		$scenario->write('second.latte', $this->templateFileSource(self::OTHER_TEMPLATE, "<p>second</p>\n"));
		$scenario->write(
			self::SECOND_CONTROL . '.php',
			$this->controlSource(self::SECOND_CONTROL, self::OTHER_TEMPLATE, 'second.latte'),
		);
	}

	private function twoTemplateControlSource(string $pairedTemplate): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class ScratchControl extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		return $pairedTemplate::class;
	}

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

	public function renderSecond(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/second.latte');
	}

}

PHP;
	}

	private function conditionalControlSource(string $pairedTemplate): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

class ScratchControl extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		if (\$this->getName() !== null) {
			return $pairedTemplate::class;
		}

		return $pairedTemplate::class;
	}

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP;
	}

	private function traitSource(string $pairedTemplate): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

trait ScratchTemplateClassTrait
{

	public function formatTemplateClass(): ?string
	{
		return $pairedTemplate::class;
	}

}

PHP;
	}

	private function traitUsingControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class ScratchControl extends \Nette\Application\UI\Control
{

	use ScratchTemplateClassTrait;

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP;
	}

	private function hookOnlyClassSource(string $className, string $pairedTemplate): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

abstract class $className extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		return $pairedTemplate::class;
	}

}

PHP;
	}

}
