<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Application\UI\Template;

// The vendor createTemplate() body reached on ANOTHER component's instance: it passes ITS $this,
// so the control the factory gets is that other object, not this renderer. Same vendor body as the
// inherited rung, opposite answer - the receiver is the whole difference.
final class ControlArgumentForeignReceiverControl extends Control
{

	private Control $other;

	public function __construct(Control $other)
	{
		$this->other = $other;
	}

	protected function createTemplate(): Template
	{
		return $this->other->createTemplate();
	}

}
