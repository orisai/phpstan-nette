<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use ReflectionException;
use function class_exists;

final class DeclaredVarsResolver
{

	private TemplateEdgeIndex $edgeIndex;

	/** @var array<string, array{vars: array<string, string>, provenance: array<string, string>}> */
	private array $cache = [];

	/** @var array<string, array<string, array<string, string>>> */
	private array $blockCache = [];

	public function __construct(TemplateEdgeIndex $edgeIndex)
	{
		$this->edgeIndex = $edgeIndex;
	}

	/**
	 * @return array<string, string>
	 */
	public function forFile(?string $absoluteFile): array
	{
		return $this->resolve($absoluteFile)['vars'];
	}

	// Per-variable provenance labels (consumed by dumpLatteVarOrigin): where each declared var's
	// TYPE came from, mirroring the resolve() order below (later sources overwrite earlier ones,
	// same as $vars).

	/**
	 * @return array<string, string>
	 */
	public function provenanceForFile(?string $absoluteFile): array
	{
		return $this->resolve($absoluteFile)['provenance'];
	}

	// Body-depth-0 {varType}s of a block/define, keyed by block name - params stay in the
	// existing block-param surface (Declarations::getDefineParams()), not here.

	/**
	 * @return array<string, string>
	 */
	public function forBlock(string $absoluteFile, string $blockName): array
	{
		if (!isset($this->blockCache[$absoluteFile])) {
			$this->blockCache[$absoluteFile] = $this->edgeIndex->factsFor($absoluteFile)->getBlockDeclaredVars();
		}

		return $this->blockCache[$absoluteFile][$blockName] ?? [];
	}

	/**
	 * @return array{vars: array<string, string>, provenance: array<string, string>}
	 */
	private function resolve(?string $absoluteFile): array
	{
		if ($absoluteFile === null) {
			return ['vars' => [], 'provenance' => []];
		}

		if (isset($this->cache[$absoluteFile])) {
			return $this->cache[$absoluteFile];
		}

		$declarations = $this->edgeIndex->declarationsFor($absoluteFile);
		$vars = [];
		$provenance = [];

		$templateTypeClass = $declarations->getTemplateTypeClass();
		if ($templateTypeClass !== null && class_exists($templateTypeClass)) {
			try {
				foreach (PropertyTypeResolver::resolveAllPublic($templateTypeClass) as $name => $type) {
					$vars[$name] = $type;
					$provenance[$name] = 'declared:templateType';
				}
			} catch (ReflectionException $e) {
				// orisai.nette.latte.unknownType is reported once, by DeclarationInjector, for the file that
				// DECLARES the bad templateType. This resolver runs for OTHER files consuming that
				// declaration (ContextResolver/IncludeContractChecker); re-reporting here would
				// duplicate the diagnostic once per consumer, so a reflection failure silently
				// degrades to no declared vars instead.
			}
		}

		$headerVarTypeLines = $declarations->getHeaderVarTypeLines();
		foreach ($declarations->getHeaderVarTypes() as $name => $type) {
			$vars[$name] = $type;
			$provenance[$name] = 'declared:varType@' . ($headerVarTypeLines[$name] ?? '?');
		}

		$parameters = $declarations->getParameters();
		if ($parameters !== null) {
			foreach ($parameters as $parameter) {
				[$type, $name] = $parameter;
				$vars[$name] = $type ?? 'mixed';
				$provenance[$name] = 'declared:parameters@' . $parameter[3];
			}
		}

		return $this->cache[$absoluteFile] = ['vars' => $vars, 'provenance' => $provenance];
	}

}
