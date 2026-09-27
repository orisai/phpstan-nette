<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm;

final class UnannotatedRegistrarUser extends RawForm
{

	use UnannotatedRegistrarTrait;

}
