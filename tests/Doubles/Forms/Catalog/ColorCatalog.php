<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Catalog;

interface ColorCatalog
{

	/** @form-read-type non-empty-string|null */
	public function addRgbColor(): void;

}
