<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Cache\LatteRenderFactsValueExtension;
use OriPhpstan\Nette\Latte\Cache\LatteTemplateValueExtension;
use OriPhpstan\Nette\Latte\Cache\TemplateNeighbourhood;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use function substr_compare;

/**
 * @implements Rule<FileNode>
 */
final class LatteDependencyRule implements Rule
{

	private bool $enabled;

	private bool $narrowingEnabled;

	private LatteUniverse $universe;

	private TemplateNeighbourhood $neighbourhood;

	private SiteScopeStore $siteScopeStore;

	public function __construct(
		ConfigurationGuard $guard,
		LatteUniverse $universe,
		TemplateNeighbourhood $neighbourhood,
		SiteScopeStore $siteScopeStore
	)
	{
		$guard->validate();
		$this->enabled = $guard->isLatteEnabled();
		$this->narrowingEnabled = $guard->isLatteNarrowingEnabled();
		$this->universe = $universe;
		$this->neighbourhood = $neighbourhood;
		$this->siteScopeStore = $siteScopeStore;
	}

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	/**
	 * @param FileNode $node
	 * @return list<never>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$file = $scope->getFile();
		if (!$this->enabled || substr_compare($file, '.latte', -6) !== 0) {
			return [];
		}

		$relativePath = $this->universe->relativePath($file);

		$scope->trackValueDependency(LatteTemplateValueExtension::class, LatteTemplateValueExtension::HARVEST);
		$scope->trackValueDependency(LatteTemplateValueExtension::class, LatteTemplateValueExtension::TEMPLATE_FACTORY);
		$scope->trackValueDependency(
			LatteTemplateValueExtension::class,
			LatteTemplateValueExtension::neighbourhoodKey($relativePath),
		);
		$scope->trackValueDependency(
			LatteTemplateValueExtension::class,
			LatteTemplateValueExtension::harvestHostKey($relativePath),
		);

		foreach ($this->neighbourhood->rendererClasses($relativePath) as $rendererClass) {
			$scope->trackValueDependency(
				LatteRenderFactsValueExtension::class,
				LatteRenderFactsValueExtension::sitelessKey($rendererClass),
			);
		}

		// An includer's arguments reach every template below it, through any number of include hops.
		$ancestors = $this->neighbourhood->ancestors($relativePath);
		foreach ($ancestors as $includer) {
			$scope->trackFileDependency($this->universe->absolutePath($includer));
		}

		if ($this->narrowingEnabled) {
			// The template's own slice too: its include checks read the captures it wrote.
			$scope->trackFileDependency($this->siteScopeStore->slicePath($relativePath));
			foreach ($ancestors as $includer) {
				$scope->trackFileDependency($this->siteScopeStore->slicePath($includer));
			}
		}

		return [];
	}

}
