<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\AttrShellEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\BlockDispatchEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EliminatorVisitor;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FamilyPatterns;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\UiMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;
use function array_filter;
use function array_keys;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function is_subclass_of;
use function sort;

// Every consumer of generated-code shapes has a pattern table for every shape family, and the
// consumers still matching their Latte 2 shapes on Latte 3 are exactly the listed ones.
final class FamilyCoverageTest extends BaseTestCase
{

	public function testEveryEliminatorIsARegisteredConsumer(): void
	{
		$consumers = FamilyPatterns::CONSUMERS;
		$eliminators = self::eliminatorClasses();
		sort($consumers);
		sort($eliminators);

		self::assertSame(
			$eliminators,
			array_values(array_filter($consumers, static fn (string $class): bool => $class !== FilterRewriter::class)),
		);
		self::assertContains(FilterRewriter::class, FamilyPatterns::CONSUMERS);
	}

	public function testEveryConsumerHasAPatternSetForEveryFamily(): void
	{
		foreach (ShapeFamily::all() as $family) {
			foreach (FamilyPatterns::CONSUMERS as $consumer) {
				$set = FamilyPatterns::for($family, $consumer);

				self::assertNotSame('', $set->describe(), $consumer . ' on ' . $family->id());
			}
		}
	}

	// The list shrinks as Tasks 13 and 14 give these consumers their own Latte 3 tables; a NEW
	// provisional entry is a Latte 3 shape silently matched with a Latte 2 table.
	public function testProvisionalConsumersAreExactlyTheOnesStillOnLatte2Shapes(): void
	{
		$provisional = [];
		foreach (FamilyPatterns::CONSUMERS as $consumer) {
			foreach (ShapeFamily::all() as $family) {
				if (FamilyPatterns::isProvisional($family, $consumer)) {
					$provisional[$consumer . '@' . $family->id()] = true;
				}
			}
		}

		self::assertSame(
			[
				AttrShellEliminator::class . '@3.0/item',
				AttrShellEliminator::class . '@3.0/provider',
				AttrShellEliminator::class . '@3.1/item',
				AttrShellEliminator::class . '@3.1/provider',
				UiMacroEliminator::class . '@3.0/item',
				UiMacroEliminator::class . '@3.0/provider',
				UiMacroEliminator::class . '@3.1/item',
				UiMacroEliminator::class . '@3.1/provider',
				FormsMacroEliminator::class . '@3.0/item',
				FormsMacroEliminator::class . '@3.0/provider',
				FormsMacroEliminator::class . '@3.1/item',
				FormsMacroEliminator::class . '@3.1/provider',
				BlockDispatchEliminator::class . '@3.0/item',
				BlockDispatchEliminator::class . '@3.0/provider',
				BlockDispatchEliminator::class . '@3.1/item',
				BlockDispatchEliminator::class . '@3.1/provider',
			],
			array_keys($provisional),
		);
	}

	public function testAProvisionalConsumerGetsItsLatte2SetOnEveryLatte3Family(): void
	{
		$latte2 = EliminatorRun::family(ShapeFamily::LATTE_2);
		foreach (FamilyPatterns::PROVISIONAL as $consumer => $lines) {
			foreach ($lines as $line) {
				$family = EliminatorRun::family($line);

				self::assertTrue(FamilyPatterns::isProvisional($family, $consumer));
				self::assertEquals(FamilyPatterns::for($latte2, $consumer), FamilyPatterns::for($family, $consumer));
			}
		}

		self::assertFalse(FamilyPatterns::isProvisional($latte2, AttrShellEliminator::class));
		self::assertFalse(
			FamilyPatterns::isProvisional(EliminatorRun::family(ShapeFamily::LATTE_31), EscapingEliminator::class),
		);
	}

	public function testMigratedConsumersDescribeDifferentShapesPerLine(): void
	{
		$descriptions = [];
		foreach ([ShapeFamily::LATTE_2, ShapeFamily::LATTE_30, ShapeFamily::LATTE_31] as $line) {
			$descriptions[$line] = (new EscapingEliminator(EliminatorRun::family($line)))->describePattern();
		}

		self::assertStringContainsString('Latte\Runtime\Filters::escapeHtmlText', $descriptions[ShapeFamily::LATTE_2]);
		self::assertStringContainsString('safeUrl', $descriptions[ShapeFamily::LATTE_2]);
		self::assertStringContainsString('escapeXmlText', $descriptions[ShapeFamily::LATTE_30]);
		self::assertStringContainsString('checkUrl', $descriptions[ShapeFamily::LATTE_30]);
		self::assertStringContainsString('Latte\Runtime\HtmlHelpers::escapeText', $descriptions[ShapeFamily::LATTE_31]);
		self::assertStringContainsString('formatAttribute', $descriptions[ShapeFamily::LATTE_31]);
		self::assertStringNotContainsString('formatAttribute', $descriptions[ShapeFamily::LATTE_30]);
	}

	public function testTheFormsBridgeDoesNotChangeTheCoreShapes(): void
	{
		foreach (FamilyPatterns::CONSUMERS as $consumer) {
			self::assertEquals(
				FamilyPatterns::for(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_ITEM), $consumer),
				FamilyPatterns::for(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER), $consumer),
				$consumer,
			);
		}
	}

	/**
	 * @return list<class-string<EliminatorVisitor>>
	 */
	private static function eliminatorClasses(): array
	{
		$classes = [];
		foreach ((array) glob(dirname(__DIR__, 5) . '/src/Latte/Postprocess/Eliminator/*Eliminator.php') as $path) {
			$class = 'OriPhpstan\Nette\Latte\Postprocess\Eliminator\\' . basename((string) $path, '.php');
			self::assertTrue(is_subclass_of($class, EliminatorVisitor::class), $class);
			$classes[] = $class;
		}

		self::assertNotSame([], $classes);

		return $classes;
	}

}
