<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

use LogicException;
use Nette\DI\Container;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use function get_class;
use function in_array;
use function is_a;
use function lcfirst;
use function preg_match;
use function sprintf;
use function str_replace;

final class ContainerMetadataFactory
{

	public function fromContainer(string $profile, Container $container): ContainerMetadata
	{
		$reflection = new ReflectionClass($container);

		/** @var array<string, string> $aliases */
		$aliases = $this->readProperty($container, 'aliases');
		/** @var array<string, array<string, mixed>> $tags */
		$tags = $this->readProperty($container, 'tags');
		/** @var array<string, array<int, array<int, string>>> $wiring */
		$wiring = $this->readProperty($container, 'wiring');

		$containerClass = $reflection->getName();

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

			// nette/di < 3.2 compiles the "container" service as returning the compiled subclass and imported
			// services as returning void; the declared type then survives only in the wiring.
			$typeName = $this->resolveReturnTypeName($method);
			if ($typeName === null || $typeName === $containerClass) {
				$typeName = $this->resolveWiredType($wiring, $serviceName) ?? $typeName;
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

	/**
	 * @param array<string, array<int, array<int, string>>> $wiring
	 */
	private function resolveWiredType(array $wiring, string $serviceName): ?string
	{
		$candidates = [];
		foreach ($wiring as $type => $buckets) {
			foreach ($buckets as $names) {
				if (in_array($serviceName, $names, true)) {
					$candidates[] = $type;

					break;
				}
			}
		}

		foreach ($candidates as $candidate) {
			foreach ($candidates as $other) {
				if (!is_a($candidate, $other, true)) {
					continue 2;
				}
			}

			return $candidate;
		}

		return null;
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
