<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class TemplateContextTest extends BaseTestCase
{

	public function testIdenticalVarMapsInADifferentInsertionOrderAreOneContext(): void
	{
		$forward = new TemplateContext(
			['user' => 'App\User', 'basePath' => 'string', 'flashes' => 'array'],
			['a.latte'],
		);
		$backward = new TemplateContext(
			['flashes' => 'array', 'basePath' => 'string', 'user' => 'App\User'],
			['b.latte'],
		);

		self::assertSame(['basePath' => 'string', 'flashes' => 'array', 'user' => 'App\User'], $forward->getVars());
		self::assertSame($forward->getVars(), $backward->getVars());
		self::assertSame($forward->canonicalHash(), $backward->canonicalHash());
	}

}
