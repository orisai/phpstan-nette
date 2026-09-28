<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints the PHP-side render facts PhpRenderWalk extracts for the given class -
 * assignments with type/certainty/sites, setFile targets with kind, the resolved template class
 * with its provenance channel, and render sites. Callable from any analyzed context (a .latte
 * template or the class's own PHP file). No-op at runtime - only LatteDebugDumpRule acts on it.
 *
 * @param class-string $className
 */
function dumpLatteRenderFacts(string $className): void
{
}
