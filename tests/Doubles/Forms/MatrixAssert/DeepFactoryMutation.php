<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Nette\Application\UI\Form;
use Nette\Forms\Container;
use function assert;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

// The reader precedes the factories so its rule fires before their store entries exist, forcing
// the on-demand deep path (shapeVendorMethod + nested factory compensation). Each method uses a
// distinct form variable name so the flattened plain-file walk cannot mix the bodies and answer
// for the deep path.
final class DeepFactoryMutationReader
{

	private DeepMutatingFormFactory $factory;

	public function __construct(DeepMutatingFormFactory $factory)
	{
		$this->factory = $factory;
	}

	public function read(): void
	{
		$form = $this->factory->create();
		assertComponent($form['fromFactory'], 'Nette\ComponentModel\IComponent');
		assertComponent($form['section'], 'Nette\ComponentModel\IComponent');
	}

}

class DeepMutatingFormFactory
{

	private DeepSectionFormFactory $inner;

	public function __construct(DeepSectionFormFactory $inner)
	{
		$this->inner = $inner;
	}

	public function create(): Form
	{
		$built = $this->inner->make();

		$section = $built->getComponent('section');
		assert($section instanceof Container);
		$section->addText('childInSection');

		return $built;
	}

}

class DeepSectionFormFactory
{

	public function make(): Form
	{
		$fresh = new Form();
		$fresh->addText('fromFactory');
		$sectionContainer = $fresh->addContainer('section');
		$sectionContainer->addText('inSection');

		return $fresh;
	}

}
