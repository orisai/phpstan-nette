<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

interface FormModifierCatalog
{

	/** @form-read-by-arg Nette\Forms\Controls\DateTimeControl::FormatTimestamp=int; Nette\Forms\Controls\DateTimeControl::FormatObject=DateTimeImmutable; *=string */
	public function setFormat(): void;

}
