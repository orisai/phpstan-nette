<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;

// The one shared facts seam for every discovery-store consumer (collector, writer, ref resolver):
// all reads go through the envelope-validated cache, so a stale read set or a flipped existence
// probe recomputes the walk exactly once per run regardless of which consumer asks first.
final class DiscoveryRecordSource
{

	private PhpFactsCache $cache;

	private PhpRenderWalk $walk;

	public function __construct(PhpFactsCache $cache, PhpRenderWalk $walk)
	{
		$this->cache = $cache;
		$this->walk = $walk;
	}

	public function factsFor(string $className): PhpRenderFacts
	{
		$walk = $this->walk;

		return $this->cache->remember(
			$className,
			static fn (): PhpRenderFacts => $walk->factsFor($className),
		);
	}

}
