<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function ksort;
use function serialize;
use function sha1;
use function strcmp;
use function usort;

final class TemplateContext
{

	/** @var array<string, string> */
	private array $vars;

	/** @var list<string> */
	private array $chain;

	// Debug-only (consumed by dumpLatteVarOrigin): which source contributed each var's TYPE.
	// Never participates in canonicalHash()/dedup - two contexts with identical $vars but different
	// provenance are still the same context for every narrowing/injection consumer, so folding
	// provenance into the hash would fork behaviour those consumers must not see.

	/** @var array<string, string> */
	private array $provenance;

	/**
	 * @param array<string, string> $vars
	 * @param list<string> $chain
	 * @param array<string, string> $provenance
	 */
	public function __construct(array $vars, array $chain, array $provenance = [])
	{
		ksort($vars);
		$this->vars = $vars;
		$this->chain = $chain;
		$this->provenance = $provenance;
	}

	/**
	 * @param array<string, string> $vars
	 * @param array<string, string> $provenance
	 */
	public static function root(array $vars, array $provenance = []): self
	{
		return new self($vars, [], $provenance);
	}

	/**
	 * @return array<string, string>
	 */
	public function getVars(): array
	{
		return $this->vars;
	}

	/**
	 * @return list<string>
	 */
	public function getChain(): array
	{
		return $this->chain;
	}

	/**
	 * @return array<string, string>
	 */
	public function getProvenance(): array
	{
		return $this->provenance;
	}

	public function canonicalHash(): string
	{
		return sha1(serialize($this->vars));
	}

	// The clone-index ordering DeclarationInjector::cloneMainPerContext() assigns (latteMain_ctxN) -
	// shared here so LatteDebugDumpRule can map a clone's scope back to the context that produced it
	// without recomputing (and risking drifting from) that assignment. strcmp(), not <=>:
	// canonicalHash() is a sha1 hex digest, and PHP's <=> compares two numeric-looking strings
	// numerically (with float precision loss for long digit runs) instead of lexicographically - a
	// hazard for a comparator whose only ordering contract is this being deterministic and total.

	/**
	 * @param list<self> $contexts
	 * @return list<self>
	 */
	public static function sortByHash(array $contexts): array
	{
		usort($contexts, static fn (self $a, self $b): int => strcmp($a->canonicalHash(), $b->canonicalHash()));

		return $contexts;
	}

}
