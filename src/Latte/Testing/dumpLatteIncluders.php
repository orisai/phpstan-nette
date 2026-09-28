<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints every incoming Latte edge (include/extends/import/embed/sandbox site) of
 * the CURRENT template, one per line, with its per-edge context count - or, for a template with
 * no discoverable edge, an explicit note that PHP-side wiring is invisible to this analysis. No-op
 * at runtime - only LatteDebugDumpRule acts on it.
 */
function dumpLatteIncluders(): void
{
}
