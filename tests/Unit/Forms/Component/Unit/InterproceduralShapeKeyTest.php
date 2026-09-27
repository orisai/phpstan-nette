<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Unit;

use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class InterproceduralShapeKeyTest extends BaseTestCase
{

	public function testClassComponentKey(): void
	{
		self::assertSame(
			'Sample\\Component\\BranchDataGrid#assignForm',
			InterproceduralShapeKey::forClassComponent('Sample\\Component\\BranchDataGrid', 'assignForm'),
		);
	}

	public function testLeadingBackslashNormalised(): void
	{
		self::assertSame('Sample\\X#form', InterproceduralShapeKey::forClassComponent('\\Sample\\X', 'form'));
	}

	public function testPlainFileKey(): void
	{
		self::assertSame(
			'file:/srv/content/edit.php#form',
			InterproceduralShapeKey::forPlainFile('/srv/content/edit.php', 'form'),
		);
	}

}
