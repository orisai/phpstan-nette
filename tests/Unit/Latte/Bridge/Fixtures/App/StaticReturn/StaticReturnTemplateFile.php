<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\StaticReturn;

trait StaticReturnTemplateFile
{

	protected function getFormTemplateFile(): string
	{
		return __DIR__ . '/staticReturnTrait.latte';
	}

}
