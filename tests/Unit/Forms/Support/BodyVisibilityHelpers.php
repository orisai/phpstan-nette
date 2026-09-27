<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

use Nette\Forms\Container;

/**
 * Four callees a body-stripping parser rewrites differently, so that "the body was not read" and
 * "the body adds nothing" can be shown to be separable rather than assumed to be.
 *
 * PHPStan's CleaningParser keeps closures and yields and drops everything else, so adds() and
 * addsNothing() both collapse to an empty statement list — the same list emptyBody() has for real —
 * while closureOnly() collapses to a synthesised statement with no source position.
 */
final class BodyVisibilityHelpers
{

	public function adds(Container $container): void
	{
		$container->addText('fromHelper');
	}

	public function addsNothing(Container $container): void
	{
		$container->setDefaults([]);
	}

	public function emptyBody(Container $container): void
	{
	}

	public function closureOnly(Container $container): void
	{
		$run = static function () use ($container): void {
			$container->addText('fromClosure');
		};
		$run();
	}

}
