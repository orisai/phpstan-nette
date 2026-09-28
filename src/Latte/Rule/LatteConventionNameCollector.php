<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use function count;
use function strpbrk;
use function substr_compare;

// The one channel a per-class walk structurally cannot see: a convention locator derives its
// template path from $this->file, and $this->file is written from OUTSIDE the class - a component
// factory in the presenter that owns the control. The call site is what carries both halves of the
// fact (which class, which name), and a collector is the only place with a Scope precise enough to
// answer the first: the receiver's own type. Nothing is interpreted here - the pair is emitted raw
// and TemplateTypeChecker::conventionNameRoots() decides whether the receiving class even has a
// convention candidate to parameterize. Purely file-local by design: anything derived from the
// class's facts would be a global read frozen into per-file collected data.

/**
 * @implements Collector<MethodCall, list<array{class: string, name: string}>>
 */
final class LatteConventionNameCollector implements Collector
{

	private const SET_FILE_METHOD = 'setFile';

	private bool $enabled;

	private bool $discoveryStoreEnabled;

	public function __construct(ConfigurationGuard $guard)
	{
		$this->enabled = $guard->isLatteEnabled();
		$this->discoveryStoreEnabled = $guard->isLatteDiscoveryEnabled();
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	/**
	 * @param MethodCall $node
	 * @return list<array{class: string, name: string}>|null
	 */
	public function processNode(Node $node, Scope $scope)
	{
		// Before any other work: a flag-off consumer must pay no type-resolution cost at all.
		if (!$this->enabled || !$this->discoveryStoreEnabled) {
			return null;
		}

		// Compiled LatteTpl_* classes report the .latte file itself as their source - the render
		// side this describes is always hand-written PHP.
		if (substr_compare($scope->getFile(), '.latte', -6) === 0) {
			return null;
		}

		if (!$node->name instanceof Identifier || $node->name->toString() !== self::SET_FILE_METHOD) {
			return null;
		}

		$args = $node->getArgs();
		if (count($args) !== 1 || $args[0]->name !== null || $args[0]->unpack) {
			return null;
		}

		$constantStrings = $scope->getType($args[0]->value)->getConstantStrings();
		if (count($constantStrings) !== 1) {
			return null;
		}

		// A BARE NAME, never a path: the locator's own file_exists() gate hands a path straight
		// back as the template file, which is the already-modelled literal-setFile channel. Only
		// the name form falls through to the convention derivation this collector feeds.
		$name = $constantStrings[0]->getValue();
		if ($name === '' || strpbrk($name, '/\\') !== false) {
			return null;
		}

		$sites = [];
		foreach ($scope->getType($node->var)->getObjectClassNames() as $className) {
			$sites[] = ['class' => $className, 'name' => $name];
		}

		return $sites === [] ? null : $sites;
	}

}
