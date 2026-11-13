<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Cache;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Dic\Metadata\ReceiverResolution;
use OriPhpstan\Nette\Dic\Metadata\TypeLookupResult;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Throwable;
use function get_class;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function json_encode;
use function serialize;
use function sha1;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

// One key per registry question an analysed file asked, valued by the answer: a container rebuild
// re-analyses only the files whose answer moved.
final class ContainerValueExtension implements ResultCacheValueExtension
{

	private ConfigurationGuard $guard;

	private MultiContainerRegistry $registry;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$this->guard = $guard;
		$this->registry = $registry;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public static function key(string $query, array $arguments): string
	{
		return json_encode([$query, $arguments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public function getValue(string $key): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$this->guard->validate();
		} catch (InvalidConfiguration $e) {
			return 'invalid';
		}

		if (!$this->registry->isActive()) {
			return 'inactive';
		}

		try {
			$answer = $this->answer($key);
		} catch (Throwable $e) {
			// The rules report the loader failure itself; the value only has to differ from any answer.
			return 'unavailable:' . get_class($e);
		}

		return sha1(serialize($answer));
	}

	public function keyToResultCache(string $key): string
	{
		return $key;
	}

	public function keyFromResultCache(string $storedKey): string
	{
		return $storedKey;
	}

	/**
	 * @return mixed
	 */
	private function answer(string $key)
	{
		$decoded = json_decode($key, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || !is_string($decoded[0] ?? null) || !is_array($decoded[1] ?? null)) {
			return 'malformed';
		}

		[$query, $arguments] = $decoded;

		switch ($query) {
			case 'resolveProfilesFor':
				return self::resolution(
					$this->registry->resolveProfilesFor(
						self::strings($arguments[0] ?? null),
						($arguments[1] ?? null) === true,
					),
				);
			case 'getServiceExistence':
				return $this->registry->getServiceExistence(
					self::string($arguments[0] ?? null),
					self::bool($arguments[1] ?? null),
					self::strings($arguments[2] ?? null),
				);
			case 'getResolvedServiceMethodName':
				return $this->registry->getResolvedServiceMethodName(
					self::string($arguments[0] ?? null),
					self::bool($arguments[1] ?? null),
					self::strings($arguments[2] ?? null),
				);
			case 'getServiceType':
				return self::type(
					$this->registry->getServiceType(
						self::string($arguments[0] ?? null),
						self::bool($arguments[1] ?? null),
						self::strings($arguments[2] ?? null),
					),
				);
			case 'getServiceTypeNames':
				return $this->registry->getServiceTypeNames(
					self::string($arguments[0] ?? null),
					self::bool($arguments[1] ?? null),
					self::strings($arguments[2] ?? null),
				);
			case 'getTypeLookup':
				return self::lookups(
					$this->registry->getTypeLookup(
						self::string($arguments[0] ?? null),
						self::strings($arguments[1] ?? null),
					),
				);
			case 'getTagExistence':
				return $this->registry->getTagExistence(
					self::string($arguments[0] ?? null),
					self::strings($arguments[1] ?? null),
				);
			case 'getTagValueType':
				return self::type(
					$this->registry->getTagValueType(
						self::string($arguments[0] ?? null),
						self::strings($arguments[1] ?? null),
					),
				);
			case 'getMergedParametersType':
				return self::type($this->registry->getMergedParametersType(self::strings($arguments[0] ?? null)));
			default:
				return 'unknown query';
		}
	}

	/**
	 * @return array{bool, list<string>}|null
	 */
	private static function resolution(?ReceiverResolution $resolution): ?array
	{
		return $resolution === null ? null : [$resolution->isBase(), $resolution->getProfiles()];
	}

	private static function type(?Type $type): ?string
	{
		return $type === null ? null : $type->describe(VerbosityLevel::precise());
	}

	/**
	 * @param array<string, TypeLookupResult> $lookups
	 * @return array<string, array{bool, list<string>, list<string>}>
	 */
	private static function lookups(array $lookups): array
	{
		$answer = [];
		foreach ($lookups as $profile => $lookup) {
			$answer[$profile] = [$lookup->isKnown(), $lookup->getAutowiredNames(), $lookup->getAllNames()];
		}

		return $answer;
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function strings($value): array
	{
		$strings = [];
		foreach (is_array($value) ? $value : [] as $item) {
			$strings[] = self::string($item);
		}

		return $strings;
	}

	/**
	 * @param mixed $value
	 */
	private static function string($value): string
	{
		return is_string($value) ? $value : '';
	}

	/**
	 * @param mixed $value
	 */
	private static function bool($value): bool
	{
		return is_bool($value) && $value;
	}

}
