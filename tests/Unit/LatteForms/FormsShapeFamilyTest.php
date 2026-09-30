<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function dirname;
use function in_array;
use function str_replace;

// The installed bridge (Latte 2 FormMacros, Latte 3 + nette/forms < 3.3, nette/forms 3.3) compiles
// the same template three ways; the pipeline reduces all of them to the same Helpers:: calls on the
// same template lines, which is what FormMacroTypeResolver types $form and every control from.
final class FormsShapeFamilyTest extends BaseTestCase
{

	private const SHARED_FIXTURE = 'forms-macros.latte';

	public function testTheInstalledBridgeIsTheDetectedFamily(): void
	{
		if (InstalledVersionsGuard::latteMajor() === 2) {
			$expected = ShapeFamily::FORMS_MACROS;
		} else {
			$expected = InstalledVersionsGuard::satisfies('nette/forms', '>=3.3')
				? ShapeFamily::FORMS_PROVIDER
				: ShapeFamily::FORMS_ITEM;
		}

		self::assertSame($expected, TestAdapter::factory()->family()->formsBridge);
	}

	public function testSharedFixtureReducesToTheSameHelperCallsOnEveryBridge(): void
	{
		$fixture = dirname(__DIR__) . '/Latte/Fixtures/' . self::SHARED_FIXTURE;
		[$calls, $php] = self::process(FileSystem::read($fixture), self::SHARED_FIXTURE);

		self::assertSame(
			[
				[1, "form('f')"],
				[2, "formField('x')"],
				[self::labelLine(3, 4), "formField('x')"],
				[4, "formField('x')"],
				[7, "form('f2')"],
				[8, "formField('y')"],
				[9, "formContainer('c')"],
				[10, "formField('z')"],
			],
			$calls,
		);
		self::assertNoBridgeResidue($php);
	}

	public function testEveryReferenceKindReducesToTheSameHelperCallsOnEveryBridge(): void
	{
		[$calls, $php] = self::process(
			"{form myForm}\n"
			. "\t{input name}\n"
			. "\t{input name:part}\n"
			. "\t{input name, class => 'c'}\n"
			. "\t{input name:part, class => 'c'}\n"
			. "\t{label name}Name{/label}\n"
			. "\t{label name /}\n"
			. "\t{label name:part /}\n"
			. "\t{inputError name}\n"
			. "\t<label n:name=\"name\">L</label>\n"
			. "\t<select n:name=\"sel\"></select>\n"
			. "\t<button n:name=\"send\">Go</button>\n"
			. "\t{formContainer address}\n"
			. "\t\t{input street}\n"
			. "\t{/formContainer}\n"
			. "{/form}\n"
			. "{formContext other}\n"
			. "\t{input x}\n"
			. "{/formContext}\n"
			. "<form n:name=\"attrForm\" class=\"c\">\n"
			. "\t{input y}\n"
			. "</form>\n",
			'kinds.latte',
		);

		self::assertSame(
			[
				[1, "form('myForm')"],
				[2, "formField('name')"],
				[3, "formField('name')"],
				[4, "formInput('name')"],
				[5, "formInput('name', 'part')"],
				[self::labelLine(6, 9), "formLabel('name')"],
				[self::labelLine(7, 9), "formField('name')"],
				[self::labelLine(8, 9), "formField('name')"],
				[9, "formField('name')"],
				[10, "formField('name')"],
				[11, "formField('sel')"],
				[12, "formField('send')"],
				[13, "formContainer('address')"],
				[14, "formField('street')"],
				[17, "form('other')"],
				[18, "formField('x')"],
				[20, "form('attrForm')"],
				[21, "formField('y')"],
			],
			$calls,
		);
		self::assertNoBridgeResidue($php);
	}

	// A form rendered inside an {embed} block layer lives in a block method; on nette/forms 3.3 this
	// is the provider shape, and the resolver still needs the plain Helpers::form('x') to type $form['x'].
	public function testFormInsideAnEmbedBlockLayer(): void
	{
		[$calls, $php] = self::process(
			"{embed 'layout.latte'}\n"
			. "\t{block content}\n"
			. "\t\t{form myForm}\n"
			. "\t\t\t{input name}\n"
			. "\t\t\t{\$form['name']->getValue()}\n"
			. "\t\t{/form}\n"
			. "\t{/block}\n"
			. "{/embed}\n",
			'embedded-form.latte',
		);

		self::assertSame([[3, "form('myForm')"], [4, "formField('name')"]], $calls);
		self::assertStringContainsString("\$form['name']->getValue()", $php);
		self::assertNoBridgeResidue($php);
	}

	/**
	 * @return array{list<array{int, string}>, string}
	 */
	private static function process(string $latte, string $name): array
	{
		$relativePath = 'fixtures/' . $name;
		$compiled = TestAdapter::create()->compile($latte, TemplateClassName::forPath($relativePath), $relativePath);
		self::assertNotNull($compiled->getResult()->getPhpSource(), 'fixture must compile');

		$stmts = PipelineFactory::create()->process(
			$compiled->getResult(),
			$compiled->getFacts()->getDeclarations(),
		);

		$printer = new Standard();
		$prefix = '\\' . Helpers::class . '::';
		$calls = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				!$call->class instanceof Name
				|| $call->class->toString() !== Helpers::class
				|| !$call->name instanceof Node\Identifier
				|| !in_array(
					$call->name->toString(),
					['form', 'formObject', 'formContainer', 'formField', 'formLabel', 'formInput'],
					true,
				)
			) {
				continue;
			}

			$calls[] = [$call->getStartLine(), str_replace($prefix, '', $printer->prettyPrintExpr($call))];
		}

		return [$calls, $printer->prettyPrintFile($stmts)];
	}

	// Latte 2 FormMacros write no line marker for {label}, so its statement borrows the next
	// marked line; Latte 3 marks every tag.
	private static function labelLine(int $line, int $latte2Line): int
	{
		return InstalledVersionsGuard::latteMajor() === 2 ? $latte2Line : $line;
	}

	private static function assertNoBridgeResidue(string $php): void
	{
		self::assertStringNotContainsString('formsStack', $php);
		self::assertStringNotContainsString('FormsLatte', $php);
		self::assertStringNotContainsString('->forms->', $php);
		self::assertStringNotContainsString('ʟ_', $php);
		self::assertStringNotContainsString('?->', $php);
	}

}
