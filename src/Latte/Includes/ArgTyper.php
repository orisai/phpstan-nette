<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterAccessor;

// Reads an include-family site's argument list through the installed adapter, so no consumer
// tokenizes Latte syntax itself.
final class ArgTyper
{

	private LatteVersionAdapterAccessor $adapterAccessor;

	public function __construct(LatteVersionAdapterAccessor $adapterAccessor)
	{
		$this->adapterAccessor = $adapterAccessor;
	}

	/**
	 * @return array{vars: array<string, string>, open: bool}
	 */
	public function typeArgs(IncludeTarget $site, TemplateContext $context): array
	{
		$vars = [];
		$open = false;

		foreach ($this->parse($site) as $arg) {
			if ($arg->isSpread()) {
				$open = true;
			} elseif ($arg->getName() !== null) {
				$vars[$arg->getName()] = $this->classifyExprType($arg, $context);
			}
		}

		return ['vars' => $vars, 'open' => $open];
	}

	// Block params bind POSITIONALLY at the call site (BlockDispatchEliminator's own direct-call
	// rewrite matches by position), but typeArgs() above only recognizes `name: expr`/`name =>
	// expr` pairs - a bare positional argument is invisible to it. Consumers checking a block's
	// own declared params against what an edge provides must know when this gap could hide a
	// real binding, rather than silently trusting an unrelated same-named ambient value.
	public function hasPositionalArgs(IncludeTarget $site): bool
	{
		foreach ($this->parse($site) as $arg) {
			if (!$arg->isSpread() && $arg->getName() === null) {
				return true;
			}
		}

		return false;
	}

	// Every non-spread argument's expression source in call order, a `name:`/`name =>` prefix
	// stripped: block params thread by declaration order, so a name a caller writes never picks
	// the target param.

	/**
	 * @return list<string>
	 */
	public function argSources(IncludeTarget $site): array
	{
		$sources = [];
		foreach ($this->parse($site) as $arg) {
			if (!$arg->isSpread()) {
				$sources[] = $arg->getSource();
			}
		}

		return $sources;
	}

	/**
	 * @return list<array{string, string}>
	 */
	public function namedArgSources(IncludeTarget $site): array
	{
		$pairs = [];
		foreach ($this->parse($site) as $arg) {
			if (!$arg->isSpread() && $arg->getName() !== null) {
				$pairs[] = [$arg->getName(), $arg->getSource()];
			}
		}

		return $pairs;
	}

	/**
	 * @return list<TagArgument>
	 */
	private function parse(IncludeTarget $site): array
	{
		return $this->adapterAccessor->get()->parseTagArguments($site->getArgsSource());
	}

	private function classifyExprType(TagArgument $arg, TemplateContext $context): string
	{
		if ($arg->getVariable() !== null) {
			return $context->getVars()[$arg->getVariable()] ?? 'mixed';
		}

		return $arg->getLiteralType() ?? 'mixed';
	}

}
