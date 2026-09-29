<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EliminatorVisitor;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EscapingEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FamilyPatterns;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;
use function array_filter;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function is_subclass_of;
use function sort;

// Every consumer of generated-code shapes has a pattern table of its own for every shape family, and
// only the forms consumer's follows the forms bridge.
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

	// FamilyPatterns::for() never routes a Latte 3 family through its Latte 2 branch: a consumer whose
	// Latte 3 table equalled its Latte 2 one would match Latte 3 shapes with Latte 2 patterns silently.
	public function testNoConsumerReusesItsLatte2TableOnLatte3(): void
	{
		$latte2 = EliminatorRun::family(ShapeFamily::LATTE_2);
		foreach (FamilyPatterns::CONSUMERS as $consumer) {
			foreach (ShapeFamily::all() as $family) {
				if ($family->latteLine === ShapeFamily::LATTE_2) {
					continue;
				}

				self::assertNotEquals(
					FamilyPatterns::for($latte2, $consumer),
					FamilyPatterns::for($family, $consumer),
					$consumer . ' on ' . $family->id(),
				);
			}
		}
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
			if ($consumer === FormsMacroEliminator::class) {
				continue;
			}

			self::assertEquals(
				FamilyPatterns::for(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_ITEM), $consumer),
				FamilyPatterns::for(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER), $consumer),
				$consumer,
			);
		}
	}

	public function testTheLatteLineDoesNotChangeTheFormsShapes(): void
	{
		$sets = [];
		foreach (ShapeFamily::all() as $family) {
			$sets[$family->id()] = FamilyPatterns::for($family, FormsMacroEliminator::class);
		}

		self::assertEquals($sets['3.0/item'], $sets['3.1/item']);
		self::assertEquals($sets['3.0/provider'], $sets['3.1/provider']);
		self::assertNotEquals($sets['2/macros'], $sets['3.1/item']);
		self::assertNotEquals($sets['3.1/item'], $sets['3.1/provider']);
		self::assertStringContainsString('stackOffset', $sets['2/macros']->describe());
		self::assertStringContainsString('runtimeItem', $sets['3.1/item']->describe());
		self::assertStringContainsString('providerGet', $sets['3.1/provider']->describe());
	}

	/**
	 * @return list<class-string<EliminatorVisitor>>
	 */
	private static function eliminatorClasses(): array
	{
		$classes = [];
		foreach ((array) glob(dirname(__DIR__, 5) . '/src/Latte/Postprocess/Eliminator/*.php') as $path) {
			$class = 'OriPhpstan\Nette\Latte\Postprocess\Eliminator\\' . basename((string) $path, '.php');
			if (is_subclass_of($class, EliminatorVisitor::class)) {
				$classes[] = $class;
			}
		}

		self::assertNotSame([], $classes);

		return $classes;
	}

}
