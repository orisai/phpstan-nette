<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use LogicException;
use OriPhpstan\Nette\Latte\Compile\DiscoveryClassName;
use PHPStan\DependencyInjection\Container;
use function array_keys;
use function class_exists;
use function interface_exists;
use function sort;
use function trait_exists;
use const SORT_STRING;

// Single-hop DIRECT edges only: a signature-level PHP edit reanalyzes the linked template on the
// same warm run, a body-only edit reaches it one run later through the writer-rewritten store file.
// The record source is resolved lazily BY SERVICE NAME because the routing parser holds this
// resolver, and a constructor-eager walk would chain reflectionProvider -> source locators ->
// defaultAnalysisParser back into that parser's own construction.
final class DiscoveryRefResolver
{

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	private Container $container;

	private DiscoveryStore $store;

	private bool $enabled;

	private ?DiscoveryRecordSource $recordSource = null;

	private FileClassScanner $scanner;

	public function __construct(Container $container, DiscoveryStore $store, bool $enabled)
	{
		$this->container = $container;
		$this->store = $store;
		$this->enabled = $enabled;
		$this->scanner = new FileClassScanner();
	}

	/**
	 * @return list<string>
	 */
	public function refClassNamesFor(string $relativePath): array
	{
		// Opt-in gate: disabled never calls into the store at all (SiteScopeStore discipline).
		if (!$this->enabled) {
			return [];
		}

		$names = [];

		// Self-ref only when the store file already EXISTS on disk - referencing a class whose
		// file does not exist would be class.notFound (the hasSlice() precedent).
		if ($this->store->hasTemplateFile($relativePath)) {
			$names[DiscoveryClassName::forPath($relativePath)] = true;
		}

		foreach ($this->store->recordsForTemplate($relativePath) as $record) {
			// class_exists (runtime autoload) mirrors emitTemplateTypeRef's own guard: a record
			// class deleted since the store was written must never be referenced.
			$className = $record['class'];
			if (!class_exists($className)) {
				continue;
			}

			$names[$className] = true;

			foreach ($this->recordSource()->factsFor($className)->getReadSet() as $file) {
				$readSetClass = $this->scanner->firstClassLikeIn($file);
				if ($readSetClass === null || !self::classLikeExists($readSetClass)) {
					continue;
				}

				$names[$readSetClass] = true;
			}
		}

		$list = array_keys($names);
		sort($list, SORT_STRING);

		return $list;
	}

	private static function classLikeExists(string $name): bool
	{
		return class_exists($name) || interface_exists($name) || trait_exists($name);
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
