<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\TemplateBinding;

/**
 * @template C of BoundControlBase
 * @template-covariant T
 */
abstract class BoundTemplateBase
{

	/** @var C */
	public BoundControlBase $control;

	/** @var list<C> */
	public array $controls = [];

	/** @var T|null */
	public $item;

}
