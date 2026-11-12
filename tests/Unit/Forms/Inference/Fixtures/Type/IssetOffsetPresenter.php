<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type;

use Nette\Application\UI\Presenter;
use function PHPStan\Testing\assertType;

final class IssetOffsetPresenter extends Presenter
{

	public function renderDefault(): void
	{
		if (isset($this['grid'])) {
			assertType('Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Type\IssetOffsetControl', $this['grid']);
		}
	}

	protected function createComponentGrid(): IssetOffsetControl
	{
		return new IssetOffsetControl();
	}

}
