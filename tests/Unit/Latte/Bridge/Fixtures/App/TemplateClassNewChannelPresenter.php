<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class TemplateClassNewChannelPresenter
{

	protected function createTemplate(): TemplateClassChannelTargetTwo
	{
		return new TemplateClassChannelTargetTwo();
	}

}
