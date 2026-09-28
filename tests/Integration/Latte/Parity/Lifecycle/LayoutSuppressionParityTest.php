<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle;

use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Template;
use function ob_get_clean;
use function ob_start;
use function trim;

// The tripwire for TemplateEdgeIndex's auto-layout ingestion. Every rule the model applies when
// deciding whether a discovery layout record may attach to a rendered template is proven here
// against real Latte + nette/application, so a vendor upgrade that moves the goalposts breaks a
// test rather than silently turning the analyser's layout edges into fiction.
final class LayoutSuppressionParityTest extends LifecycleParityTestCase
{

	public function testTemplateWithoutOwnLayoutConsultsTheLayoutFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutblock']));

		self::assertSame(
			['findLayoutTemplateFile(view=layoutblock)'],
			$presenter->layoutLookups,
			'a template declaring no {layout}/{extends} of its own still lets UIRuntime::initialize()'
				. ' run the presenter-side auto-layout walk',
		);
		self::assertSame('LAYOUT[block-body]', $output);
	}

	// {define} registers a block under its own name exactly like {block} does, so it satisfies
	// UIRuntime::initialize()'s block-set condition on its own.
	public function testDefineOnlyTemplateStillConsultsTheLayoutFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutdefine']));

		self::assertSame(['findLayoutTemplateFile(view=layoutdefine)'], $presenter->layoutLookups);
		self::assertSame('LAYOUT[]', $output, 'the layout renders, and its {ifset content} slot stays empty');
	}

	// THE condition the owner amendment does not spell out and the model therefore has to carry
	// separately: UIRuntime::initialize() bails before findLayoutTemplateFile() when the template
	// registers no non-'_'-prefixed block at all.
	public function testBlocklessTemplateNeverConsultsTheLayoutFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutblockless']));

		self::assertSame(
			[],
			$presenter->layoutLookups,
			'no {block}/{define} means an empty block set, and UIRuntime::initialize() then leaves'
				. ' $parentName null without ever asking the presenter for a layout',
		);
		self::assertSame('blockless-body', $output);
	}

	// A snippet's block is registered as '_box'; initialize()'s own array_filter drops every
	// '_'-prefixed name before testing the set, so a snippet-only template stays layout-less. (A
	// hand-written {block _x} cannot exist - BlockMacros rejects the name at compile time.)
	public function testSnippetOnlyTemplateNeverConsultsTheLayoutFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutsnippet']));

		self::assertSame([], $presenter->layoutLookups);
		self::assertStringContainsString('snippet-body', $output);
		self::assertStringNotContainsString('LAYOUT[', $output);
	}

	public function testLayoutNoneRendersLayoutLessAndNeverConsultsTheFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutnone']));

		self::assertSame(
			[],
			$presenter->layoutLookups,
			'{layout none} compiles to $this->parentName = false, which fails'
				. ' UIRuntime::initialize()\'s `=== null` guard',
		);
		self::assertSame('none-body', $output);
	}

	public function testExplicitLayoutWinsAndNeverConsultsTheFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutexplicit']));

		self::assertSame(
			[],
			$presenter->layoutLookups,
			'an explicit {layout \'file\'} sets $this->parentName itself - the .latte-side edge'
				. ' extraction already models it, and the auto walk never runs',
		);
		self::assertSame('EXPLICIT[explicit-body]', $output);
	}

	// {layout auto} is the one declaration that REQUESTS the walk: UIMacros::macroExtends() writes
	// the findLayoutTemplateFile() call straight into the prolog, ahead of - and independent of -
	// initialize()'s block-set condition.
	public function testLayoutAutoConsultsTheFinderEvenWithoutBlocks(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutauto']));

		self::assertSame(['findLayoutTemplateFile(view=layoutauto)'], $presenter->layoutLookups);
		self::assertSame('LAYOUT[]', $output);
	}

	// DISCLOSED DIVERGENCE: the model suppresses auto-layout for a dynamic {layout $var} (owner
	// amendment - the existing dynamicExtends OPEN handling covers it), but vendor does NOT: a
	// $var evaluating to null leaves $parentName null and initialize() then runs the walk anyway.
	// The model is therefore an under-approximation here, not parity - pinned so the gap stays a
	// known, deliberate one.
	public function testDynamicLayoutResolvingToNullStillFallsThroughToTheFinder(): void
	{
		$presenter = $this->createPresenter();
		$output = $this->render($this->runPresenter($presenter, ['action' => 'layoutdynamic']));

		self::assertSame(['findLayoutTemplateFile(view=layoutdynamic)'], $presenter->layoutLookups);
		self::assertSame('LAYOUT[dynamic-body]', $output);
	}

	private function render(Response $response): string
	{
		self::assertInstanceOf(TextResponse::class, $response);
		$template = $response->getSource();
		self::assertInstanceOf(Template::class, $template);

		ob_start();

		try {
			$template->render();
		} finally {
			$output = (string) ob_get_clean();
		}

		return trim($output);
	}

}
