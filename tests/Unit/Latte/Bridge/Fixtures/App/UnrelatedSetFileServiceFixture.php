<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

final class UnrelatedSetFileServiceFixture
{

	// The real App\Presenters\UserInfoPresenter::createComponentUploadAgreement() shape:
	// $service = $this->agreementFactory->create(...); $service->setFile(...); - a factory
	// method returning the (unrelated, non-template) receiver, not a plain property fetch.
	public function create(): self
	{
		return $this;
	}

	public function setFile(string $name): self
	{
		return $this;
	}

}
