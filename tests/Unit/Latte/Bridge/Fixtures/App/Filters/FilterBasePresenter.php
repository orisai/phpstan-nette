<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
abstract class FilterBasePresenter extends Presenter
{

	protected function createTemplate(?string $class = null): Template
	{
		/** @var DefaultTemplate $template */
		$template = parent::createTemplate();
		$template->getLatte()->addFilter('email', 'Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterHelpers::email');

		return $template;
	}

	protected function beforeRender(): void
	{
		$this->template->addFilter('visibleForRole', function (?string $disableFor, int $level = self::LEVEL): string {
			return (string) $disableFor;
		});
	}

	public const LEVEL = 1;

}
