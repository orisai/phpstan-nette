<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class TrustedStaticCreateTemplateReceiverPresenter
{

	/** @var Template|stdClass */
	public $template;

	public function actionDefault(): void
	{
		$tpl = KnownTemplateIshFactory::createTemplate();
		$tpl->subject = 'hi';
	}

}
