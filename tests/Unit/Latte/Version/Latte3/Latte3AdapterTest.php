<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Adapter;
use OriPhpstan\Nette\Latte\Version\Latte3\Latte3Compiler;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function preg_match;

/**
 * @group latte3
 */
final class Latte3AdapterTest extends BaseTestCase
{

	public function testTheInstalledLatte3GetsThisAdapter(): void
	{
		$adapter = TestAdapter::create();

		self::assertInstanceOf(Latte3Adapter::class, $adapter);
		self::assertSame(InstalledVersionsGuard::latteLine(), $adapter->family()->latteLine);
		self::assertSame(TestAdapter::factory()->family()->id(), $adapter->family()->id());
	}

	public function testCompileYieldsTheCodeAndTheFactsOfOneParse(): void
	{
		$source = "{templateType Foo}\n{parameters string \$name, int \$age = 18}\n{varType bool \$show}\n{if \$show}{\$name}{/if}\n{varType string \$mid}\n";
		$adapter = $this->adapter();

		$compiled = $adapter->compile($source, 'LatteTpl_facts', 'a.latte');

		self::assertNotNull($compiled->getResult()->getPhpSource());
		self::assertSame('LatteTpl_facts', $compiled->getResult()->getClassName());
		$declarations = $compiled->getFacts()->getDeclarations();
		self::assertSame('Foo', $declarations->getTemplateTypeClass());
		self::assertSame(1, $declarations->getTemplateTypeLine());
		self::assertSame(['show' => 'bool'], $declarations->getHeaderVarTypes());
		self::assertSame(['show' => 3], $declarations->getHeaderVarTypeLines());
		self::assertSame([['mid', 'string', 5]], $declarations->getMidFileVarTypes());
		self::assertSame([['string', 'name', null, 2], ['int', 'age', '18', 2]], $declarations->getParameters());
		self::assertSame([], $declarations->getTypedVars());
		self::assertSame([], $declarations->getDefineParams());
		[$placement] = $declarations->getVarTypePlacements();
		self::assertSame('mid', $placement->getName());
		self::assertNull($placement->getAnchorKind());
		self::assertSame('the end of the template', $placement->getAnchorLabel());
		self::assertTrue($placement->isNeverAssigned());
		$templateFacts = $compiled->getFacts()->getTemplateFacts();
		self::assertSame([], $templateFacts->getIncludeSites());
		self::assertSame(
			[
				1 => [['name' => 'templateType', 'column' => 1]],
				2 => [['name' => 'parameters', 'column' => 1]],
				3 => [['name' => 'varType', 'column' => 1]],
				4 => [['name' => 'if', 'column' => 1]],
				5 => [['name' => 'varType', 'column' => 1]],
			],
			$templateFacts->getLineMacros(),
		);
		self::assertSame([], $compiled->getFacts()->getFormSites());

		self::assertEquals($declarations, $adapter->extractFacts($source, 'a.latte')->getDeclarations());
		self::assertEquals($templateFacts, $adapter->extractFacts($source, 'a.latte')->getTemplateFacts());
	}

	public function testFailedParseStillYieldsEmptyFacts(): void
	{
		$compiled = $this->adapter()->compile("{varType int \$a}\n{if}\n", 'LatteTpl_failed', 'a.latte');

		self::assertNull($compiled->getResult()->getPhpSource());
		self::assertSame('orisaiNette.latte.parseError', $compiled->getResult()->getDiagnostics()[0]->getIdentifier());
		self::assertNull($compiled->getFacts()->getDeclarations()->getParameters());
		self::assertSame([], $compiled->getFacts()->getDeclarations()->getHeaderVarTypes());
	}

	public function testLineMarkerPatternFollowsTheInstalledLine(): void
	{
		$pattern = TestAdapter::create()->lineMarkerPattern();
		$code = $this->adapter()->compile("a\n{\$x}\n", 'LatteTpl_marker', 'a.latte')->getResult()->getPhpSource();
		self::assertNotNull($code);

		self::assertSame(1, preg_match($pattern, $code, $m));
		self::assertSame('2', $m['line']);
		self::assertSame(
			InstalledVersionsGuard::latteLine() === '3.0' ? Latte3Adapter::LINE_MARKER_PATTERN_30 : Latte3Adapter::LINE_MARKER_PATTERN_31,
			$pattern,
		);
	}

	public function testCreateIsTheFactorySeam(): void
	{
		$family = new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER);

		$adapter = Latte3Adapter::create($family, new AdapterCollaborators(new DeclarationScanner()));

		self::assertSame($family, $adapter->family());
		self::assertSame(Latte3Adapter::LINE_MARKER_PATTERN_31, $adapter->lineMarkerPattern());
		self::assertSame(
			Latte3Adapter::LINE_MARKER_PATTERN_30,
			(new Latte3Adapter(
				new Latte3Compiler(),
				new ShapeFamily(ShapeFamily::LATTE_30, ShapeFamily::FORMS_ITEM),
			))->lineMarkerPattern(),
		);
	}

	private function adapter(): Latte3Adapter
	{
		return new Latte3Adapter(new Latte3Compiler(), TestAdapter::factory()->family());
	}

}
