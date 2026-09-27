<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

interface FormRuleTypeCatalog
{

	/** @form-rule-cast :integer int integer */
	public function integer(): void;

	/** @form-rule-cast :float float float */
	public function float(): void;

}
