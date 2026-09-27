<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Catalog;

interface DuplicateTextCatalog
{

	/** @form-read-type int */
	public function addText(): void;

}
