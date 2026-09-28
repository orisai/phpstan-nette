<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// The receiver is not a TemplateFactory, but its createTemplate() returns a real template -
// the return-type trust boundary must accept it.
final class TemplateIshBuilderReceiverFixture
{

	private TemplateIshBuilderService $builder;

	public function __construct(TemplateIshBuilderService $builder)
	{
		$this->builder = $builder;
	}

	public function buildTemplate(): void
	{
		$tpl = $this->builder->createTemplate();
		$tpl->subject = 'hi';
	}

}
