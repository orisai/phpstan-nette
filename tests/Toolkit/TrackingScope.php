<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\NodeCallbackInvoker;
use PHPStan\Analyser\Scope;

// What PHPStan really hands rules and extensions since 2.3, as one interface a test double can be
// built from: a bare Scope stub fails the moment the code under test declares a dependency.
interface TrackingScope extends Scope, NodeCallbackInvoker, CollectedDataEmitter, DependencyTracker
{

}
