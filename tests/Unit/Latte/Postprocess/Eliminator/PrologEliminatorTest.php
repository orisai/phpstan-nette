<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\PrologEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

final class PrologEliminatorTest extends BaseTestCase
{

	private const LATTE3_CLASS = <<<'PHP'
<?php

final class T extends Latte\Runtime\Template
{
	public function main(): void
	{
		if ($this->getParentName()) {
			return get_defined_vars();
		}
	}

	public function prepare(): array
	{
		extract($this->params);

		if (!$this->getReferringTemplate() || $this->getReferenceType() === 'extends') {
			foreach (array_intersect_key(['item' => '48'], $this->params) as $ʟ_v => $ʟ_l) {
				trigger_error("Variable \$$ʟ_v overwritten in foreach on line $ʟ_l");
			}
		}
		return get_defined_vars();
	}

	public function lattePrepare(): void
	{
	}
}

PHP;

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testLatte3DropsTheOverwriteWarningAndKeepsTheLatte2OnlyShapes(string $latteLine): void
	{
		// No {extends} guard and no prepare() reach the eliminators on Latte 3, so neither is a Latte 3 shape.
		self::assertSame(<<<'PHP'
<?php

final class T extends \Latte\Runtime\Template
{
    public function main(): void
    {
        if ($this->getParentName()) {
            return [];
        }
    }
    public function prepare(): array
    {
        \extract($this->params);
        return [];
    }
    public function lattePrepare(): void
    {
    }
}
PHP, self::eliminate($latteLine, self::LATTE3_CLASS));
	}

	public function testLatte2DropsTheExtendsGuardAndTheEmptyPrepare(): void
	{
		self::assertSame(<<<'PHP'
<?php

final class T extends \Latte\Runtime\Template
{
    public function main(): void
    {
    }
    public function prepare(): array
    {
        \extract($this->params);
        return [];
    }
}
PHP, self::eliminate(ShapeFamily::LATTE_2, self::LATTE3_CLASS));
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
		return EliminatorRun::apply($php, new PrologEliminator(EliminatorRun::family($latteLine)));
	}

}
