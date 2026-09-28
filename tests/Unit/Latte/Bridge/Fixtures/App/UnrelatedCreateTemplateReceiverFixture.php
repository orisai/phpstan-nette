<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class UnrelatedCreateTemplateReceiverFixture extends UnrelatedCreateTemplateReceiverAncestorFixture
{

	private UnrelatedCreateTemplateReceiverThing $thing;

	public function __construct(UnrelatedCreateTemplateReceiverThing $thing)
	{
		$this->thing = $thing;
	}

	public function buildTemplate(): void
	{
		$this->thing->createTemplate();
	}

}
