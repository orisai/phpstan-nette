<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

use Nette\Utils\Html;

final class FilterPresenter extends FilterBasePresenter
{

	use DescriptionTrait;

	private FilterHelpers $helpers;

	private FilterHelpers $service;

	public function __construct(FilterHelpers $helpers)
	{
		parent::__construct();
		$this->helpers = $helpers;
		$this->service = $helpers;
	}

	public function actionDefault(bool $flag): void
	{
		$this->template->addFilter('isSlideType', function (int $type): Html {
			return Html::el();
		});

		if ($flag) {
			$this->template->addFilter('maybe', 'strtoupper');
		}
	}

	public function renderDefault(): void
	{
		$latte = $this->template->getLatte();
		$latte->addFilter('phone', [FilterHelpers::class, 'email']);
		$this->template->getLatte()->addFilter('invokable', new FilterHelpers());
		$this->template->addFilter('fmt', [$this->helpers, 'format']);
		$this->template->addFilter('aware', [FilterHelpers::class, 'aware']);
		$this->template->addFilter('arrow', fn (int $i): int => $i);
		$this->template->addFilter('untypedArrow', fn ($i) => $i);
		$this->template->addFilter('unresolvable', $this->makeCallable());
		$this->template->addFilter('instanceAsStatic', [FilterHelpers::class, 'format']);
		$this->template->addFilter('missingMethod', [FilterHelpers::class, 'nope']);
		$this->template->addFilter('twice', [FilterHelpers::class, 'email']);
		$this->template->addFilter('twice', 'strtoupper');
		$this->template->addFilter('not a name', 'strtoupper');
		$this->service->format('x');
	}

	private function makeCallable(): callable
	{
		return 'strtoupper';
	}

}
