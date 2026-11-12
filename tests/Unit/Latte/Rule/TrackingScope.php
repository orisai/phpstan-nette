<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;

// @phpstan-ignore phpstanApi.interface (stubs the scope a CollectedDataNode rule gets)
interface TrackingScope extends Scope, DependencyTracker
{

}
