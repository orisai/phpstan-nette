<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\FilterFact;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Cache\TemplateNeighbourhood;
use PHPStan\DependencyInjection\Container;
use function array_key_exists;
use function ksort;
use const SORT_STRING;

// The filters PHP code registers on a template or its engine before rendering it ($template->addFilter(),
// $template->getLatte()->addFilter()), read off the same render facts the template's discovery records
// link it to. The engine renders the template's includes, layouts and imports too, so a template knows
// the filters of every renderer reaching it through any includer.
final class RenderTimeFilters
{

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	private Container $container;

	private TemplateNeighbourhood $neighbourhood;

	private bool $enabled;

	private ?DiscoveryRecordSource $recordSource = null;

	/** @var array<string, array{filters: array<string, FilterFact>, open: bool}> */
	private array $memo = [];

	public function __construct(Container $container, TemplateNeighbourhood $neighbourhood, bool $enabled)
	{
		$this->container = $container;
		$this->neighbourhood = $neighbourhood;
		$this->enabled = $enabled;
	}

	/**
	 * @return array<string, FilterFact>
	 */
	public function filtersFor(string $projectRelativePath): array
	{
		return $this->resolved($projectRelativePath)['filters'];
	}

	public function isOpenFor(string $projectRelativePath): bool
	{
		return $this->resolved($projectRelativePath)['open'];
	}

	/**
	 * @return array{filters: array<string, FilterFact>, open: bool}
	 */
	private function resolved(string $projectRelativePath): array
	{
		if (array_key_exists($projectRelativePath, $this->memo)) {
			return $this->memo[$projectRelativePath];
		}

		return $this->memo[$projectRelativePath] = $this->resolve($projectRelativePath);
	}

	/**
	 * @return array{filters: array<string, FilterFact>, open: bool}
	 */
	private function resolve(string $projectRelativePath): array
	{
		if (!$this->enabled) {
			return ['filters' => [], 'open' => false];
		}

		$filters = [];
		$open = false;
		foreach ($this->neighbourhood->renderersReaching($projectRelativePath) as $className) {
			$facts = $this->recordSource()->factsFor($className);
			if (!Qualification::qualifies($facts)) {
				continue;
			}

			$open = $open || $facts->hasOpenFilterSet();
			foreach ($facts->getFilters() as $name => $fact) {
				$filters[$name] = isset($filters[$name]) ? $filters[$name]->mergedWith($fact) : $fact;
			}
		}

		ksort($filters, SORT_STRING);

		return ['filters' => $filters, 'open' => $open];
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
