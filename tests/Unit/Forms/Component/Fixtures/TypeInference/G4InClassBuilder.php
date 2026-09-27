<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\TypeInference;

use Nette\Application\UI\Form;
use function PHPStan\Testing\assertType;

final class G4InClassBuilder extends Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('email');
	}

	public function check(): void
	{
		assertType('Nette\Forms\Controls\TextInput', $this['email']);
	}

}
