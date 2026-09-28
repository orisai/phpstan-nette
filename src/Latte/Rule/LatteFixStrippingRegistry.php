<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use PhpParser\Node;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;
use function array_map;

final class LatteFixStrippingRegistry implements Registry
{

	private Registry $delegate;

	/** @var array<string, array<Rule<Node>>> */
	private array $cache = [];

	public function __construct(Registry $delegate)
	{
		$this->delegate = $delegate;
	}

	/**
	 * @template TNodeType of Node
	 * @param class-string<TNodeType> $nodeType
	 * @return array<Rule<TNodeType>>
	 */
	public function getRules(string $nodeType): array
	{
		if (!isset($this->cache[$nodeType])) {
			/** @var array<Rule<Node>> $wrapped */
			// @phpstan-ignore varTag.type (Rule's template is invariant; erasing to Rule<Node> mirrors the phar LazyRegistry's own inline cast)
			$wrapped = array_map(
				static fn (Rule $rule): Rule => new LatteFixStrippingRule($rule),
				$this->delegate->getRules($nodeType),
			);
			$this->cache[$nodeType] = $wrapped;
		}

		/** @var array<Rule<TNodeType>> $selectedRules */
		$selectedRules = $this->cache[$nodeType];

		return $selectedRules;
	}

}
