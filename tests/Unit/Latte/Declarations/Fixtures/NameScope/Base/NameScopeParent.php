<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Base;

use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported\ImportedOption as ParentOption;

abstract class NameScopeParent
{

	/** @var list<ParentOption> */
	public array $inherited = [];

	/** @var BaseOnly|null */
	public $inheritedSibling;

}
