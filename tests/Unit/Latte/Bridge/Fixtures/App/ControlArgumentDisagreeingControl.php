<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\TemplateFactory;

// BOTH shapes in one class: the inherited vendor createTemplate() the render path uses, and a
// standalone factory call for a second template. Neither shape may win - the class really does
// create templates both ways, so which one a given template came from is not a per-class fact.
// The standalone call is also nested inside a larger expression, where the statement-shaped
// extractors cannot see it.
final class ControlArgumentDisagreeingControl extends Control
{

	private TemplateFactory $templateFactory;

	public function __construct(TemplateFactory $templateFactory)
	{
		$this->templateFactory = $templateFactory;
	}

	public function render(): void
	{
		$this->template->heading = 'ok';
		$this->template->render(__DIR__ . '/control-argument-disagreeing.latte');
	}

	public function renderMail(): void
	{
		$this->templateFactory->createTemplate()->renderToString(
			__DIR__ . '/control-argument-mail.latte',
		);
	}

}
