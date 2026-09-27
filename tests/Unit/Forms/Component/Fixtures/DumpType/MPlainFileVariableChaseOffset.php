<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use function PHPStan\dumpType;

$form = new ApplicationForm();
$form->addDynamic('users', static function (FormContainer $c): void {
	$c->addText('username');
});

for ($i = 0; $i < 3; $i++) {
	$userRow = $form['users'][$i];
	dumpType($userRow['username']); // => Nette\Forms\Controls\TextInput
}
