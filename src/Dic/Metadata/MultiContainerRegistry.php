<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Metadata;

use LogicException;
use Nette\DI\Container;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function ksort;
use function sprintf;

final class MultiContainerRegistry
{

	private ?string $loaderFile;

	/** @var array<string, ContainerMetadata>|null */
	private ?array $metadata = null;

	/** @var array<string, Type> */
	private array $parametersTypeBySubset = [];

	private ParameterTypeWidener $widener;

	public function __construct(?string $loaderFile)
	{
		$this->loaderFile = $loaderFile;
		$this->widener = new ParameterTypeWidener();
	}

	public function isActive(): bool
	{
		return $this->loaderFile !== null;
	}

	/**
	 * @return list<string>
	 */
	public function getProfiles(): array
	{
		return array_keys($this->load());
	}

	/**
	 * @return list<string>
	 */
	public function getContainerFilePaths(): array
	{
		return array_values(array_unique($this->getContainerFilesByProfile()));
	}

	/**
	 * The result cache's salt input: the profile NAME is part of the analysed state (every rule
	 * message prints it and it selects the subset every type extension resolves against), while two
	 * profiles may share one container file, so a digest over unique file paths alone cannot see a
	 * profile being renamed, added onto an existing file or reordered.
	 *
	 * @return array<string, string>
	 */
	public function getContainerFilesByProfile(): array
	{
		$paths = [];

		foreach ($this->load() as $profile => $metadata) {
			$paths[$profile] = $metadata->getFilePath();
		}

		return $paths;
	}

	public function resolveProfiles(Type $receiverType): ?ReceiverResolution
	{
		$classNames = $receiverType->getObjectClassNames();

		if ($classNames === []) {
			return null;
		}

		$profilesByClassName = [];

		foreach ($this->load() as $profile => $metadata) {
			$profilesByClassName[$metadata->getClassName()][] = $profile;
		}

		$hasBase = false;
		$known = [];

		foreach ($classNames as $className) {
			if ($className === Container::class) {
				$hasBase = true;
			} elseif (isset($profilesByClassName[$className])) {
				foreach ($profilesByClassName[$className] as $profile) {
					$known[$profile] = true;
				}
			} else {
				return null;
			}
		}

		if ($hasBase) {
			// Known + base mix: a union member widens to all profiles, a base-classed
			// accessory (marker) intersected with a known class does not.
			if ($known === [] || $receiverType->isSuperTypeOf(new ObjectType(Container::class))->yes()) {
				return new ReceiverResolution(true, $this->getProfiles());
			}
		}

		return new ReceiverResolution(false, array_keys($known));
	}

	/**
	 * @param list<string> $profiles
	 * @return array<string, bool>
	 */
	public function getServiceExistence(string $name, bool $recursiveAliases, array $profiles): array
	{
		$existence = [];

		foreach ($this->loadSubset($profiles) as $profile => $metadata) {
			$existence[$profile] = $recursiveAliases
				? $metadata->hasServiceRecursive($name)
				: $metadata->hasService($name);
		}

		return $existence;
	}

	/**
	 * @param list<string> $profiles
	 */
	public function getResolvedServiceMethodName(string $name, bool $recursiveAliases, array $profiles): ?string
	{
		$methodNames = [];

		foreach ($this->loadSubset($profiles) as $metadata) {
			$resolved = $recursiveAliases
				? $metadata->resolveNameRecursive($name)
				: $metadata->resolveName($name);

			if ($resolved === null || $resolved === '') {
				continue;
			}

			$methodNames[Container::getMethodName($resolved)] = true;
		}

		if (count($methodNames) !== 1) {
			return null;
		}

		return array_key_first($methodNames);
	}

	/**
	 * @param list<string> $profiles
	 */
	public function getServiceType(string $name, bool $recursiveAliases, array $profiles): ?Type
	{
		$types = [];

		foreach ($this->loadSubset($profiles) as $metadata) {
			$typeName = $recursiveAliases
				? $metadata->getServiceTypeNameRecursive($name)
				: $metadata->getServiceTypeName($name);

			if ($typeName !== null) {
				$types[] = new ObjectType($typeName);
			} elseif ($recursiveAliases ? $metadata->hasServiceRecursive($name) : $metadata->hasService($name)) {
				$types[] = new ObjectWithoutClassType();
			}
		}

		if ($types === []) {
			return null;
		}

		return TypeCombinator::union(...$types);
	}

	/**
	 * @param list<string> $profiles
	 * @return array<string, string>|null
	 */
	public function getServiceTypeNames(string $name, bool $recursiveAliases, array $profiles): ?array
	{
		$names = [];

		foreach ($this->loadSubset($profiles) as $profile => $metadata) {
			$typeName = $recursiveAliases
				? $metadata->getServiceTypeNameRecursive($name)
				: $metadata->getServiceTypeName($name);

			if ($typeName !== null) {
				$names[$profile] = $typeName;
			} elseif ($recursiveAliases ? $metadata->hasServiceRecursive($name) : $metadata->hasService($name)) {
				return null;
			}
		}

		return $names;
	}

	/**
	 * @param list<string> $profiles
	 * @return array<string, TypeLookupResult>
	 */
	public function getTypeLookup(string $className, array $profiles): array
	{
		$results = [];

		foreach ($this->loadSubset($profiles) as $profile => $metadata) {
			$buckets = $metadata->getWiring()[$className] ?? null;

			if ($buckets === null) {
				$results[$profile] = new TypeLookupResult(false, [], []);

				continue;
			}

			ksort($buckets);

			$allNames = [];

			foreach ($buckets as $bucketNames) {
				foreach (array_values($bucketNames) as $bucketName) {
					$allNames[] = $bucketName;
				}
			}

			$results[$profile] = new TypeLookupResult(true, array_values($buckets[0] ?? []), $allNames);
		}

		return $results;
	}

	/**
	 * @param list<string> $profiles
	 * @return array<string, bool>
	 */
	public function getTagExistence(string $tag, array $profiles): array
	{
		$existence = [];

		foreach ($this->loadSubset($profiles) as $profile => $metadata) {
			$existence[$profile] = array_key_exists($tag, $metadata->getTags());
		}

		return $existence;
	}

	/**
	 * @param list<string> $profiles
	 */
	public function getTagValueType(string $tag, array $profiles): ?Type
	{
		$widened = [];

		foreach ($this->loadSubset($profiles) as $metadata) {
			$tags = $metadata->getTags();

			if (!array_key_exists($tag, $tags)) {
				continue;
			}

			foreach ($tags[$tag] as $value) {
				$widened[] = $this->widener->widen($value);
			}
		}

		if ($widened === []) {
			return null;
		}

		return TypeCombinator::union(...$widened);
	}

	/**
	 * @param list<string> $profiles
	 */
	public function getMergedParametersType(array $profiles): Type
	{
		$subsetKey = implode("\x00", $profiles);

		if (isset($this->parametersTypeBySubset[$subsetKey])) {
			return $this->parametersTypeBySubset[$subsetKey];
		}

		$parameterSets = [];

		foreach ($this->loadSubset($profiles) as $metadata) {
			$parameterSets[] = $metadata->getParameters();
		}

		return $this->parametersTypeBySubset[$subsetKey] = $this->mergeValueSets($parameterSets);
	}

	/**
	 * @param list<string> $profiles
	 * @return array<string, ContainerMetadata>
	 */
	private function loadSubset(array $profiles): array
	{
		$all = $this->load();
		$subset = [];

		foreach ($profiles as $profile) {
			if (!array_key_exists($profile, $all)) {
				throw new LogicException(sprintf('Unknown DIC profile "%s".', $profile));
			}

			$subset[$profile] = $all[$profile];
		}

		return $subset;
	}

	/**
	 * @return array<string, ContainerMetadata>
	 */
	private function load(): array
	{
		if ($this->metadata !== null) {
			return $this->metadata;
		}

		if ($this->loaderFile === null || !is_file($this->loaderFile)) {
			throw new LogicException(
				sprintf('DIC container loader file "%s" does not exist.', (string) $this->loaderFile),
			);
		}

		$containers = require $this->loaderFile;

		if ($containers instanceof Container) {
			$containers = ['default' => $containers];
		}

		if (!is_array($containers) || $containers === []) {
			throw $this->invalidLoaderResult();
		}

		$factory = new ContainerMetadataFactory();
		$metadata = [];

		foreach ($containers as $profile => $container) {
			if (!is_string($profile) || !$container instanceof Container) {
				throw $this->invalidLoaderResult();
			}

			$metadata[$profile] = $factory->fromContainer($profile, $container);
		}

		unset($containers);

		return $this->metadata = $metadata;
	}

	private function invalidLoaderResult(): LogicException
	{
		return new LogicException(sprintf(
			'DIC container loader "%s" must return a Nette\DI\Container or a non-empty array of Nette\DI\Container instances keyed by profile name.',
			$this->loaderFile,
		));
	}

	/**
	 * @param list<mixed> $values
	 */
	private function mergeValueSets(array $values): Type
	{
		if (count($values) > 1 && $this->allArrays($values)) {
			return $this->mergeArrayValueSets($values);
		}

		$widened = [];

		foreach ($values as $value) {
			$widened[] = $this->widener->widen($value);
		}

		return TypeCombinator::union(...$widened);
	}

	/**
	 * @param list<mixed> $values
	 */
	private function allArrays(array $values): bool
	{
		foreach ($values as $value) {
			if (!is_array($value)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<array<mixed>> $values
	 */
	private function mergeArrayValueSets(array $values): Type
	{
		$builder = ConstantArrayTypeBuilder::createEmpty();
		$keys = [];

		foreach ($values as $array) {
			foreach (array_keys($array) as $key) {
				$keys[$key] = true;
			}
		}

		if ($keys === []) {
			return new ArrayType(new MixedType(), new MixedType());
		}

		foreach (array_keys($keys) as $key) {
			$subValues = [];

			foreach ($values as $array) {
				if (array_key_exists($key, $array)) {
					$subValues[] = $array[$key];
				}
			}

			$builder->setOffsetValueType(
				is_int($key) ? new ConstantIntegerType($key) : new ConstantStringType($key),
				$this->mergeValueSets($subValues),
				count($subValues) < count($values),
			);
		}

		return $builder->getArray();
	}

}
