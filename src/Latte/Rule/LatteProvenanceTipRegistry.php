<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use PhpParser\Node;
use PHPStan\Rules\Registry;
use PHPStan\Rules\Rule;
use function array_map;

final class LatteProvenanceTipRegistry implements Registry
{

	private Registry $delegate;

	private TemplateEdgeIndex $templateEdgeIndex;

	/** @var array<string, array<Rule<Node>>> */
	private array $cache = [];

	public function __construct(Registry $delegate, TemplateEdgeIndex $templateEdgeIndex)
	{
		$this->delegate = $delegate;
		$this->templateEdgeIndex = $templateEdgeIndex;
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
			// @phpstan-ignore varTag.type (Rule's template is invariant; erasing to Rule<Node> mirrors LatteFixStrippingRegistry's own inline cast)
			$wrapped = array_map(
				fn (Rule $rule): Rule => new LatteProvenanceTipRule($rule, $this->templateEdgeIndex),
				$this->delegate->getRules($nodeType),
			);
			$this->cache[$nodeType] = $wrapped;
		}

		/** @var array<Rule<TNodeType>> $selectedRules */
		$selectedRules = $this->cache[$nodeType];

		return $selectedRules;
	}

}
