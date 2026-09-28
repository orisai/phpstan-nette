<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

final class SetFileReceiverFilterPresenter
{

	/** @var Template|stdClass */
	public $template;

	private UnrelatedSetFileServiceFixture $agreementFactory;

	public function __construct(UnrelatedSetFileServiceFixture $agreementFactory)
	{
		$this->agreementFactory = $agreementFactory;
	}

	public function actionDefault(): void
	{
		$this->template->setFile(__DIR__ . '/real.latte');
	}

	// Mirrors App\Presenters\UserInfoPresenter::createComponentUploadAgreement() exactly: the
	// non-template receiver is a method-LOCAL variable assigned from a factory call, never a
	// plain property fetch.
	public function createComponentUploadAgreement(): void
	{
		$service = $this->agreementFactory->create();
		$service->setFile('uploadAgreement');
	}

}
