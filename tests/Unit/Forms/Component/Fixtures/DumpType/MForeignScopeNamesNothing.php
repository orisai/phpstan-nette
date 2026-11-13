<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;
use function PHPStan\dumpType;

// The builder's shape is first asked for from this method, whose scope types `$key` and `self::NAME`
// its own way. The shape is the builder's alone: its `self::NAME` and its dynamic `$key`, whichever
// consumer happens to ask first.
final class MForeignScopeNamesNothing
{

	private const NAME = 'consumer';

	public function read(ForeignScopeBuilder $builder): void
	{
		$key = 'user';
		$name = self::NAME;
		$form = $builder->createForm();
		dumpType($form['builder']); // => Nette\Forms\Controls\TextInput
		dumpType($form['user']); // => Nette\ComponentModel\IComponent
		dumpType($key . $name); // => 'userconsumer'
	}

}

final class ForeignScopeBuilder
{

	private const NAME = 'builder';

	/** @var array<string, string> */
	private array $roles;

	/**
	 * @param array<string, string> $roles
	 */
	public function __construct(array $roles)
	{
		$this->roles = $roles;
	}

	public function createForm(): RawForm
	{
		$form = new RawForm();
		$form->addText(self::NAME);
		foreach ($this->roles as $key => $label) {
			$form->addContainer($key);
		}

		return $form;
	}

}
