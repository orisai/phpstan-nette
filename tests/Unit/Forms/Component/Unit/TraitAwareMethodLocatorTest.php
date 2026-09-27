<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use OriPhpstan\Nette\Forms\Component\TraitAwareMethodLocator;
use PHPStan\Parser\Parser;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit\Support\TraitMethodLocatorFixture;
use function assert;

final class TraitAwareMethodLocatorTest extends FormShapeTestCase
{

	public function testLocatesTraitMethodBodyByOwnFileAndStartLine(): void
	{
		$locator = $this->locator();
		$reflection = new ReflectionClass(TraitMethodLocatorFixture::class);

		$traitMethod = $reflection->getMethod('builtInTrait');
		$node = $locator->locate($traitMethod);
		self::assertNotNull($node);
		self::assertSame('builtInTrait', $node->name->toString());
		self::assertSame($traitMethod->getStartLine(), $node->getStartLine());
		self::assertSame($traitMethod->getFileName(), $locator->ownFile($traitMethod));
	}

	public function testLocatesAsAliasedTraitMethodAtAliasedBody(): void
	{
		$locator = $this->locator();
		$reflection = new ReflectionClass(TraitMethodLocatorFixture::class);

		$aliased = $reflection->getMethod('renamedFromTrait');
		$node = $locator->locate($aliased);
		self::assertNotNull($node);
		self::assertSame('aliasedInTrait', $node->name->toString());
		self::assertSame($aliased->getStartLine(), $node->getStartLine());
	}

	public function testLocatesClassDefinedMethod(): void
	{
		$locator = $this->locator();
		$reflection = new ReflectionClass(TraitMethodLocatorFixture::class);

		$classMethod = $reflection->getMethod('definedInClass');
		$node = $locator->locate($classMethod);
		self::assertNotNull($node);
		self::assertSame('definedInClass', $node->name->toString());
	}

	private function locator(): TraitAwareMethodLocator
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);

		return new TraitAwareMethodLocator($parser);
	}

}
