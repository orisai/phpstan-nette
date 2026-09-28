<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Latte\Engine;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

// The only template surface is the native getTemplate() return type - no $template member,
// no @property tag, no createTemplate() - so neither qualification nor ladder resolution can
// come from any other rung.
final class GetTemplateOnlySurfaceFixture
{

	/** @var bool */
	public $flag = false;

	public function getTemplate(): DefaultTemplate
	{
		return new DefaultTemplate(new Engine());
	}

	public function render(): void
	{
		$tpl = $this->getTemplate();
		$tpl->heading = 'from-get-template';

		if ($this->flag) {
			$tpl->subtitle = 'maybe';
		}

		$tpl->render();
	}

}
