<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

// The real-world shape: the name is written from the OWNING class's component factory, and the
// receiver's type is only knowable from the factory call's return type.
class ConventionNameCaller
{

	public function wire(ConventionNameFactory $factory, ConventionNameWidget $other, string $dynamic): void
	{
		$service = $factory->create();
		$service->setFile('uploadAgreement');

		// A full path is the already-modelled literal channel: the locator's own file_exists() gate
		// hands it straight back as the template file, no convention derivation involved.
		$other->setFile('/absolute/uploadAgreement.latte');

		// Neither an empty name nor a non-constant one derives anything.
		$other->setFile('');
		$other->setFile($dynamic);
	}

}
