<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Testing;

/**
 * Test helper (the assert counterpart of dumpComponent, mirroring PHPStan's assertType):
 * ComponentShapeAssertRule renders the resolved shape of $component the same way
 * dumpComponent does and reports an error unless it equals $expected. $depth and
 * $formValues are forwarded to the renderer. No-op at runtime.
 *
 * @param mixed $component
 */
function assertComponent($component, string $expected, ?int $depth = null, bool $formValues = true): void
{
}
