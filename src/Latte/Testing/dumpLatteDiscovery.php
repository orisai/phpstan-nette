<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints the template-file discovery DiscoveryResolver computes for the given class -
 * the view set with certainty and mutation provenance, per-view candidate paths with existence and
 * the chosen one, layout candidates, opaque channels with their reasons and the mutations proven
 * out of their lifecycle window. Callable from any analyzed context (a .latte template or plain
 * PHP). No-op at runtime - only LatteDebugDumpRule acts on it.
 *
 * @param class-string $className
 */
function dumpLatteDiscovery(string $className): void
{
}
