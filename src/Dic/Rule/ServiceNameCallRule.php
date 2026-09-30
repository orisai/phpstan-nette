<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Rule;

use Nette\DI\Container;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Type\ContainerMissingServicesType;
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
final class ServiceNameCallRule implements Rule
{

	private const Methods = [
		'getservice' => true,
		'getbyname' => true,
		'createservice' => false,
		'getservicetype' => true,
		'iscreated' => false,
		'hasservice' => false,
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

		$recursiveAliases = self::Methods[$method];

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

		$argType = $scope->getType($args[0]->value);
		$constantStrings = $argType->getConstantStrings();

		if ($constantStrings === []) {
			return [
				RuleErrorBuilder::message(sprintf(
					'Dynamic service name in %s::%s() cannot be analysed. Provide a literal service name.',
					Container::class,
					$node->name->toString(),
				))->identifier('orisai.nette.dic.dynamicServiceName')->build(),
			];
		}

		$errors = [];

		foreach ($constantStrings as $constantString) {
			$name = $constantString->getValue();

			$resolvedMethod = $this->registry->getResolvedServiceMethodName(
				$name,
				$recursiveAliases,
				$receiverProfiles,
			);

			// Suppression before the marker probe: a contradictory scope carrying both stays silent.
			// hasService reports stay exact on known-class receivers, whose hasMethod() is
			// native reflection rather than guard narrowing.
			if (
				$resolvedMethod !== null
				&& ($method !== 'hasservice' || $resolution->isBase())
				&& $receiverType->hasMethod($resolvedMethod)->yes()
			) {
				continue;
			}

			if (
				$method !== 'hasservice'
				&& $resolvedMethod !== null
				&& (new ContainerMissingServicesType([$resolvedMethod]))->isSuperTypeOf($receiverType)->yes()
			) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Service \'%s\' is excluded by the hasService() guard in this branch; the call always throws here.',
					$name,
				))->identifier('orisai.nette.dic.serviceMissingInBranch')->build();

				continue;
			}

			$existence = $this->registry->getServiceExistence($name, $recursiveAliases, $receiverProfiles);
			$profiles = implode(', ', array_keys($existence));
			$missing = array_keys(array_filter($existence, static fn (bool $exists): bool => !$exists));

			if ($method === 'hasservice') {
				if ($missing === []) {
					$errors[] = RuleErrorBuilder::message(sprintf(
						'Service \'%s\' is registered in every analysed container (%s); hasService() always returns true.',
						$name,
						$profiles,
					))->identifier('orisai.nette.dic.hasServiceAlwaysTrue')->build();
				} elseif (count($missing) === count($existence)) {
					$errors[] = RuleErrorBuilder::message(sprintf(
						'Service \'%s\' is not registered in any analysed container (%s); hasService() always returns false.',
						$name,
						$profiles,
					))->identifier('orisai.nette.dic.hasServiceAlwaysFalse')->build();
				}

				continue;
			}

			if ($missing === []) {
				continue;
			}

			$errors[] = count($missing) === count($existence)
				? RuleErrorBuilder::message(sprintf(
					'Service \'%s\' is not registered in any analysed container (%s).',
					$name,
					$profiles,
				))->identifier('orisai.nette.dic.serviceNotFound')->build()
				: RuleErrorBuilder::message(sprintf(
					'Service \'%s\' is not registered in container(s): %s.',
					$name,
					implode(', ', $missing),
				))->identifier('orisai.nette.dic.serviceNotInAllContainers')->build();
		}

		return $errors;
	}

}
