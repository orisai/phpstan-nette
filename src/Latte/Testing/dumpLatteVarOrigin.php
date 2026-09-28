<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Testing;

/**
 * Debug helper: prints, for every context this template is analyzed under, $var's effective type
 * and which source contributed it (declared/arg/captured/topLevel/default - see
 * ContextResolver::edgeProvenance and DeclaredVarsResolver::provenanceForFile), plus a final union
 * summary across all contexts. No-op at runtime - only LatteDebugDumpRule acts on it.
 *
 * @param mixed $var
 */
function dumpLatteVarOrigin($var): void
{
}
