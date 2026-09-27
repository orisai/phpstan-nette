<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Nette\Forms\Form;
use function PHPStan\dumpType;

/**
 * Method access is analysed exactly like array access for an absent/unknown component:
 * getComponent('x', false) matches $form['x'] ?? null (IComponent|null) and the throwing
 * getComponent('x') matches $form['x'] (IComponent).
 */
final class CmGetComponentAbsentMatchesArrayAccess extends Control
{

	public function go(Form $form): void
	{
		dumpType($form->getComponent('absent', false)); // => Nette\ComponentModel\IComponent|null
		dumpType($form['absent'] ?? null); // => Nette\ComponentModel\IComponent|null
		dumpType($form->getComponent('absent')); // => Nette\ComponentModel\IComponent
		dumpType($form['absent']); // => Nette\ComponentModel\IComponent
	}

}
