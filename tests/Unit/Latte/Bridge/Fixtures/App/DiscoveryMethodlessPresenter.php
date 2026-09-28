<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Presenter;

// No action and no render method at all - in Nette both are optional, so the template file alone
// makes the view render.
final class DiscoveryMethodlessPresenter extends Presenter
{

}
