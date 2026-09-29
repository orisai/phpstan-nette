<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

use Nette\DI\Container;
use function array_key_exists;
use function array_values;

final class ContainerMetadata
{

	private string $profile;

	private string $className;

	private string $filePath;

	/** @var array<string, string|null> */
	private array $typesByMethodName;

	/** @var list<string> */
	private array $serviceNames;

	/** @var array<string, string> */
	private array $aliases;

	/** @var array<string, array<string, mixed>> */
	private array $tags;

	/** @var array<string, array<int, array<int, string>>> */
	private array $wiring;

	/** @var array<string, mixed> */
	private array $parameters;

	/**
	 * @param array<string, string|null> $typesByMethodName
	 * @param list<string> $serviceNames
	 * @param array<string, string> $aliases
	 * @param array<string, array<string, mixed>> $tags
	 * @param array<string, array<int, array<int, string>>> $wiring
	 * @param array<string, mixed> $parameters
	 */
	public function __construct(
		string $profile,
		string $className,
		string $filePath,
		array $typesByMethodName,
		array $serviceNames,
		array $aliases,
		array $tags,
		array $wiring,
		array $parameters
	)
	{
		$this->profile = $profile;
		$this->className = $className;
		$this->filePath = $filePath;
		$this->typesByMethodName = $typesByMethodName;
		$this->serviceNames = $serviceNames;
		$this->aliases = $aliases;
		$this->tags = $tags;
		$this->wiring = $wiring;
		$this->parameters = $parameters;
	}

	public function getProfile(): string
	{
		return $this->profile;
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	public function getFilePath(): string
	{
		return $this->filePath;
	}

	public function hasService(string $name): bool
	{
		$resolved = $this->aliases[$name] ?? $name;

		return array_key_exists(Container::getMethodName($resolved), $this->typesByMethodName);
	}

	public function resolveName(string $name): string
	{
		return $this->aliases[$name] ?? $name;
	}

	public function resolveNameRecursive(string $name): ?string
	{
		return $this->resolveAliasChain($name);
	}

	public function getServiceTypeName(string $name): ?string
	{
		$resolved = $this->aliases[$name] ?? $name;

		return $this->typesByMethodName[Container::getMethodName($resolved)] ?? null;
	}

	public function hasServiceRecursive(string $name): bool
	{
		$resolved = $this->resolveAliasChain($name);

		return $resolved !== null && array_key_exists(Container::getMethodName($resolved), $this->typesByMethodName);
	}

	public function getServiceTypeNameRecursive(string $name): ?string
	{
		$resolved = $this->resolveAliasChain($name);

		if ($resolved === null) {
			return null;
		}

		return $this->typesByMethodName[Container::getMethodName($resolved)] ?? null;
	}

	private function resolveAliasChain(string $name): ?string
	{
		$visited = [];

		while (isset($this->aliases[$name])) {
			if (isset($visited[$name])) {
				return null;
			}

			$visited[$name] = true;
			$name = $this->aliases[$name];
		}

		return $name;
	}

	/**
	 * @return list<string>
	 */
	public function getServiceNames(): array
	{
		return $this->serviceNames;
	}

	/**
	 * @return array<string, string>
	 */
	public function getAliases(): array
	{
		return $this->aliases;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function getTags(): array
	{
		return $this->tags;
	}

	/**
	 * @return array<string, array<int, array<int, string>>>
	 */
	public function getWiring(): array
	{
		return $this->wiring;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}

	/**
	 * @return list<string>
	 */
	public function getWiringBucket(string $type, int $bucket): array
	{
		return array_values($this->wiring[$type][$bucket] ?? []);
	}

}
