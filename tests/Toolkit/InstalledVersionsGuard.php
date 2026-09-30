<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Composer\Semver\Semver;
use LogicException;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use PHPUnit\Framework\Assert;
use function explode;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;

final class InstalledVersionsGuard
{

	private const GROUPS = [
		'latte2' => ['latte/latte', '^2.0', 'Latte 2'],
		'latte3' => ['latte/latte', '^3.0', 'Latte 3'],
		'latte30' => ['latte/latte', '~3.0.0', 'Latte 3.0'],
		'latte31' => ['latte/latte', '~3.1.0', 'Latte 3.1'],
		'nette32' => ['nette/application', '~3.2.0', 'nette/application 3.2'],
		'nette33' => ['nette/application', '~3.3.0', 'nette/application 3.3'],
	];

	/** @var array<string, string>|null */
	private static ?array $versions = null;

	/**
	 * @param array<string, string>|null $versions
	 */
	public static function overrideVersions(?array $versions): void
	{
		self::$versions = $versions;
	}

	public static function version(string $package): ?string
	{
		if (self::$versions !== null) {
			return self::$versions[$package] ?? null;
		}

		return ProjectInstalledVersions::get()->getVersion($package);
	}

	public static function satisfies(string $package, string $constraint): bool
	{
		$version = self::version($package);

		return $version !== null && Semver::satisfies($version, $constraint);
	}

	public static function latteMajor(): int
	{
		return (int) self::latteVersionParts()[0];
	}

	public static function latteLine(): string
	{
		$parts = self::latteVersionParts();

		return $parts[0] === '2' ? '2' : $parts[0] . '.' . ($parts[1] ?? '0');
	}

	public static function requireLatteMajor(int $major): void
	{
		self::requireVersion('latte/latte', '^' . $major . '.0', 'Latte ' . $major);
	}

	public static function requirePhpstan(string $constraint): void
	{
		self::requireVersion('phpstan/phpstan', $constraint, 'phpstan/phpstan ' . $constraint);
	}

	public static function requireNetteLine(string $package, string $constraint): void
	{
		self::requireVersion($package, $constraint, $package . ' ' . $constraint);
	}

	/**
	 * @param array<mixed> $groups
	 */
	public static function skipReason(array $groups): ?string
	{
		foreach ($groups as $group) {
			if (
				is_string($group)
				&& !isset(self::GROUPS[$group])
				&& preg_match('~^(latte|nette)\d~', $group) === 1
			) {
				throw new LogicException(sprintf('Unknown version group "%s".', $group));
			}
		}

		foreach (self::GROUPS as $group => [$package, $constraint, $label]) {
			if (in_array($group, $groups, true) && !self::satisfies($package, $constraint)) {
				return self::reason($package, $label);
			}
		}

		return null;
	}

	private static function requireVersion(string $package, string $constraint, string $label): void
	{
		if (!self::satisfies($package, $constraint)) {
			Assert::markTestSkipped(self::reason($package, $label));
		}
	}

	private static function reason(string $package, string $label): string
	{
		return sprintf('requires %s (installed %s %s)', $label, $package, self::version($package) ?? 'none');
	}

	/**
	 * @return non-empty-list<string>
	 */
	private static function latteVersionParts(): array
	{
		$version = self::version('latte/latte');
		if ($version === null) {
			throw new LogicException('latte/latte is not installed.');
		}

		return explode('.', $version);
	}

}
