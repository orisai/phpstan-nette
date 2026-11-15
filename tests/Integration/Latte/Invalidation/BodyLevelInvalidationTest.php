<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use OriPhpstan\Nette\Latte\Rule\LatteDiscoveryRule;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Matrix group C - BODY-ONLY edits: every signature in the corpus stays byte-identical, so
// PHPStan's exportedNodesChanged() returns null and nothing propagates to the linked template
// through its dependency edge. This is the group the probe reproduced as holed, and the group that
// is RED against a per-file templateTypeMismatch consumer and GREEN against an aggregate-stage one.
/**
 * @group latte2
 */
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

	// Scenario 17 - the body edit happens in a TRAIT the renderer uses. PhpRenderWalk walks the methods
	// a class's first-party traits give it, so the trait's convention hook pairs the renderer and the
	// trait file is part of the renderer's read set: the body-only edit reaches the template through
	// the renderer's render facts, which PHPStan's own trait branch (ResultCacheManager (D) reanalyses
	// the using files) would not do for a .latte file.
	public function testBodyEditInATraitTheRendererUsesReachesTheTemplate(): void
	{
		$this->assertScenario(
			'body-trait',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateClassTrait.php',
						$this->traitSource(self::OTHER_TEMPLATE),
					),
					'expect' => [$this->mismatchError(self::OTHER_TEMPLATE)],
					'reanalysed' => [self::CONTROL . '.php', 'ScratchTemplateClassTrait.php', 'shared.latte'],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateClassTrait.php',
						$this->traitSource(self::BASE_TEMPLATE),
					),
					'expect' => [],
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write('ScratchTemplateClassTrait.php', $this->traitSource(self::BASE_TEMPLATE));
				$scenario->write(self::CONTROL . '.php', $this->traitUsingControlSource());
			},
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

	// Scenario 19 - the setFile argument is a convention method whose static `return <path>;` lives
	// in a TRAIT, which the walk never iterates. The trait's file is an input of the renderer's
	// facts: retargeting the return re-links the renderer, reverting it unlinks it again, and a
	// dynamic body turns the renderer's own cached discovery opaque.
	public function testStaticConventionReturnEditedInATrait(): void
	{
		$secondMismatch = $this->mismatchError(
			self::BASE_TEMPLATE,
			'second.latte',
			self::CONTROL,
			self::OTHER_TEMPLATE,
		);
		$sharedOrphan = 'shared.latte:1 :: ' . TemplateTypeChecker::ORPHAN_IDENTIFIER
			. ' :: No analysable render, include or layout path reaches this template file.';

		$this->assertScenario(
			'body-convention-return',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateFileTrait.php',
						$this->templateFileTraitSource('second.latte'),
					),
					'expect' => [$secondMismatch, $sharedOrphan],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateFileTrait.php',
						$this->templateFileTraitSource('shared.latte'),
					),
					'expect' => [],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchTemplateFileTrait.php',
						$this->templateFileTraitSource("' . \$this->getName() . '.latte"),
					),
					'expect' => [
						'ScratchControl.php:15 :: ' . LatteDiscoveryRule::OPAQUE_IDENTIFIER
						. ' :: Template file discovery is opaque: convention method templateFile declared by '
						. 'ScratchTemplateFileTrait has no assigned discovery formula.',
						$sharedOrphan,
					],
				],
			],
			function (InvalidationScenario $scenario): void {
				$this->seedSecondTemplate($scenario);
				$scenario->write('ScratchTemplateFileTrait.php', $this->templateFileTraitSource('shared.latte'));
				$scenario->write(self::CONTROL . '.php', <<<'PHP'
<?php declare(strict_types = 1);

class ScratchControl extends \Nette\Application\UI\Control
{

	use ScratchTemplateFileTrait;

	public function formatTemplateClass(): ?string
	{
		return ScratchBaseTemplate::class;
	}

	public function render(): void
	{
		$this->getTemplate()->setFile($this->templateFile());
	}

}

PHP);
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

	private function templateFileTraitSource(string $templateRelPath): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

trait ScratchTemplateFileTrait
{

	protected function templateFile(): string
	{
		return __DIR__ . '/$templateRelPath';
	}

}

PHP;
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
