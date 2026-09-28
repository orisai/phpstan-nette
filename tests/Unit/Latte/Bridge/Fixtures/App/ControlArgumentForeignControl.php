<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

// A control argument that is a control but NOT this renderer: $control would be a real object of
// an unknown class, so the resolved answer is neither of the two shapes and nothing may be claimed.
final class ControlArgumentForeignControl extends Control
{

	private TemplateFactory $templateFactory;

	private Control $other;

	public function __construct(TemplateFactory $templateFactory, Control $other)
	{
		$this->templateFactory = $templateFactory;
		$this->other = $other;
	}

	protected function createTemplate(): Template
	{
		return $this->templateFactory->createTemplate($this->other);
	}

}
