<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
// A plain component on the inherited vendor createTemplate(): the factory is handed this very
// instance, so $control is definitely there and IS this class. Its $presenter is the runtime
// attachment state, which nothing here can prove.
final class FactoryVarsControlRenderer extends Control
{

}
