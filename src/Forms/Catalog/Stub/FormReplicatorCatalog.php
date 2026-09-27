<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

interface FormReplicatorCatalog
{

	/** @form-replicator 1 Kdyby\Replicator\Container */
	public function addDynamic(): void;

}
