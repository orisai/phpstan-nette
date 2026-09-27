<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;

class UnresolvableFactory
{

	private ApplicationForm $prebuilt;

	public function create(): ApplicationForm
	{
		return $this->prebuilt;
	}

}
