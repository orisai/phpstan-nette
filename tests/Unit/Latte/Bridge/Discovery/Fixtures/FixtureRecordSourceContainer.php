<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery\Fixtures;

use LogicException;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ExtensionsCollection;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRefResolver;

// DiscoveryRefResolver only ever calls getService() for its record source (the lazy seam that
// keeps the parser's constructor free of the reflection-provider cycle) - every other interface
// method is unreachable in these tests and deliberately throws.
final class FixtureRecordSourceContainer implements Container
{

	private DiscoveryRecordSource $recordSource;

	public function __construct(DiscoveryRecordSource $recordSource)
	{
		$this->recordSource = $recordSource;
	}

	public function hasService(string $serviceName): bool
	{
		return $serviceName === DiscoveryRefResolver::RECORD_SOURCE_SERVICE_NAME;
	}

	public function getService(string $serviceName)
	{
		if ($serviceName === DiscoveryRefResolver::RECORD_SOURCE_SERVICE_NAME) {
			return $this->recordSource;
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
