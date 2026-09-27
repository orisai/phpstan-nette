<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Testing;

/**
 * Debug helper: prints the resolved component shape of $component as a PHPStan-shape block
 * (see ComponentShapeRenderer). $depth limits nesting; $formValues toggles the input
 * `<write, read>` generics. No-op at runtime — only ComponentShapeDumpRule acts on it.
 *
 * @param mixed $component
 */
function dumpComponent($component, ?int $depth = null, bool $formValues = true): void
{
}
