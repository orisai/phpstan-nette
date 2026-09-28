<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule\Fixtures;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function is_array;
use function is_string;
use function sort;
use const SORT_STRING;

// Test-only inverse of a Collector: turns everything a named collector emitted into one error per
// value, so a collector's real output can be pinned through the real analyser (real Scope, real
// type resolution, real early-termination analysis) instead of a hand-built Scope double.

/**
 * @implements Rule<CollectedDataNode>
 */
final class CollectedDataEchoRule implements Rule
{

	public const IDENTIFIER = 'latte.testCollected';

	private string $collectorType;

	public function __construct(string $collectorType)
	{
		$this->collectorType = $collectorType;
	}

	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @param CollectedDataNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$messages = [];
		foreach ($node->get($this->collectorType) as $perFile) {
			foreach (self::flatten($perFile) as $message) {
				$messages[] = $message;
			}
		}

		sort($messages, SORT_STRING);

		$errors = [];
		foreach ($messages as $message) {
			$errors[] = RuleErrorBuilder::message($message)->identifier(self::IDENTIFIER)->build();
		}

		return $errors;
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function flatten($value): array
	{
		if (is_string($value)) {
			return [$value];
		}

		if (!is_array($value)) {
			return [];
		}

		if (is_string($value['class'] ?? null) && is_string($value['name'] ?? null)) {
			return [$value['class'] . '|' . $value['name']];
		}

		$flattened = [];
		foreach ($value as $item) {
			foreach (self::flatten($item) as $message) {
				$flattened[] = $message;
			}
		}

		return $flattened;
	}

}
