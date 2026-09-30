<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\SliceClassName;
use OriPhpstan\Nette\Latte\Compile\TemplateClassName;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Expression;
use function array_map;
use function array_merge;
use function array_unique;
use function class_exists;
use function sort;
use const SORT_STRING;

final class DependencyEdgeEmitter
{

	/**
	 * @param array<Stmt> $stmts
	 * @param list<string> $neighborRelPaths
	 * @return array<Stmt>
	 */
	public function emit(array $stmts, array $neighborRelPaths): array
	{
		return $this->emitClassRefs($stmts, array_map(
			static fn (string $rel): string => TemplateClassName::forPath($rel),
			$neighborRelPaths,
		));
	}

	// A slice ref is emitted only for an includer whose
	// slice file already EXISTS on disk (SiteScopeStore::hasSlice() - checked by the caller), never
	// derived unconditionally from an edge, so a target never references a class.notFound slice.

	/**
	 * @param array<Stmt> $stmts
	 * @param list<string> $sliceIncluderRelPaths
	 * @return array<Stmt>
	 */
	public function emitSliceRefs(array $stmts, array $sliceIncluderRelPaths): array
	{
		return $this->emitClassRefs($stmts, array_map(
			static fn (string $rel): string => SliceClassName::forPath($rel),
			$sliceIncluderRelPaths,
		));
	}

	// Discovery refs arrive pre-derived (DiscoveryRefResolver validates existence per name kind:
	// store classes by file presence, PHP classes by class_exists) - nothing to map here.

	/**
	 * @param array<Stmt> $stmts
	 * @param list<string> $classNames
	 * @return array<Stmt>
	 */
	public function emitDiscoveryRefs(array $stmts, array $classNames): array
	{
		return $this->emitClassRefs($stmts, $classNames);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @param list<string> $classNames
	 * @return array<Stmt>
	 */
	private function emitClassRefs(array $stmts, array $classNames): array
	{
		if ($classNames === []) {
			return $stmts;
		}

		$main = MainMethodFinder::find($stmts);
		if ($main === null) {
			return $stmts;
		}

		$classNames = array_unique($classNames);
		sort($classNames, SORT_STRING);

		// An includer edit changes this file's resolved contexts; a target edit changes this
		// file's own contract-check diagnostics; a changed slice file changes the captures a target
		// overlays - all three must invalidate this file's cached result, so every referenced class
		// is referenced by class constant here purely to make PHPStan's dependency resolver tie this
		// file's result to theirs. Prepended, never appended: main()'s body always ends in a
		// terminal `return`, and appending after it would be dead code.
		$main->stmts = array_merge(array_map(
			static fn (string $className): Stmt => self::buildAnalyzedCall($className),
			$classNames,
		), $main->stmts ?? []);

		return $stmts;
	}

	// A {templateType C} declaration's own consumers (DeclaredVarsResolver/PropertyTypeResolver
	// for its properties, TemplateTypeCustoms for its filter/function methods) all reflect C
	// directly, off the class name alone - nothing in the generated AST otherwise references C, so
	// without this edge PHPStan's result cache never ties this file's cached result to C's own file
	// and an edit to C (a new property, a docblock tag change, ...) never triggers reanalysis of an
	// unedited consumer. $templateTypeClasses is every {templateType} class reachable ANYWHERE in
	// the caller's own transitive outgoing include graph (LatteRoutingParser::
	// reachableTemplateTypeClasses()), not just the caller's own declaration: PHPStan's result-cache
	// propagation is single-hop from a file whose content hash genuinely changed, so an includer
	// several hops away from C needs its OWN direct edge to C, not just a hope that an unedited
	// intermediate .latte file's own reanalysis carries the change forward. class_exists() guards
	// the same way DeclarationInjector's own header-param build does: an
	// unresolvable class already reports orisai.nette.latte.unknownType elsewhere, referencing it here would
	// only add a spurious edge to nothing.

	/**
	 * @param array<Stmt> $stmts
	 * @param list<string> $templateTypeClasses
	 * @return array<Stmt>
	 */
	public function emitTemplateTypeRef(array $stmts, array $templateTypeClasses): array
	{
		$existing = [];
		foreach ($templateTypeClasses as $templateTypeClass) {
			if (class_exists($templateTypeClass)) {
				$existing[] = $templateTypeClass;
			}
		}

		return $this->emitClassRefs($stmts, $existing);
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return array<Stmt>
	 */
	public function emitFingerprint(array $stmts, string $fingerprint): array
	{
		$class = MainMethodFinder::findClass($stmts);
		if ($class === null) {
			return $stmts;
		}

		// MUST be public: PHPStan's ExportedNodeResolver drops a private ClassConst entirely
		// (returns null), so a private fingerprint would never participate in exported-shape
		// diffing and this whole mechanism would be a no-op.
		$const = new ClassConst(
			[new Const_('LATTE_EDGE_FINGERPRINT', new String_($fingerprint))],
			Modifiers::PUBLIC,
			['startLine' => 1, 'endLine' => 1],
		);

		$class->stmts = array_merge([$const], $class->stmts);

		return $stmts;
	}

	private static function buildAnalyzedCall(string $className): Expression
	{
		$call = new StaticCall(
			new FullyQualified(Helpers::class),
			new Identifier('analyzed'),
			[new Arg(new ClassConstFetch(new FullyQualified($className), new Identifier('class')))],
			['startLine' => 1, 'endLine' => 1],
		);

		return new Expression($call, ['startLine' => 1, 'endLine' => 1]);
	}

}
