<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

final class WizardMeta
{

	private string $stepMethodPrefix;

	public function __construct(string $stepMethodPrefix)
	{
		$this->stepMethodPrefix = $stepMethodPrefix;
	}

	public function getStepMethodPrefix(): string
	{
		return $this->stepMethodPrefix;
	}

}
