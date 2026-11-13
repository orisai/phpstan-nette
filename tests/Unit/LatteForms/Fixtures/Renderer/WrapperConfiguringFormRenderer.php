<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\LatteForms\Fixtures\Renderer;

// The renderer's $wrappers is an array whose keys happen to be component names. A value no
// Container::offsetSet() accepts can never be a component written into a component.
final class WrapperConfiguringFormRenderer
{

	/** @var array<string, array<string, string|null>> */
	public array $wrappers = [];

	public function __construct(self $other)
	{
		$this->wrappers['wrappedForm']['container'] = null;
		$this->wrappers['wrappedForm']['.error'] = 'has-error';
		$other->wrappers['wrappedForm']['suffix'] = ':' . 'x';
		$other->wrappers['wrappedForm']['flags'] = [];
	}

}
