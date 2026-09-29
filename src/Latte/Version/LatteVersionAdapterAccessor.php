<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

// Resolves the adapter on first use, never at container build: with Latte analysis off no template
// is ever parsed, so an unsupported install must stay a guard message instead of a construction error.
final class LatteVersionAdapterAccessor
{

	private LatteVersionAdapterFactory $factory;

	private AdapterCollaborators $collaborators;

	private ?LatteVersionAdapter $adapter = null;

	public function __construct(LatteVersionAdapterFactory $factory, AdapterCollaborators $collaborators)
	{
		$this->factory = $factory;
		$this->collaborators = $collaborators;
	}

	public function get(): LatteVersionAdapter
	{
		return $this->adapter ??= $this->factory->create($this->collaborators);
	}

}
