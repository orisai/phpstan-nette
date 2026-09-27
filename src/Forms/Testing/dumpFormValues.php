<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Testing;

/**
 * Debug helper: prints the read-values shape of $component (what getValues() yields) as
 * `ArrayHash{field: …, …}` — control classes dropped, containers shown as nested `array{…}`,
 * an open shape carrying `...<mixed>`. No-op at runtime — only FormValuesDumpRule acts on it.
 *
 * @param mixed $component
 */
function dumpFormValues($component): void
{
}
