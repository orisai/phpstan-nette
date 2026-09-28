<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function preg_match_all;
use function substr_compare;
use function substr_count;
use const PREG_OFFSET_CAPTURE;

/**
 * @implements Rule<Expr>
 */
final class LatteInternalAccessRule implements Rule
{

	/** @var array<string, array<string, true>> */
	private array $userWrittenAccessesByFile = [];

	public function __construct(ConfigurationGuard $guard)
	{
		$guard->validate();
	}

	public function getNodeType(): string
	{
		return Expr::class;
	}

	/**
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (substr_compare($scope->getFile(), '.latte', -6) !== 0) {
			return [];
		}

		$access = $this->matchThisMemberAccess($node);
		if ($access === null) {
			return [];
		}

		[$member, $isCall] = $access;

		$key = $node->getStartLine() . ':' . $member;
		if (!isset($this->scanUserWrittenAccesses($scope->getFile())[$key])) {
			return [];
		}

		return [
			RuleErrorBuilder::message(
				'Access to Latte runtime internal $this->' . $member . ($isCall ? '()' : '') . ' from template code.',
			)
				->identifier('orisaiNette.latte.internalAccess')
				->line($node->getStartLine())
				->build(),
		];
	}

	/**
	 * @return array{string, bool}|null
	 */
	private function matchThisMemberAccess(Node $node): ?array
	{
		if ($node instanceof MethodCall) {
			$isCall = true;
		} elseif ($node instanceof PropertyFetch) {
			$isCall = false;
		} else {
			return null;
		}

		if (!$node->var instanceof Variable || $node->var->name !== 'this') {
			return null;
		}

		if (!$node->name instanceof Identifier) {
			return null;
		}

		return [$node->name->toString(), $isCall];
	}

	/**
	 * @return array<string, true>
	 */
	private function scanUserWrittenAccesses(string $file): array
	{
		if (isset($this->userWrittenAccessesByFile[$file])) {
			return $this->userWrittenAccessesByFile[$file];
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			return $this->userWrittenAccessesByFile[$file] = [];
		}

		$occurrences = [];
		// Line is derived from the $this offset, since that is the line PhpParser assigns as the node's start.
		if (preg_match_all('~\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*)~', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
			foreach ($matches[0] as $index => $wholeMatch) {
				$member = $matches[1][$index][0];
				$line = substr_count($source, "\n", 0, $wholeMatch[1]) + 1;
				$occurrences[$line . ':' . $member] = true;
			}
		}

		return $this->userWrittenAccessesByFile[$file] = $occurrences;
	}

}
