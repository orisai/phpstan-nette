<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Rule\LatteProvenanceTipRegistry;
use PhpParser\Node\Expr\BinaryOp;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;

final class LatteProvenanceTipRegistryTest extends BaseTestCase
{

	public function testGetRulesMemoizes(): void
	{
		$mockRule = $this->createMock(Rule::class);
		$mockDelegate = $this->createMock(Registry::class);

		$mockDelegate
			->expects($this->once())
			->method('getRules')
			->with(BinaryOp::class)
			->willReturn([$mockRule]);

		$registry = new LatteProvenanceTipRegistry(
			$mockDelegate,
			new TemplateEdgeIndex(new LatteUniverse([], ''), TestAdapter::accessor()),
		);

		$rules1 = $registry->getRules(BinaryOp::class);
		$rules2 = $registry->getRules(BinaryOp::class);

		$this->assertSame($rules1, $rules2);
	}

}
