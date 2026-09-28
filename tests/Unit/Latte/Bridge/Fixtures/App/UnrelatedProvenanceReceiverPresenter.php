<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class UnrelatedProvenanceReceiverPresenter
{

	/** @var Template|stdClass */
	public $template;

	private UnrelatedProvenanceReceiverService $mailerService;

	public function __construct(UnrelatedProvenanceReceiverService $mailerService)
	{
		$this->mailerService = $mailerService;
	}

	public function actionDefault(): void
	{
		$mailerTpl = $this->mailerService->createTemplate();
		$mailerTpl->subject = 'hi';
	}

}
