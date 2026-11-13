<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Support;

use OriPhpstan\Nette\Support\RerunValueExtension;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class RerunValueExtensionTest extends BaseTestCase
{

	public function testOneRunAgreesWithItselfAndNeverWithAnother(): void
	{
		$run = new RerunValueExtension();

		self::assertSame($run->getValue('rule'), $run->getValue('other rule'));
		self::assertNotSame($run->getValue('rule'), (new RerunValueExtension())->getValue('rule'));
		self::assertSame('rule', $run->keyFromResultCache($run->keyToResultCache('rule')));
	}

}
