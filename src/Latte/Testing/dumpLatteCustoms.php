<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints the harvested GLOBAL customs (filters/functions/macros, one line per kind,
 * orig-case names appended where they differ from the lowercase key) plus the CURRENT template's
 * own {templateType} per-template filter/function entries (declaring class named) - or, when
 * neither orisai.nette.dic.containerLoader nor orisai.nette.latte.engineLoader resolves an engine, the explicit no-source
 * state. No-op at runtime - only LatteDebugDumpRule acts on it.
 */
function dumpLatteCustoms(): void
{
}
