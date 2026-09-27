<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

use Nette\Forms\Container;
use Nette\Forms\Controls\TextInput;

final class AnnotatedAddsContainer extends Container
{

	/** @form-adds $name */
	public function addSingleLine(string $name): TextInput
	{
		return $this->addText($name);
	}

	/**
	 * @form-adds $name Nette\Forms\Controls\TextArea
	 */
	public function addSecondParameter(string $label, string $name): void
	{
		$this->addTextArea($name)->setCaption($label);
	}

	/**
	 * @form-adds $first Nette\Forms\Controls\TextInput
	 * @form-adds $second \Nette\Forms\Controls\SelectBox
	 */
	public function addPair(string $first, string $second): void
	{
		$this->addText($first);
		$this->addSelect($second);
	}

	/** @form-adds $name */
	public function addUndeclaredReturn(string $name)
	{
		return $this->addText($name);
	}

	/**
	 * A tag whose name only STARTS with the adds tag is a different tag.
	 *
	 * @form-adds-later $name
	 */
	public function addLookalike(string $name): TextInput
	{
		return $this->addText($name);
	}

	public function addUntagged(string $name): TextInput
	{
		return $this->addText($name);
	}

}
