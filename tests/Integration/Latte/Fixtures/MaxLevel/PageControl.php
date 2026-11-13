<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel;

use Nette\Application\UI\Control;

final class PageControl extends Control
{

	public function heading(): string
	{
		return '';
	}

}
