<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\DevTagEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class DevTagEliminatorTest extends BaseTestCase
{

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testLatte3DropsEssentialTracerAndTheTemplatePrintNodeExitPair(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		Nette\Bridges\ApplicationLatte\Nodes\TemplatePrintNode::printClass($this->getParameters(), 'Nette\\Bridges\\ApplicationLatte\\Template');
		exit;
		Tracy\Debugger::barDump(get_defined_vars(), 'variables') /* pos 1:1 */;
		Tracy\Debugger::barDump($x, '$x') /* pos 2:1 */;
		Latte\Essential\Tracer::throw() /* pos 3:1 */;
		LR\Tracer::throw();
		Nette\Bridges\ApplicationLatte\UIRuntime::printClass($this, NULL);
		exit;
		Nette\Forms\Blueprint::latte(is_object($ʟ_tmp = 'myForm') ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp]) /* pos 1:1 */;
		exit;
		Nette\Forms\Blueprint::dataClass(is_object($ʟ_tmp = $f) ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp]) /* pos 2:1 */;
		exit;
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed(\get_defined_vars(), 'variables');
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed($x, '$x');
        \Latte\Runtime\Tracer::throw();
        \Nette\Bridges\ApplicationLatte\UIRuntime::printClass($this, \NULL);
        exit;
PHP), self::eliminate($latteLine, $php));
	}

	public function testLatte2DropsRuntimeTracerAndTheUiRuntimeExitPair(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		Nette\Bridges\ApplicationLatte\UIRuntime::printClass($this, NULL);
		exit;
		Tracy\Debugger::barDump(($x), '$x') /* line 2 */;
		LR\Tracer::throw() /* line 3 */;
		Latte\Essential\Tracer::throw();
		Nette\Bridges\ApplicationLatte\Nodes\TemplatePrintNode::printClass($this->getParameters(), 'X');
		exit;
		Nette\Forms\Blueprint::latte($this->global->uiControl["myForm"]);
		exit;
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \OriPhpstan\Nette\Latte\Runtime\Helpers::analyzed($x, '$x');
        \Latte\Essential\Tracer::throw();
        \Nette\Bridges\ApplicationLatte\Nodes\TemplatePrintNode::printClass($this->getParameters(), 'X');
        exit;
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	public function testPrintClassWithoutTheExitStays(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		Nette\Bridges\ApplicationLatte\UIRuntime::printClass($this, NULL);
		echo 'x';
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        \Nette\Bridges\ApplicationLatte\UIRuntime::printClass($this, \NULL);
        echo 'x';
PHP), self::eliminate(ShapeFamily::LATTE_2, $php));
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideLatte3Lines(): iterable
	{
		yield 'latte 3.0' => [ShapeFamily::LATTE_30];
		yield 'latte 3.1' => [ShapeFamily::LATTE_31];
	}

	private static function eliminate(string $latteLine, string $php): string
	{
		return EliminatorRun::apply($php, new DevTagEliminator(EliminatorRun::family($latteLine)));
	}

}
