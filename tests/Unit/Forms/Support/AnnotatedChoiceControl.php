<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

use Nette\Forms\Controls\BaseControl;

/**
 * @form-read-type int|string|null
 * @form-choice single int
 * @form-choice-open string
 */
final class AnnotatedChoiceControl extends BaseControl
{

}
