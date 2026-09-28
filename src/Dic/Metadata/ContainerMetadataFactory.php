<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

use LogicException;
use Nette\DI\Container;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use function get_class;
use function lcfirst;
use function preg_match;
use function sprintf;
use function str_replace;

final class ContainerMetadataFactory
{

	public function fromContainer(string $profile, Container $container): ContainerMetadata
	{
		$reflection = new ReflectionClass($container);

		/** @var array<string, string> $types */
		$types = $this->readProperty($container, 'types');
		/** @var array<string, string> $aliases */
		$aliases = $this->readProperty($container, 'aliases');
		/** @var array<string, array<string, mixed>> $tags */
		$tags = $this->readProperty($container, 'tags');
		/** @var array<string, array<int, array<int, string>>> $wiring */
		$wiring = $this->readProperty($container, 'wiring');

		$typesByMethodName = [];
		$serviceNames = [];

		foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$methodName = $method->getName();

			if (preg_match('#^createService(.+)#', $methodName, $m) !== 1) {
				continue;
			}

			$serviceName = lcfirst(str_replace('__', '.', $m[1]));

			if (Container::getMethodName($serviceName) !== $methodName) {
				throw new LogicException(
					sprintf('Cannot reconstruct service name from method %s::%s.', get_class($container), $methodName),
				);
			}

			// Mirrors Container::getServiceType() lookup priority: $types overrides the reflected return type
			// (e.g. the "container" service's method returns the compiled subclass, but $types pins the base type).
			$typeName = $types[$serviceName] ?? $this->resolveReturnTypeName($method);

			if ($typeName === null) {
				throw new LogicException(
					sprintf('Service %s of container %s has no resolvable type.', $serviceName, get_class($container)),
				);
			}

			$typesByMethodName[$methodName] = $typeName;
			$serviceNames[] = $serviceName;
		}

		$file = $reflection->getFileName();

		if ($file === false) {
			throw new LogicException(sprintf('Container class %s has no file.', get_class($container)));
		}

		return new ContainerMetadata(
			$profile,
			get_class($container),
			$file,
			$typesByMethodName,
			$serviceNames,
			$aliases,
			$tags,
			$wiring,
			$container->getParameters(),
		);
	}

	private function resolveReturnTypeName(ReflectionMethod $method): ?string
	{
		$returnType = $method->getReturnType();

		if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
			return $returnType->getName();
		}

		return null;
	}

	/**
	 * @return mixed
	 */
	private function readProperty(Container $container, string $property)
	{
		$reflection = new ReflectionProperty(Container::class, $property);
		$reflection->setAccessible(true);

		return $reflection->getValue($container);
	}

}
