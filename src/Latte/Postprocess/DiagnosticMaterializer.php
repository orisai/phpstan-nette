<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess;

use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Runtime\Diag;
use PhpParser\Modifiers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use function array_merge;
use function usort;

final class DiagnosticMaterializer
{

	/**
	 * @param array<Stmt> $stmts
	 * @param array<Diagnostic> $diagnostics
	 * @return array<Stmt>
	 */
	public function materialize(array $stmts, array $diagnostics, string $className): array
	{
		if ($diagnostics === []) {
			return $stmts;
		}

		$reportStmts = $this->buildReportStatements($diagnostics);

		if ($stmts === []) {
			return [$this->buildMinimalClass($className, $reportStmts)];
		}

		$main = MainMethodFinder::find($stmts);
		if ($main === null) {
			// A non-empty AST with no main() is not a shape LatteCompiler ever produces; report the
			// diagnostics as top-level statements rather than silently dropping them (bulletproof).
			return array_merge($reportStmts, $stmts);
		}

		$main->stmts = array_merge($reportStmts, $main->stmts ?? []);

		return $stmts;
	}

	/**
	 * @param array<Diagnostic> $diagnostics
	 * @return array<Expression>
	 */
	private function buildReportStatements(array $diagnostics): array
	{
		$positions = [];
		foreach ($diagnostics as $index => $diagnostic) {
			$positions[] = [$diagnostic->getLatteLine(), $index];
		}

		usort($positions, static function (array $a, array $b): int {
			$byLine = $a[0] <=> $b[0];

			return $byLine !== 0 ? $byLine : $a[1] <=> $b[1];
		});

		$stmts = [];
		foreach ($positions as [$line, $index]) {
			$stmts[] = $this->buildReportStatement($diagnostics[$index], $line);
		}

		return $stmts;
	}

	private function buildReportStatement(Diagnostic $diagnostic, int $line): Expression
	{
		$args = [
			new Arg(new String_($diagnostic->getIdentifier())),
			new Arg(new String_($diagnostic->getMessage())),
		];

		$tip = $diagnostic->getTip();
		if ($tip !== null) {
			$args[] = new Arg(new String_($tip));
		}

		$call = new StaticCall(
			new FullyQualified(Diag::class),
			new Identifier('report'),
			$args,
			['startLine' => $line, 'endLine' => $line],
		);

		return new Expression($call, ['startLine' => $line, 'endLine' => $line]);
	}

	/**
	 * @param array<Expression> $reportStmts
	 */
	private function buildMinimalClass(string $className, array $reportStmts): Class_
	{
		// Matches the real, successfully-compiled main()'s shape (native `: array` return type,
		// terminal `return`) so this degenerate stand-in class reports only the parse-error
		// diagnostic itself, never method-shape noise on top of it.
		$main = new ClassMethod('main', [
			'flags' => Modifiers::PUBLIC,
			'returnType' => new Identifier('array'),
			'stmts' => array_merge($reportStmts, [new Return_(new Array_())]),
		], ['startLine' => 1, 'endLine' => 1]);

		$class = new Class_($className, [
			'flags' => Modifiers::FINAL,
			// extends Latte\Runtime\Template so main()'s bare `: array` return type inherits the same
			// override-of-an-already-imprecise-parent exemption a normally-compiled class gets - a
			// standalone class here would get freshly flagged by missingType.iterableValue instead.
			'extends' => new FullyQualified('Latte\Runtime\Template'),
			'stmts' => [$main],
		], ['startLine' => 1, 'endLine' => 1]);

		// A parsed class always carries this - NameResolver sets it while walking the real AST.
		// This class never goes through that visitor (it is built by hand, after a compile failure),
		// so the typed property is left uninitialized; PHPStan's own reflection reads it unconditionally.
		$class->namespacedName = new Name($className);

		return $class;
	}

}
