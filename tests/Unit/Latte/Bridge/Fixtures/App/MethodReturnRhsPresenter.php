<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class MethodReturnRhsPresenter
{

	/** @var Template|stdClass */
	public $template;

	private MethodReturnRhsRepository $repo;

	public function __construct(MethodReturnRhsRepository $repo)
	{
		$this->repo = $repo;
	}

	public function actionDefault(): void
	{
		$this->template->found = $this->repo->find();
	}

}
