<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Declarations\PropertyTypeResolver;
use PHPStan\PhpDoc\TypeStringResolver;
use ReflectionException;
use Throwable;
use function array_key_exists;
use function array_merge;
use function class_exists;
use function sprintf;

// Intra-target override matrix: compares each target's own NATIVE declaration (definition param
// type for a block, {templateType}-derived property type for a file) against its own PHPDoc-style
// OVERRIDE ({varType}), depth-0 both sides. This never looks at include edges or call sites -
// IncludeContractChecker's "does the edge provide what's declared" question is orthogonal to this
// checker's "does the declaration override its own native type sensibly" one.
final class DeclarationConsistencyChecker
{

	private TemplateEdgeIndex $index;

	private TypeStringResolver $typeStringResolver;

	private bool $allowNarrowingOverride;

	public function __construct(
		TemplateEdgeIndex $index,
		TypeStringResolver $typeStringResolver,
		bool $allowNarrowingOverride
	)
	{
		$this->index = $index;
		$this->typeStringResolver = $typeStringResolver;
		$this->allowNarrowingOverride = $allowNarrowingOverride;
	}

	// $declarations is the caller's own already-scanned Declarations (LatteRoutingParser::
	// parseLatteFile() has already read $absoluteFile and scanned it by the time it calls this) -
	// this checker never touches the filesystem itself, matching IncludeContractChecker's own
	// fully-precomputed-input shape. $absoluteFile is still needed to key into TemplateEdgeIndex's
	// own (separately cached) per-file facts for the block side.

	/**
	 * @return list<Diagnostic>
	 */
	public function check(Declarations $declarations, string $absoluteFile, string $projectRelativePath): array
	{
		return array_merge(
			$this->checkFile($declarations, $projectRelativePath),
			$this->checkBlocks($declarations, $absoluteFile, $projectRelativePath),
		);
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkFile(Declarations $declarations, string $projectRelativePath): array
	{
		$templateTypeClass = $declarations->getTemplateTypeClass();
		if ($templateTypeClass === null || !class_exists($templateTypeClass)) {
			return [];
		}

		try {
			$nativeTypes = PropertyTypeResolver::resolveAllPublic($templateTypeClass);
		} catch (ReflectionException $e) {
			// orisai.nette.latte.unknownType already covers a genuinely bad {templateType} class, reported once
			// by DeclarationInjector for the declaring file - degrading silently here avoids a
			// duplicate report from this unrelated checker.
			return [];
		}

		$overrideLines = $declarations->getHeaderVarTypeLines();

		$diagnostics = [];
		foreach ($declarations->getHeaderVarTypes() as $name => $overrideType) {
			if (!array_key_exists($name, $overrideLines)) {
				continue;
			}

			// A property PropertyTypeResolver could only resolve to 'mixed' (no native type hint,
			// no @var docblock) has no real native type to override - same non-conflict rule as a
			// block param without a type hint below, one level up (a genuinely-`mixed`-typed
			// property is indistinguishable from an untyped one at this resolver's precision).
			if (!array_key_exists($name, $nativeTypes) || $nativeTypes[$name] === 'mixed') {
				continue;
			}

			$diagnostics = array_merge(
				$diagnostics,
				$this->compare($name, $nativeTypes[$name], $overrideType, $overrideLines[$name], $projectRelativePath),
			);
		}

		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function checkBlocks(Declarations $declarations, string $absoluteFile, string $projectRelativePath): array
	{
		$facts = $this->index->factsFor($absoluteFile);
		$blockDeclaredVars = $facts->getBlockDeclaredVars();
		$blockDeclaredVarLines = $facts->getBlockDeclaredVarLines();
		$defineParams = $declarations->getDefineParams();

		$diagnostics = [];
		foreach ($blockDeclaredVars as $blockName => $overrides) {
			// {block} never carries params (DeclarationScanner::scanDefine() always yields an
			// empty list for it) - a pure {block} body varType has no native counterpart to
			// compare against, so it's a plain declaration, never an override.
			$nativeTypes = [];
			foreach ($defineParams[$blockName] ?? [] as [$type, $paramName]) {
				$nativeTypes[$paramName] = $type;
			}

			$lines = $blockDeclaredVarLines[$blockName] ?? [];
			$targetLabel = $projectRelativePath . '#' . $blockName;

			foreach ($overrides as $varName => $overrideType) {
				// Own param present but WITHOUT a type hint (bare `$x`) is §2's contract
				// declaration channel (the varType supplies the block's only type for that
				// param), not an override of anything - never a conflict.
				if (!array_key_exists($varName, $nativeTypes) || $nativeTypes[$varName] === null) {
					continue;
				}

				if (!array_key_exists($varName, $lines)) {
					continue;
				}

				$diagnostics = array_merge(
					$diagnostics,
					$this->compare($varName, $nativeTypes[$varName], $overrideType, $lines[$varName], $targetLabel),
				);
			}
		}

		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function compare(
		string $name,
		string $nativeType,
		string $overrideType,
		int $line,
		string $targetLabel
	): array
	{
		try {
			$native = $this->typeStringResolver->resolve($nativeType);
		} catch (Throwable $e) {
			return [];
		}

		try {
			$override = $this->typeStringResolver->resolve($overrideType);
		} catch (Throwable $e) {
			return [];
		}

		if ($native->equals($override)) {
			return [
				new Diagnostic(
					'orisai.nette.latte.duplicateDeclaration',
					sprintf(
						"Variable \$%s in '%s' is declared as %s both natively and via {varType} - the {varType} is redundant.",
						$name,
						$targetLabel,
						$overrideType,
					),
					$line,
				),
			];
		}

		if ($native->isSuperTypeOf($override)->yes()) {
			if ($this->allowNarrowingOverride) {
				return [];
			}

			return [
				new Diagnostic(
					'orisai.nette.latte.narrowingOverride',
					sprintf(
						"Variable \$%s in '%s' is narrowed via {varType} from its native type %s to %s.",
						$name,
						$targetLabel,
						$nativeType,
						$overrideType,
					),
					$line,
				),
			];
		}

		return [
			new Diagnostic(
				'orisai.nette.latte.impossibleOverride',
				sprintf(
					"Variable \$%s in '%s' is declared via {varType} as %s, which is incompatible with its native type %s.",
					$name,
					$targetLabel,
					$overrideType,
					$nativeType,
				),
				$line,
			),
		];
	}

}
