<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

use Nette\Forms\Container;
use Nette\Forms\Controls\TextInput;
use Tests\OriPhpstan\Nette\Doubles\Forms\OurRegistrarTrait;

/**
 * A container standing in for one that ships in a package: the tests below hand the resolver
 * analysed paths this file is not under, so its declarations are foreign and the walk has no body of
 * theirs to read.
 */
final class UnannotatedRegistrarContainer extends Container
{

	use OurRegistrarTrait;

	/**
	 * The convention holds: one component, named by argument 0. Nothing is lost and nothing opens.
	 */
	public function addPlain(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * The convention is refuted. Argument 0 is the label, so reading it would register a component
	 * called after the caption and prove the real one absent.
	 */
	public function addLabelled(string $label, string $name): TextInput
	{
		return $this->addText($name)->setCaption($label);
	}

	/**
	 * Two components under one call, which argument 0 cannot account for either.
	 */
	public function addPair(string $first, string $second): void
	{
		$this->addText($first);
		$this->addText($second);
	}

	/**
	 * Declared, so the tag resolves the name properly and this is never asked about.
	 *
	 * @form-adds $name
	 */
	public function addDeclaredLabelled(string $label, string $name): TextInput
	{
		return $this->addText($name)->setCaption($label);
	}

	/**
	 * Registers nothing this recognises, so nothing is refuted — the walk's own answer stands.
	 */
	public function addNothing(string $name): void
	{
		$this->setDefaults([$name => '']);
	}

}
