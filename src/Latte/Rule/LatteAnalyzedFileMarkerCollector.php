<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;
use function substr_compare;

/**
 * @implements Collector<FileNode, string>
 */
final class LatteAnalyzedFileMarkerCollector implements Collector
{

	private LatteUniverse $universe;

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	// Three consumers across two opt-in flags: LatteSiteScopeWriterRule behind narrowing,
	// LatteTemplateGraphRule and LatteFormsRule behind the discovery-store flag, which they ride for
	// their REPORTABLE TEMPLATE SET alone - no writer stands behind that flag any more, so
	// discoveryStoreEnabled here gates the marker for two pure readers. The marker fires when either
	// flag needs it and stays per-file waste-free when both are off.
	public function __construct(ConfigurationGuard $guard, LatteUniverse $universe)
	{
		$this->universe = $universe;
		$this->enabled = $guard->isLatteNarrowingEnabled();
		$this->discoveryStoreEnabled = $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	/**
	 * @param FileNode $node
	 * @return string|null
	 */
	public function processNode(Node $node, Scope $scope)
	{
		if (!$this->enabled && !$this->discoveryStoreEnabled) {
			return null;
		}

		$file = $scope->getFile();
		if (substr_compare($file, '.latte', -6) !== 0) {
			return null;
		}

		return $this->universe->relativePath($file);
	}

}
