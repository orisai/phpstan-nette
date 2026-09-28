<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App;

use Nette\Application\UI\Presenter;

// The shared base two renderers of one template widen to. Abstract on purpose: it is never itself a
// renderer, only the answer the merge is expected to produce.
abstract class FactoryVarsAncestorBasePresenter extends Presenter
{

}
