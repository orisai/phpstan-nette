<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Template;
use stdClass;

class LexicalCallBaseFixture
{

	use LexicalTraitTargetTrait;

	/** @var Template|stdClass */
	public $template;

	public function invokeLexicalSelf(): void
	{
		self::traitTarget();
	}

}
