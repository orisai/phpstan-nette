<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteFixStrippingRegistry;
use PhpParser\Node\Expr\BinaryOp;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class LatteFixStrippingRegistryTest extends BaseTestCase
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

		$registry = new LatteFixStrippingRegistry($mockDelegate);

		$rules1 = $registry->getRules(BinaryOp::class);
		$rules2 = $registry->getRules(BinaryOp::class);

		$this->assertSame($rules1, $rules2);
	}

}
