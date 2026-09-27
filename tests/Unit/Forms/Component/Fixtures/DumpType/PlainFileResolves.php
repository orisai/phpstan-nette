<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Forms\Form;
use function PHPStan\dumpType;

$form = new Form();
$section = $form->addContainer('section');
$section->addText('title');

dumpType($form['section']['title']); // => Nette\Forms\Controls\TextInput
