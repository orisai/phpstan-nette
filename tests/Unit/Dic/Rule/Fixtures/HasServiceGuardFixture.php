<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Dic\Rule\Fixtures;

use Nette\DI\Container;

final class HasServiceGuardFixture
{

	public function unguarded(Container $container): void
	{
		$container->hasService('nonexistent'); // error: always false
		$container->hasService('foo'); // error: always true
		$container->hasService('alphaOnly'); // OK: partial existence, legitimate probe
	}

	public function guardedIf(Container $container): void
	{
		if ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: guaranteed by guard
			$container->createService('alphaOnly'); // OK: guaranteed by guard
			$container->isCreated('alphaOnly'); // OK: guaranteed by guard
		}
	}

	public function earlyReturnNegation(Container $container): void
	{
		if (!$container->hasService('alphaOnly')) {
			return;
		}

		$container->getService('alphaOnly'); // OK: guaranteed after early return
	}

	public function ternary(Container $container): void
	{
		$container->hasService('alphaOnly')
			? $container->getService('alphaOnly') // OK: guaranteed in true branch
			: null;
	}

	public function andChain(Container $container): void
	{
		if ($container->hasService('alphaOnly') && $container->getService('alphaOnly') !== null) {
			// OK: guaranteed by preceding && operand
		}
	}

	public function crossNameAlias(Container $container): void
	{
		if ($container->hasService('fooRealAlias')) { // error: alias resolves to foo, present everywhere
			$container->getService('foo'); // OK: same resolved method name (createServiceFoo)
		}
	}

	public function deadGuardChainedAlias(Container $container): void
	{
		if ($container->hasService('chainedAlias')) { // error: single-hop, always false
			$container->getService('foo'); // dead branch, not pinned
		}
	}

	public function elseBranch(Container $container): void
	{
		if ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: guaranteed by guard
		} else {
			$container->getService('alphaOnly'); // error: excluded by guard
		}
	}

	public function earlyReturnInverse(Container $container): void
	{
		if (!$container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // error: excluded by guard

			return;
		}

		$container->getService('alphaOnly'); // OK: guaranteed after early return
	}

	public function ternaryFalseArm(Container $container): void
	{
		$container->hasService('alphaOnly')
			? null
			: $container->getService('alphaOnly'); // error: excluded by guard
	}

	public function crossNameAliasElse(Container $container): void
	{
		if ($container->hasService('fooRealAlias')) { // error: alias resolves to foo, present everywhere
			return;
		}

		$container->getService('foo'); // error: excluded by guard (same resolved method name)
	}

	public function mixedGuardsIntersection(Container $container): void
	{
		if ($container->hasService('betaOnly')) {
			if (!$container->hasService('alphaOnly')) {
				$container->getService('betaOnly'); // OK: guaranteed by outer guard
				$container->getService('alphaOnly'); // error: excluded by inner guard
			}
		}
	}

	public function elseifUnrelatedCondition(Container $container, bool $somethingElse): void
	{
		if ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: guaranteed by guard
		} elseif ($somethingElse) {
			$container->getService('alphaOnly'); // error: excluded by first guard
		}
	}

	public function elseifChain(Container $container): void
	{
		if ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: guaranteed by guard
		} elseif ($container->hasService('betaOnly')) {
			$container->getService('betaOnly'); // OK: guaranteed by own guard
			$container->getService('alphaOnly'); // error: excluded by first guard
		} else {
			$container->getService('alphaOnly'); // error: excluded by guard chain
			$container->getService('betaOnly'); // error: excluded by guard chain
		}
	}

	public function elseifContradictoryRecheck(Container $container): void
	{
		if ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: guaranteed by guard
		} elseif ($container->hasService('alphaOnly')) {
			$container->getService('alphaOnly'); // OK: dead branch, truthy suppression wins
		}
	}

}
