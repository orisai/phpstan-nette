<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Rule;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use function array_keys;
use function array_merge;
use function count;
use function implode;
use function sprintf;
use function strtolower;

/**
 * @implements Rule<MethodCall>
 */
final class TypeLookupCallRule implements Rule
{

	private const Methods = [
		'getbytype' => true,
		'findbytype' => true,
		'createinstance' => true,
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
					'Dynamic type in %s::%s() cannot be analysed. Provide a literal ::class type.',
					Container::class,
					$node->name->toString(),
				))->identifier('orisaiNette.dic.dynamicType')->build(),
			];
		}

		if ($method === 'createinstance') {
			return [];
		}

		$throwSuppressed = $method === 'getbytype'
			&& count($args) >= 2
			&& !$scope->getType($args[1]->value)->isTrue()->yes();

		$errors = [];

		foreach ($constantStrings as $constantString) {
			$className = $constantString->getValue();
			$lookup = $this->registry->getTypeLookup($className, $receiverProfiles);
			$profiles = array_keys($lookup);

			$unknown = [];
			$notAutowired = [];
			$ambiguousParts = [];

			foreach ($lookup as $profile => $result) {
				if (!$result->isKnown()) {
					$unknown[] = $profile;

					continue;
				}

				$autowiredNames = $result->getAutowiredNames();

				if (count($autowiredNames) > 1) {
					$ambiguousParts[] = sprintf('%s (%s)', $profile, implode(', ', $autowiredNames));
				} elseif ($autowiredNames === []) {
					$notAutowired[] = $profile;
				}
			}

			if ($method === 'findbytype') {
				$errors = array_merge($errors, $this->checkFindByType($className, $profiles, $unknown));

				continue;
			}

			if ($ambiguousParts !== []) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Type %s is ambiguous in container(s): %s; getByType() throws.',
					$className,
					implode(', ', $ambiguousParts),
				))->identifier('orisaiNette.dic.typeAmbiguous')->build();
			}

			if ($throwSuppressed) {
				continue;
			}

			$errors = array_merge($errors, $this->checkGetByType($className, $profiles, $unknown, $notAutowired));
		}

		return $errors;
	}

	/**
	 * @param list<string> $profiles
	 * @param list<string> $unknown
	 * @return list<IdentifierRuleError>
	 */
	private function checkFindByType(string $className, array $profiles, array $unknown): array
	{
		if ($unknown === []) {
			return [];
		}

		if (count($unknown) === count($profiles)) {
			return [
				RuleErrorBuilder::message(sprintf(
					'Type %s is never resolvable; findByType() always returns an empty array.',
					$className,
				))->identifier('orisaiNette.dic.typeNotFound')->build(),
			];
		}

		return [
			RuleErrorBuilder::message(sprintf(
				'Type %s is not registered in container(s): %s.',
				$className,
				implode(', ', $unknown),
			))->identifier('orisaiNette.dic.typeNotInAllContainers')->build(),
		];
	}

	/**
	 * @param list<string> $profiles
	 * @param list<string> $unknown
	 * @param list<string> $notAutowired
	 * @return list<IdentifierRuleError>
	 */
	private function checkGetByType(string $className, array $profiles, array $unknown, array $notAutowired): array
	{
		$failing = array_merge($unknown, $notAutowired);

		if ($failing === []) {
			return [];
		}

		if (count($unknown) === count($profiles)) {
			return [
				RuleErrorBuilder::message(sprintf(
					'Type %s is not registered in any analysed container (%s).',
					$className,
					implode(', ', $profiles),
				))->identifier('orisaiNette.dic.typeNotFound')->build(),
			];
		}

		if (count($notAutowired) === count($profiles)) {
			return [
				RuleErrorBuilder::message(sprintf(
					'Type %s is registered but not autowired in container(s): %s; getByType() throws.',
					$className,
					implode(', ', $notAutowired),
				))->identifier('orisaiNette.dic.typeNotAutowired')->build(),
			];
		}

		return [
			RuleErrorBuilder::message(sprintf(
				'Type %s is not autowirable in container(s): %s; getByType() throws there.',
				$className,
				implode(', ', $failing),
			))->identifier('orisaiNette.dic.typeNotInAllContainers')->build(),
		];
	}

}
