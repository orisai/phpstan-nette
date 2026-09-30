<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Includes;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function rtrim;

// Runtime pins of the include scope EdgeScope::resolve() models, on every Latte line: a file include
// gets its explicit args UNION the includer's render params (not its {var} locals), a layout the
// child's finished scope, an embed its explicit args only.
final class IncludeSemanticsParityTest extends BaseTestCase
{

	public function testFileIncludeUnionsTheIncludersEntireParamSet(): void
	{
		self::assertSame(
			'INHERITED|ARG',
			$this->renderSet(
				['main' => 'is-union-main.latte', 'part' => 'is-union-part.latte'],
				'main',
				['inherited' => 'INHERITED', 'overridden' => 'PARAM'],
			),
			'the included template receives its explicit args UNION the includer\'s entire param set,'
				. ' explicit winning on key collision - so $inherited resolves although the include site'
				. ' never mentions it.',
		);
	}

	public function testFileIncludeCompilesToTheParamsUnionOperator(): void
	{
		self::assertStringContainsString(
			'+ $this->params',
			$this->compile(['main' => 'is-union-main.latte', 'part' => 'is-union-part.latte'], 'main'),
			'the union is a compiled-code fact, not an accident of this fixture.',
		);
	}

	public function testFileIncludeNeverSeesTheIncludersBodyLocals(): void
	{
		self::assertSame(
			'UNSEEN',
			$this->renderSet(
				['main' => 'is-locals-main.latte', 'part' => 'is-locals-part.latte'],
				'main',
				[],
			),
			'the union\'s right operand is $this->params - the includer\'s constructor params - never'
				. ' get_defined_vars(): a body-level {var} is a compiled local of the includer\'s own'
				. ' main() and is never written back into $this->params, so it cannot reach the'
				. ' included file. EdgeScope::resolve() models exactly this for include/embed.',
		);
	}

	public function testLayoutSeesTheChildsFinishedBodyLocals(): void
	{
		self::assertSame(
			'SEEN',
			$this->renderSet(
				['child' => 'is-layout-child.latte', 'lay' => 'is-layout-lay.latte'],
				'child',
				[],
			),
			'the other direction of the asymmetry EdgeScope::resolve() encodes: Template::doRender()'
				. ' runs `$this->params = $this->main()` and hands that finished scope - {var} locals'
				. ' included - to createTemplate($parentName, $this->params, "extends"), so a layout'
				. ' sees strictly MORE than an include does.',
		);
	}

	public function testEmbedFileFormReceivesOnlyItsOwnExplicitArgs(): void
	{
		self::assertSame(
			'UNSEEN',
			$this->renderSet(
				['main' => 'is-embed-main.latte', 'part' => 'is-embed-part.latte'],
				'main',
				['inherited' => 'INHERITED'],
			),
			'an embedded file receives its explicit args only, like {sandbox}; the analysis'
				. ' over-approximates it as an include-like union unless orisaiNette.latte.includeIsolation'
				. ' is on.',
		);
	}

	/**
	 * @param array<string, string> $templates
	 * @param array<string, mixed> $params
	 */
	private function renderSet(array $templates, string $entry, array $params): string
	{
		return rtrim($this->engineFor($templates)->renderToString($entry, $params), "\n");
	}

	/**
	 * @param array<string, string> $templates
	 */
	private function compile(array $templates, string $entry): string
	{
		return $this->engineFor($templates)->compile($entry);
	}

	/**
	 * @param array<string, string> $templates
	 */
	private function engineFor(array $templates): Engine
	{
		$sources = [];
		foreach ($templates as $name => $fixture) {
			$sources[$name] = FileSystem::read(__DIR__ . '/Fixtures/' . $fixture);
		}

		$engine = new Engine();
		$engine->setLoader(new StringLoader($sources));

		return $engine;
	}

}
