<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// Replicates app/presenters/NewsPresenter: a parent reaching two offsets deep into a child control's
// form on one code path only. The owner IS nameable here ($this['newsManage']), so the fact is keyed
// to the class that createComponentNewsManage returns.
final class ParentPresenterReplica
{

	public function createComponentNewsManage(): NewsManageRenderer
	{
		return new NewsManageRenderer();
	}

	public function actionEdit(): void
	{
		$this['newsManage']['newsForm']->addSubmit('files_submit');
	}

}
