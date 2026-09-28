<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class PropertyRhsPresenter
{

	/** @var Template|stdClass */
	public $template;

	public PropertyRhsSource $source;

	public function actionDefault(): void
	{
		$this->template->obj = $this->source;
	}

}
