<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Form;
use function PHPStan\dumpType;

$form = new Form();
$opts = $form->addContainer('opts');
$opts->addCheckbox('flag');

dumpType($form['opts']['flag']); // => Nette\Forms\Controls\Checkbox
