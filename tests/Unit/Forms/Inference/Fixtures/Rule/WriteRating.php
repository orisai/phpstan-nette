<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Nette\Forms\Controls\BaseControl;

/**
 * @form-read-type 'a'|'b'|'c'
 * @form-write-type 'a'|'b'|'c'
 */
final class WriteRating extends BaseControl
{

}
