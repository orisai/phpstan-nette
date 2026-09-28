<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

class ConventionNameFactory
{

	public function create(): ConventionNameWidget
	{
		return new ConventionNameWidget();
	}

}
