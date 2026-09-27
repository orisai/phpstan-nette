<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\Support\FmChainBuilder;
use function PHPStan\dumpType;

/**
 * A form returned by a not-analysed builder whose method returns a chain
 * ($this->factory->assemble()) rather than a tracked variable. The builder's $this->factory
 * receiver must resolve scope-free: the on-demand recompute runs in this foreign consumer
 * scope, where $this->factory does not exist, so $scope->getType() would misresolve it.
 */
final class FmChainReceiverResolves extends Control
{

	public function go(FmChainBuilder $builder): void
	{
		$built = $builder->buildForm();
		dumpType($built['city']); // => Nette\Forms\Controls\TextInput
	}

}
