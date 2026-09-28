<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints the pairing verdict PairingJudge computes for the given class - the
 * resolved primary template class with its provenance channel and certainty, every observed
 * candidate, per-site pairings, merged conflicts and opaque channels. Callable from any analyzed
 * context (a .latte template or plain PHP). No-op at runtime - only LatteDebugDumpRule acts on it.
 *
 * @param class-string $className
 */
function dumpLattePairing(string $className): void
{
}
