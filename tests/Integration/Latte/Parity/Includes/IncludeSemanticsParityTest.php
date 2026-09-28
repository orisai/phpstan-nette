<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Includes;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function rtrim;

// Vendor-upgrade tripwire for the ONE semantic Latte 3 changes under this bridge's feet: Latte 2
// gives an included template its explicit args UNION the includer's ENTIRE param set
// (CoreMacros::macroInclude's `%node.array? + $this->params`), Latte 3 isolates include params to
// the explicit args alone. Latte 3 cannot be probed from here (not installed), so the honest
// artifact is a Latte-2 RUNTIME pin: every probe below asserts today's union/asymmetry behaviour,
// and each one turns RED on a Latte 3 upgrade instead of letting the analysis model silently
// become fiction. A failing probe here is the SIGNAL - see orisaiNette.latte.includeIsolation
// (Latte/wiring.neon), the flag that models the Latte 3 semantics for migration measurement.
// Overlaps RuntimeParity's testIncludeFilePassesParamsNotLocals/testLayoutSeesChildFinishedScope
// on purpose: those ground the analysis model against today's runtime, these two exist to break
// on the upgrade.
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
			'Latte 2.11.7 include semantics: the included template receives its explicit args UNION'
				. ' the includer\'s entire param set, explicit winning on key collision - so $inherited'
				. ' resolves although the include site never mentions it. Latte 3 isolates include'
				. ' params: this same fixture would leave $inherited undefined there, which is exactly'
				. ' what orisaiNette.latte.includeIsolation models.',
		);
	}

	public function testFileIncludeCompilesToTheParamsUnionOperator(): void
	{
		self::assertStringContainsString(
			'+ $this->params',
			$this->compile(['main' => 'is-union-main.latte', 'part' => 'is-union-part.latte'], 'main'),
			'the union is a compiled-code fact, not an accident of this fixture:'
				. ' CoreMacros::macroInclude emits `$this->createTemplate($file, %node.array? +'
				. ' $this->params, $mode)`. Latte 3 emits no such union term.',
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
				. ' sees strictly MORE than an include does. Layout/extends inheritance is a separate'
				. ' mechanism from include params and orisaiNette.latte.includeIsolation deliberately leaves it'
				. ' alone.',
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
			'BlockMacros::macroEmbed compiles the file form to `$this->createTemplate($name,'
				. ' %node.array, "embed")` - no `+ $this->params` term at all, so an embedded file is'
				. ' ALREADY isolated in Latte 2, exactly like {sandbox}. The bridge deliberately'
				. ' over-approximates it as include-like union (EdgeScope::resolve), which can only'
				. ' miss findings, never invent them; orisaiNette.latte.includeIsolation makes the embed edge match'
				. ' this runtime truth.',
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
