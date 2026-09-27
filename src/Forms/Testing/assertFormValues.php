<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Testing;

/**
 * Test helper (the assert counterpart of dumpFormValues): FormValuesAssertRule projects the
 * read-values shape of $component the same way and reports an error unless it equals $expected.
 * No-op at runtime.
 *
 * @param mixed $component
 */
function assertFormValues($component, string $expected): void
{
}
