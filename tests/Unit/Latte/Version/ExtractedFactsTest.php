<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version;

use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\Latte2\DeclarationScanner;
use OriPhpstan\Nette\Latte\Version\Latte2\TemplateFactExtractor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * @group latte2
 */
final class ExtractedFactsTest extends BaseTestCase
{

	public function testEachResolverRunsAtMostOnceAndOnlyWhenRead(): void
	{
		$calls = new class {

			public int $declarations = 0;

			public int $templateFacts = 0;

			public int $formSites = 0;

			/**
			 * @return array{int, int, int}
			 */
			public function all(): array
			{
				return [$this->declarations, $this->templateFacts, $this->formSites];
			}

		};
		$declarations = (new DeclarationScanner())->scan("{varType int \$a}\n");
		$templateFacts = (new TemplateFactExtractor())->extract("{include 'b.latte'}\n", 'a.latte');

		$facts = ExtractedFacts::lazy(
			static function () use ($calls, $declarations): Declarations {
				$calls->declarations++;

				return $declarations;
			},
			static function () use ($calls, $templateFacts): TemplateFacts {
				$calls->templateFacts++;

				return $templateFacts;
			},
			static function () use ($calls): array {
				$calls->formSites++;

				return [];
			},
		);

		self::assertSame([0, 0, 0], $calls->all());

		self::assertSame($declarations, $facts->getDeclarations());
		self::assertSame($declarations, $facts->getDeclarations());
		self::assertSame([1, 0, 0], $calls->all());

		self::assertSame($templateFacts, $facts->getTemplateFacts());
		self::assertSame($templateFacts, $facts->getTemplateFacts());
		self::assertSame([], $facts->getFormSites());
		self::assertSame([], $facts->getFormSites());
		self::assertSame([1, 1, 1], $calls->all());
	}

	public function testEagerFactsAreReturnedAsGiven(): void
	{
		$declarations = (new DeclarationScanner())->scan("{varType int \$a}\n");
		$templateFacts = (new TemplateFactExtractor())->extract("{include 'b.latte'}\n", 'a.latte');

		$facts = ExtractedFacts::eager($declarations, $templateFacts, []);

		self::assertSame($declarations, $facts->getDeclarations());
		self::assertSame($templateFacts, $facts->getTemplateFacts());
		self::assertSame([], $facts->getFormSites());
	}

}
