<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures;

use LogicException;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ExtensionsCollection;
use PHPStan\Reflection\ReflectionProvider;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;

// TemplateTypeChecker only ever calls getService() for its record source, pairing judge and
// reflection provider - the lazy seam that keeps the routing parser's constructor free of the
// reflection-provider cycle (DiscoveryRefResolver's own precedent). Every other interface method is
// unreachable here and deliberately throws, mirroring FixtureRecordSourceContainer.
final class FixtureTemplateTypeContainer implements Container
{

	private DiscoveryRecordSource $recordSource;

	private PairingJudge $judge;

	private ReflectionProvider $reflectionProvider;

	public function __construct(
		DiscoveryRecordSource $recordSource,
		PairingJudge $judge,
		ReflectionProvider $reflectionProvider
	)
	{
		$this->recordSource = $recordSource;
		$this->judge = $judge;
		$this->reflectionProvider = $reflectionProvider;
	}

	public function hasService(string $serviceName): bool
	{
		return $serviceName === TemplateTypeChecker::RECORD_SOURCE_SERVICE_NAME
			|| $serviceName === TemplateTypeChecker::PAIRING_JUDGE_SERVICE_NAME
			|| $serviceName === TemplateTypeChecker::REFLECTION_PROVIDER_SERVICE_NAME;
	}

	public function getService(string $serviceName)
	{
		if ($serviceName === TemplateTypeChecker::RECORD_SOURCE_SERVICE_NAME) {
			return $this->recordSource;
		}

		if ($serviceName === TemplateTypeChecker::PAIRING_JUDGE_SERVICE_NAME) {
			return $this->judge;
		}

		if ($serviceName === TemplateTypeChecker::REFLECTION_PROVIDER_SERVICE_NAME) {
			return $this->reflectionProvider;
		}

		throw new LogicException('Not implemented.');
	}

	public function getByType(string $className)
	{
		throw new LogicException('Not implemented.');
	}

	public function findServiceNamesByType(string $className): array
	{
		throw new LogicException('Not implemented.');
	}

	public function getExtensionsCollection(string $extensionInterfaceName): ExtensionsCollection
	{
		throw new LogicException('Not implemented.');
	}

	public function getServicesByTag(string $tagName): array
	{
		throw new LogicException('Not implemented.');
	}

	public function getParameters(): array
	{
		throw new LogicException('Not implemented.');
	}

	public function hasParameter(string $parameterName): bool
	{
		throw new LogicException('Not implemented.');
	}

	public function getParameter(string $parameterName)
	{
		throw new LogicException('Not implemented.');
	}

}
