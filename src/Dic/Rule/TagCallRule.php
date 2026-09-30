<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Rule;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use function array_filter;
use function array_keys;
use function count;
use function implode;
use function sprintf;
use function strtolower;

/**
 * @implements Rule<MethodCall>
 */
final class TagCallRule implements Rule
{

	private const Methods = [
		'findbytag' => true,
	];

	private bool $enabled;

	private MultiContainerRegistry $registry;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$guard->validate();
		$this->enabled = $guard->isDicEnabled();
		$this->registry = $registry;
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled || !$this->registry->isActive() || !$node->name instanceof Identifier) {
			return [];
		}

		$method = strtolower($node->name->toString());

		if (!isset(self::Methods[$method])) {
			return [];
		}

		if ($node->isFirstClassCallable()) {
			return [];
		}

		$receiverType = $scope->getType($node->var);

		if (!(new ObjectType(Container::class))->isSuperTypeOf($receiverType)->yes()) {
			return [];
		}

		$resolution = $this->registry->resolveProfiles($receiverType);

		if ($resolution === null) {
			return [];
		}

		$receiverProfiles = $resolution->getProfiles();

		$args = $node->getArgs();

		if ($args === []) {
			return [];
		}

		$constantStrings = $scope->getType($args[0]->value)->getConstantStrings();

		if ($constantStrings === []) {
			return [
				RuleErrorBuilder::message(sprintf(
					'Dynamic tag in %s::%s() cannot be analysed. Provide a literal tag name.',
					Container::class,
					$node->name->toString(),
				))->identifier('orisai.nette.dic.dynamicTag')->build(),
			];
		}

		$errors = [];

		foreach ($constantStrings as $constantString) {
			$tag = $constantString->getValue();
			$existence = $this->registry->getTagExistence($tag, $receiverProfiles);
			$missing = array_keys(array_filter($existence, static fn (bool $exists): bool => !$exists));

			if ($missing === []) {
				continue;
			}

			$errors[] = count($missing) === count($existence) ? RuleErrorBuilder::message(sprintf(
				'Tag \'%s\' is not present in any analysed container (%s).',
				$tag,
				implode(', ', array_keys($existence)),
			))->identifier('orisai.nette.dic.tagNotFound')->build() : RuleErrorBuilder::message(sprintf(
				'Tag \'%s\' is not present in container(s): %s.',
				$tag,
				implode(', ', $missing),
			))->identifier('orisai.nette.dic.tagNotInAllContainers')->build();
		}

		return $errors;
	}

}
