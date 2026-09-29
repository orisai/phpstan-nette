<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use const PHP_VERSION_ID;

// Matrix group B - IN-FILE, SIGNATURE-CHANGING edits. These are the ones PHPStan's own result cache
// already propagates: each moves an exported node, so exportedNodesChanged() is non-null and
// ResultCacheManager reaches its dependent-files loop. They are CONTROLS - they worked before the
// aggregate move and their job is to prove the move did not cost them - plus the line-attribution
// pin, which a per-file-to-aggregate move is exactly the kind of change that can silently regress.
/**
 * @group latte2
 */
final class SignatureLevelInvalidationTest extends LatteInvalidationMatrixCase
{

	private const FLOOR_TEMPLATE = 'Nette\Application\UI\Template';

	// Read only from the template's synthesized locals, which shipmonk's dead-property rule cannot
	// see - the ignore keeps that unavoidable artefact out of every expected error set.
	private const DEAD_PROPERTY_IGNORE = ' // @phpstan-ignore shipmonk.deadProperty.neverRead';

	private const NATIVE_INT_PROPERTY = "\tpublic int \$count = 0;" . self::DEAD_PROPERTY_IGNORE . "\n";

	private const DOCBLOCK_INT_PROPERTY = "\t/** @var int */\n\tpublic \$count = 0;"
		. self::DEAD_PROPERTY_IGNORE . "\n";

	private const DOCBLOCK_STRING_PROPERTY = "\t/** @var string */\n\tpublic \$count = '';"
		. self::DEAD_PROPERTY_IGNORE . "\n";

	// Scenario 7 - a method ADDED. Without a convention hook the renderer pairs the bare floor, so
	// adding one is the edit that has to reach the template and silence the finding.
	public function testMethodAdded(): void
	{
		$this->assertScenario(
			'sig-method-added',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, self::BASE_TEMPLATE),
					),
					'expect' => [],
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				self::CONTROL . '.php',
				$this->controlSource(self::CONTROL, null),
			),
			[$this->mismatchError(self::FLOOR_TEMPLATE)],
		);
	}

	// Scenario 8 - a method REMOVED.
	public function testMethodRemoved(): void
	{
		$this->assertScenario(
			'sig-method-removed',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, null),
					),
					'expect' => [$this->mismatchError(self::FLOOR_TEMPLATE)],
				],
			],
		);
	}

	// Scenario 9 - a class's SIGNATURE changed, in a third file neither the renderer nor the
	// template names: the paired class gains the declared class as a parent, so the declaration
	// starts WIDENING it and the finding must clear.
	public function testClassSignatureChanged(): void
	{
		$this->assertScenario(
			'sig-extends-changed',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::OTHER_TEMPLATE . '.php',
						$this->templateSource(self::OTHER_TEMPLATE, self::BASE_TEMPLATE),
					),
					'expect' => [],
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				self::CONTROL . '.php',
				$this->controlSource(self::CONTROL, self::OTHER_TEMPLATE),
			),
			[$this->mismatchError(self::OTHER_TEMPLATE)],
		);
	}

	// Scenario 10 - a PROPERTY of the declared template class removed and added back. The template's
	// synthesized locals come from that class's public properties, so its own body stops and starts
	// type-checking without the template file being touched.
	public function testPropertyRemovedAndAddedBack(): void
	{
		$this->assertScenario(
			'sig-property',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::BASE_TEMPLATE . '.php',
						$this->templateSource(self::BASE_TEMPLATE),
					),
					'expect' => [$this->undefinedCountError()],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::BASE_TEMPLATE . '.php',
						$this->countingTemplateSource(self::NATIVE_INT_PROPERTY),
					),
					'expect' => [],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedCountingCorpus($scenario, self::NATIVE_INT_PROPERTY),
		);
	}

	// Scenario 11 - only the DOCBLOCK type annotation changed; the native code is byte-identical.
	public function testDocblockTypeAnnotationChanged(): void
	{
		$this->assertScenario(
			'sig-docblock',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::BASE_TEMPLATE . '.php',
						$this->countingTemplateSource(self::DOCBLOCK_STRING_PROPERTY),
					),
					'expect' => [$this->strRepeatError()],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedCountingCorpus($scenario, self::DOCBLOCK_INT_PROPERTY),
		);
	}

	// Scenario 23 (added by this task) - the {templateType} declaration MOVES to another line. The
	// aggregate stage builds its errors with an explicit ->file()->line() instead of materializing
	// them into the compiled template's AST, so the line a finding lands on is now this checker's
	// own answer rather than the compiler's line map. That is precisely the kind of thing a
	// per-file-to-aggregate move regresses silently, so it gets a pin of its own.
	public function testTemplateTypeDeclarationMovedToAnotherLine(): void
	{
		$this->assertScenario(
			'sig-templatetype-line',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'shared.latte',
						"{* leading comment *}\n" . $this->templateFileSource(self::BASE_TEMPLATE),
					),
					'expect' => [
						$this->mismatchError(
							self::OTHER_TEMPLATE,
							'shared.latte',
							self::CONTROL,
							self::BASE_TEMPLATE,
							2,
						),
					],
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(
				self::CONTROL . '.php',
				$this->controlSource(self::CONTROL, self::OTHER_TEMPLATE),
			),
			[$this->mismatchError(self::OTHER_TEMPLATE)],
		);
	}

	private function seedCountingCorpus(InvalidationScenario $scenario, string $property): void
	{
		$scenario->write(self::BASE_TEMPLATE . '.php', $this->countingTemplateSource($property));
		$scenario->write(
			'shared.latte',
			$this->templateFileSource(self::BASE_TEMPLATE, "{=str_repeat('x', \$count)}\n"),
		);
	}

	private function countingTemplateSource(string $property): string
	{
		return $this->templateSource(self::BASE_TEMPLATE, '\Nette\Bridges\ApplicationLatte\Template', $property);
	}

	private function undefinedCountError(): string
	{
		return 'shared.latte:2 :: variable.undefined :: Undefined variable: $count';
	}

	private function strRepeatError(): string
	{
		return 'shared.latte:2 :: argument.type :: Parameter #2 $' . self::strRepeatCountParameter()
			. ' of function str_repeat expects int, string given.';
	}

	// PHPStan's function map names it, not the runtime's reflection (7.4 reflects `$mult`).
	private static function strRepeatCountParameter(): string
	{
		return PHP_VERSION_ID >= 80000 ? 'times' : 'multiplier';
	}

}
