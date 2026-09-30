<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;
use function substr_compare;

// Each analysed template's own {templateType} declaration, carried to the AGGREGATE stage. The
// template-type checks compare it against a pairing verdict computed from OTHER files' method
// bodies, which is why they cannot live in the per-file parse (see TemplateTypeChecker's own
// constraint note); the finalizer that runs them has no parser memo to read the declaration from,
// because collected data is the only channel from an analysis worker to it.
//
// An entry is emitted for EVERY analysed template, including one with no {templateType} at all -
// orisai.nette.latte.templateTypeRequired's whole subject is the absence.
//
// Deliberately re-reads and re-scans the source instead of reusing LatteRoutingParser's memo:
// reaching that memo means reaching the defaultAnalysisParser! service (the DI cycle documented on
// TemplateTypeChecker) and assuming the parser ran before this collector for this file. The scan is
// a string pass beside a full Latte compile of the same bytes that the parse already paid for.
/**
 * @implements Collector<FileNode, array{path: string, class: string|null, line: int}>
 */
final class LatteTemplateTypeDeclarationCollector implements Collector
{

	private TemplateEdgeIndex $edgeIndex;

	private LatteUniverse $universe;

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	public function __construct(ConfigurationGuard $guard, TemplateEdgeIndex $edgeIndex, LatteUniverse $universe)
	{
		$this->edgeIndex = $edgeIndex;
		$this->universe = $universe;
		$this->enabled = $guard->isLatteEnabled();
		$this->discoveryStoreEnabled = $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	/**
	 * @param FileNode $node
	 * @return array{path: string, class: string|null, line: int}|null
	 */
	public function processNode(Node $node, Scope $scope)
	{
		if (!$this->enabled || !$this->discoveryStoreEnabled) {
			return null;
		}

		$file = $scope->getFile();
		if (substr_compare($file, '.latte', -6) !== 0) {
			return null;
		}

		$declarations = $this->edgeIndex->findDeclarations($file);
		if ($declarations === null) {
			return null;
		}

		return [
			'path' => $this->universe->relativePath($file),
			'class' => $declarations->getTemplateTypeClass(),
			'line' => $declarations->getTemplateTypeLine() ?? 1,
		];
	}

}
