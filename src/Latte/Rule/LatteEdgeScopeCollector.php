<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Throwable;
use function array_key_exists;
use function is_array;
use function is_string;
use function sha1;
use function strlen;
use function strncmp;
use function substr_compare;

/**
 * @implements Collector<StaticCall, array{key: string, sha: string, vars: array<string, string>, args: array<string, string>}>
 */
final class LatteEdgeScopeCollector implements Collector
{

	private const MAX_TYPE_LENGTH = 1024;

	private const TEMP_VAR_PREFIX = "\u{29F}_";

	/** @var array<string, string|null> */
	private array $shaByFile = [];

	private bool $enabled;

	public function __construct(ConfigurationGuard $guard)
	{
		$this->enabled = $guard->isLatteNarrowingEnabled();
	}

	public function getNodeType(): string
	{
		return StaticCall::class;
	}

	/**
	 * @param StaticCall $node
	 * @return array{key: string, sha: string, vars: array<string, string>, args: array<string, string>}|null
	 */
	public function processNode(Node $node, Scope $scope)
	{
		if (!$this->enabled) {
			return null;
		}

		try {
			return $this->capture($node, $scope);
		} catch (Throwable $e) {
			// A capture failure must never take down the whole analysis run - degrade to no
			// capture for this one anchor, same tight-catch discipline as the per-field
			// omissions below.
			return null;
		}
	}

	/**
	 * @return array{key: string, sha: string, vars: array<string, string>, args: array<string, string>}|null
	 */
	private function capture(StaticCall $node, Scope $scope): ?array
	{
		if (substr_compare($scope->getFile(), '.latte', -6) !== 0) {
			return null;
		}

		if (!$this->isEdgeScopeCall($node, $scope)) {
			return null;
		}

		$key = $this->literalStringArg($node, 0);
		$sha = $this->shaFor($scope->getFile());
		if ($key === null || $sha === null) {
			return null;
		}

		return [
			'key' => $key,
			'sha' => $sha,
			'vars' => $this->captureVars($node, $scope),
			'args' => $this->captureArgs($node, $scope),
		];
	}

	private function isEdgeScopeCall(StaticCall $node, Scope $scope): bool
	{
		if (!$node->class instanceof Name || !$node->name instanceof Identifier) {
			return false;
		}

		if ($node->name->toString() !== 'edgeScope') {
			return false;
		}

		return $scope->resolveName($node->class) === Helpers::class;
	}

	private function literalStringArg(StaticCall $node, int $index): ?string
	{
		$arg = $node->args[$index] ?? null;

		return $arg instanceof Arg && $arg->value instanceof String_ ? $arg->value->value : null;
	}

	private function shaFor(string $file): ?string
	{
		if (array_key_exists($file, $this->shaByFile)) {
			return $this->shaByFile[$file];
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			return $this->shaByFile[$file] = null;
		}

		return $this->shaByFile[$file] = sha1($source);
	}

	/**
	 * @return array<string, string>
	 */
	private function captureVars(StaticCall $node, Scope $scope): array
	{
		$manifest = $node->getAttribute('latte.edgeManifest');
		if (!is_array($manifest)) {
			return [];
		}

		$vars = [];
		foreach ($manifest as $name) {
			if (!is_string($name) || $this->isSkippedName($name) || !$scope->hasVariableType($name)->yes()) {
				continue;
			}

			$described = $this->describeCapped($scope->getVariableType($name));
			if ($described === null) {
				continue;
			}

			$vars[$name] = $described;
		}

		return $vars;
	}

	/**
	 * @return array<string, string>
	 */
	private function captureArgs(StaticCall $node, Scope $scope): array
	{
		$arg = $node->args[1] ?? null;
		$arrayArg = $arg instanceof Arg ? $arg->value : null;
		if (!$arrayArg instanceof Array_) {
			return [];
		}

		$captured = [];
		foreach ($arrayArg->items as $item) {
			if (!$item->key instanceof String_) {
				continue;
			}

			$name = $item->key->value;
			if ($this->isSkippedName($name)) {
				continue;
			}

			$described = $this->describeCapped($scope->getType($item->value));
			if ($described === null) {
				continue;
			}

			$captured[$name] = $described;
		}

		return $captured;
	}

	private function describeCapped(Type $type): ?string
	{
		$described = $type->describe(VerbosityLevel::precise());

		return strlen($described) <= self::MAX_TYPE_LENGTH ? $described : null;
	}

	private function isSkippedName(string $name): bool
	{
		return $name === 'this' || strncmp($name, self::TEMP_VAR_PREFIX, strlen(self::TEMP_VAR_PREFIX)) === 0;
	}

}
