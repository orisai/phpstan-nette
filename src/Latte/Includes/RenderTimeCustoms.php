<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use OriPhpstan\Nette\Latte\Bridge\CallableFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Cache\TemplateNeighbourhood;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\ReflectionProvider;
use function array_key_exists;
use function ksort;
use function ltrim;
use const SORT_STRING;

// The filters and functions PHP code registers on a template or its engine before rendering it
// ($template->addFilter(), $template->getLatte()->addFunction()), read off the same render facts the
// template's discovery records link it to. The engine renders the template's includes, layouts and
// imports too, so a template knows the registrations of every renderer reaching it through any includer.
/**
 * @phpstan-type OpenSite array{class: string, file: string, line: int, reason: CallableFact::OPEN_*}
 * @phpstan-type Resolved array{filters: array<string, CallableFact>, functions: array<string, CallableFact>, openFilters: list<OpenSite>, openFunctions: list<OpenSite>}
 */
final class RenderTimeCustoms
{

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	private Container $container;

	private TemplateNeighbourhood $neighbourhood;

	private bool $enabled;

	private ?DiscoveryRecordSource $recordSource = null;

	private ?ReflectionProvider $reflectionProvider = null;

	/** @var array<string, Resolved> */
	private array $memo = [];

	public function __construct(Container $container, TemplateNeighbourhood $neighbourhood, bool $enabled)
	{
		$this->container = $container;
		$this->neighbourhood = $neighbourhood;
		$this->enabled = $enabled;
	}

	/**
	 * @return array<string, CallableFact>
	 */
	public function filtersFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['filters'];
	}

	/**
	 * @return array<string, CallableFact>
	 */
	public function functionsFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['functions'];
	}

	/**
	 * @return list<OpenSite>
	 */
	public function openFilterSitesFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['openFilters'];
	}

	/**
	 * @return list<OpenSite>
	 */
	public function openFunctionSitesFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['openFunctions'];
	}

	public function functionExists(string $name): bool
	{
		if ($this->reflectionProvider === null) {
			$this->reflectionProvider = $this->container->getByType(ReflectionProvider::class);
		}

		return $this->reflectionProvider->hasFunction(new FullyQualified(ltrim($name, '\\')), null);
	}

	/**
	 * @return Resolved
	 */
	private function resolved(string $projectRelativePath): array
	{
		if (array_key_exists($projectRelativePath, $this->memo)) {
			return $this->memo[$projectRelativePath];
		}

		return $this->memo[$projectRelativePath] = $this->resolve($projectRelativePath);
	}

	/**
	 * @return Resolved
	 */
	private function resolve(string $projectRelativePath): array
	{
		$resolved = ['filters' => [], 'functions' => [], 'openFilters' => [], 'openFunctions' => []];
		if (!$this->enabled) {
			return $resolved;
		}

		foreach ($this->neighbourhood->renderersReaching($projectRelativePath) as $className) {
			$facts = $this->recordSource()->factsFor($className);
			if (!Qualification::qualifies($facts)) {
				continue;
			}

			$resolved['filters'] = self::merged($resolved['filters'], $facts->getFilters());
			$resolved['functions'] = self::merged($resolved['functions'], $facts->getFunctions());
			foreach ($facts->getOpenFilterSites() as $site) {
				$resolved['openFilters'][] = ['class' => $className] + $site;
			}

			foreach ($facts->getOpenFunctionSites() as $site) {
				$resolved['openFunctions'][] = ['class' => $className] + $site;
			}
		}

		return $resolved;
	}

	/**
	 * @param array<string, CallableFact> $into
	 * @param array<string, CallableFact> $facts
	 * @return array<string, CallableFact>
	 */
	private static function merged(array $into, array $facts): array
	{
		foreach ($facts as $name => $fact) {
			$into[$name] = isset($into[$name]) ? $into[$name]->mergedWith($fact) : $fact;
		}

		ksort($into, SORT_STRING);

		return $into;
	}

	private function recordSource(): DiscoveryRecordSource
	{
		if ($this->recordSource === null) {
			$recordSource = $this->container->getService(self::RECORD_SOURCE_SERVICE_NAME);
			if (!$recordSource instanceof DiscoveryRecordSource) {
				throw new LogicException(
					self::RECORD_SOURCE_SERVICE_NAME . ' must be a DiscoveryRecordSource service.',
				);
			}

			$this->recordSource = $recordSource;
		}

		return $this->recordSource;
	}

}
