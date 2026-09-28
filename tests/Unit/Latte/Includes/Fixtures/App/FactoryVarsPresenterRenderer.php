<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\DefaultTemplate;

/**
 * @property-read DefaultTemplate $template
 */
// The sound half of the presenter axis: Presenter::getPresenterIfExists() is a final override
// returning $this, so a presenter handing the factory itself makes BOTH variables definite and
// both of them this class.
final class FactoryVarsPresenterRenderer extends Presenter
{

}
