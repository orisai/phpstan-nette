<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// A same-named getTemplate() with a non-template return type must not count as a surface.
final class NonTemplateIshGetTemplateFixture
{

	public function getTemplate(): string
	{
		return 'not a template';
	}

}
