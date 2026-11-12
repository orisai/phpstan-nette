<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use Closure;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\AttrShellEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\BlockDispatchEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\CaptureEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\ControlFlowEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EliminatorVisitor;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\IteratorEliminator;
use OriPhpstan\Nette\Latte\Postprocess\Eliminator\UiMacroEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;
use function sprintf;

// A statement-list shape is reduced in every statement container, not only in a method, an if
// or a foreach body: Latte places tags under {elseif}, {else}, {case}, loops and {try}.
final class StatementContainerTest extends BaseTestCase
{

	private const CONTAINERS = [
		'method' => '%s',
		'elseif' => "if (\$a) {\n} elseif (\$b) {\n%s\n}",
		'else' => "if (\$a) {\n} else {\n%s\n}",
		'case' => "switch (\$a) {\n\tcase 1:\n%s\n}",
		'while' => "while (\$a) {\n%s\n}",
		'do' => "do {\n%s\n} while (\$a);",
		'for' => "for (\$i = 0; \$i < 3; \$i++) {\n%s\n}",
		'foreach' => "foreach (\$a as \$b) {\n%s\n}",
		'try' => "try {\n%s\n} catch (\\Exception \$e) {\n}",
		'catch' => "try {\n} catch (\\Exception \$e) {\n%s\n}",
		'finally' => "try {\n} finally {\n%s\n}",
		'closure' => "\$f = function () {\n%s\n};",
		'declare' => "declare(ticks=1) {\n%s\n}",
		'block' => "{\n%s\n}",
	];

	/**
	 * @param Closure(ShapeFamily): EliminatorVisitor $eliminator
	 *
	 * @dataProvider provideShapes
	 */
	public function testShapeIsReducedInEveryStatementContainer(
		Closure $eliminator,
		string $latteLine,
		string $shape,
		string $reduced,
		bool $pruned = false
	): void
	{
		foreach (self::CONTAINERS as $container => $wrapper) {
			$actual = $pruned
				? EliminatorRun::applyPruned(
					EliminatorRun::template(sprintf($wrapper, $shape)),
					$eliminator(EliminatorRun::family($latteLine)),
				)
				: EliminatorRun::apply(
					EliminatorRun::template(sprintf($wrapper, $shape)),
					$eliminator(EliminatorRun::family($latteLine)),
				);
			self::assertSame(
				EliminatorRun::apply(EliminatorRun::template(sprintf($wrapper, $reduced))),
				$actual,
				$container,
			);
		}
	}

	/**
	 * @return iterable<string, array{0: Closure(ShapeFamily): EliminatorVisitor, 1: string, 2: string, 3: string, 4?: bool}>
	 */
	public function provideShapes(): iterable
	{
		$attrShellEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new AttrShellEliminator($family);
		$blockDispatchEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new BlockDispatchEliminator(
			$family,
		);
		$captureEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new CaptureEliminator($family);
		$controlFlowEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new ControlFlowEliminator(
			$family,
		);
		$iteratorEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new IteratorEliminator($family);
		$uiMacroEliminator = static fn (ShapeFamily $family): EliminatorVisitor => new UiMacroEliminator($family);

		$attributes = <<<'PHP'
$ʟ_tmp = ['data-x' => $x];
echo Latte\Essential\Nodes\NAttrNode::attrs($ʟ_tmp, false);
PHP;
		$attributesReduced = "echo Latte\\Essential\\Nodes\\NAttrNode::attrs(['data-x' => \$x], false);";

		yield 'n:attr, latte 3.1' => [$attrShellEliminator, ShapeFamily::LATTE_31, $attributes, $attributesReduced];
		yield 'n:attr, latte 3.0' => [$attrShellEliminator, ShapeFamily::LATTE_30, $attributes, $attributesReduced];
		yield 'n:attr, latte 2' => [
			$attrShellEliminator,
			ShapeFamily::LATTE_2,
			<<<'PHP'
$ʟ_tmp = ['data-x' => $x];
echo LR\Filters::htmlAttributes(isset($ʟ_tmp[0]) && is_array($ʟ_tmp[0]) ? $ʟ_tmp[0] : $ʟ_tmp);
PHP,
			"echo LR\\Filters::htmlAttributes(['data-x' => \$x]);",
		];

		$ifcontent = static fn (string $noop): string => <<<PHP
ob_start($noop);
try {
	echo '<p>';
	ob_start();
	try {
		echo \$x;
	} finally {
		\$ʟ_ifc[0] = rtrim(ob_get_flush()) === '';
	}
	echo '</p>';
} finally {
	if (\$ʟ_ifc[0] ?? null) {
		ob_end_clean();
	} else {
		echo ob_get_clean();
	}
}
PHP;
		$ifcontentReduced = "echo '<p>';\necho \$x;\necho '</p>';";

		yield 'n:ifcontent, latte 3.1' => [$attrShellEliminator, ShapeFamily::LATTE_31, $ifcontent("fn() => ''"), $ifcontentReduced];
		yield 'n:ifcontent, latte 2' => [$attrShellEliminator, ShapeFamily::LATTE_2, $ifcontent('function () {}'), $ifcontentReduced];

		$capture = static fn (string $noop): string => <<<PHP
ob_start($noop);
try {
	echo \$x;
} finally {
	\$ʟ_tmp = ob_get_length() ? new LR\\Html(ob_get_clean()) : ob_get_clean();
}
\$ʟ_fi = new LR\\FilterInfo('html');
\$v = \$ʟ_tmp;
PHP;
		$captureReduced = "echo \$x;\n\$v = \\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::capturedString();";

		yield '{capture}, latte 3.1' => [$captureEliminator, ShapeFamily::LATTE_31, $capture("fn() => ''"), $captureReduced];
		yield '{capture}, latte 2' => [$captureEliminator, ShapeFamily::LATTE_2, $capture('function () {}'), $captureReduced];
		yield '{spaceless}, latte 3.0' => [
			$captureEliminator,
			ShapeFamily::LATTE_30,
			<<<'PHP'
ob_start('Latte\Essential\Filters::spacelessHtmlHandler', 4096);
try {
	echo $x;
} finally {
	ob_end_flush();
}
PHP,
			'echo $x;',
		];

		$tryShell = <<<'PHP'
$ʟ_try[0] = [$ʟ_it ?? null];
ob_start(fn() => '');
try {
	echo 'risky';
} catch (Throwable $ʟ_e) {
	ob_clean();
	if (!($ʟ_e instanceof Latte\Essential\RollbackException) && isset($this->global->coreExceptionHandler)) {
		($this->global->coreExceptionHandler)($ʟ_e, $this);
	}
} finally {
	echo ob_get_clean();
	$iterator = $ʟ_it = $ʟ_try[0][0];
}
PHP;
		$tryShellReduced = <<<'PHP'
try {
	echo 'risky';
} catch (\Throwable $latteException) {
	if (!$latteException instanceof \Latte\Essential\RollbackException && isset($this->global->coreExceptionHandler)) {
		($this->global->coreExceptionHandler)($latteException, $this);
	}
}
PHP;

		yield '{try}, latte 3.1' => [$controlFlowEliminator, ShapeFamily::LATTE_31, $tryShell, $tryShellReduced];
		yield '{try}, latte 3.0' => [$controlFlowEliminator, ShapeFamily::LATTE_30, $tryShell, $tryShellReduced];

		yield '{if} capture, latte 3.1' => [
			$controlFlowEliminator,
			ShapeFamily::LATTE_31,
			<<<'PHP'
ob_start(fn() => '');
try {
	echo 'captured';
} finally {
	$ʟ_ifA = ob_get_clean();
}
if ($show) {
	echo $ʟ_ifA;
}
PHP,
			"if (\$show) {\n\techo 'captured';\n}",
		];

		$control = <<<'PHP'
$ʟ_tmp = $this->global->uiControl->getComponent('menu');
if ($ʟ_tmp instanceof Nette\Application\UI\Renderable) {
	$ʟ_tmp->redrawControl(null, false);
}
$ʟ_tmp->render();
PHP;
		$controlReduced = "\\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::component('menu');";

		yield '{control}, latte 3.1' => [$uiMacroEliminator, ShapeFamily::LATTE_31, $control, $controlReduced];

		yield '{embed}, latte 3.1' => [
			$blockDispatchEliminator,
			ShapeFamily::LATTE_31,
			<<<'PHP'
$this->enterBlockLayer(1, get_defined_vars());
$this->copyBlockLayer();
try {
	echo 'embedded';
} finally {
	$this->leaveBlockLayer();
}
PHP,
			"echo 'embedded';",
		];

		yield 'n:snippet, latte 3.1' => [
			$blockDispatchEliminator,
			ShapeFamily::LATTE_31,
			<<<'PHP'
$this->global->snippetDriver->enter('s1', 'static');
try {
	echo 'snippet';
} finally {
	$this->global->snippetDriver->leave();
}
PHP,
			"echo 'snippet';",
		];

		// The counters are marked in every container; ImplicitBindingPruner drops them, the read $iterator stays.
		yield '{foreach} $iterations reset, latte 2' => [
			$iteratorEliminator,
			ShapeFamily::LATTE_2,
			<<<'PHP'
$iterations = 0;
foreach ($iterator = $ʟ_it = new LR\CachingIterator($items, $ʟ_it ?? null) as $item) {
	echo $iterator->counter;
	$iterations++;
}
$iterator = $ʟ_it = $ʟ_it->getParent();
PHP,
			<<<'PHP'
$iterator = new \Latte\Runtime\CachingIterator($items);
foreach ($items as $item) {
	echo $iterator->counter;
}
PHP,
			true,
		];
	}

}
