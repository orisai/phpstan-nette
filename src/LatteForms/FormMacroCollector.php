<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Forms\ControlReference;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterAccessor;
use function sha1;

// Per-template form-macro sites (every {form}/{formContext}/<form n:name> scope with the control
// references inside it), read from the installed Latte's adapter facts and kept content-addressed.
final class FormMacroCollector
{

	private const CACHE_NODE_ID = 'latteforms-macros-v2';

	private LatteUniverse $universe;

	private LatteVersionAdapterAccessor $adapterAccessor;

	private ?LatteAnalysisCache $cache;

	/** @var array<string, list<FormSite>> */
	private array $sitesByPath = [];

	/** @var array<string, string>|null */
	private ?array $filesByPath = null;

	public function __construct(
		LatteUniverse $universe,
		LatteVersionAdapterAccessor $adapterAccessor,
		?LatteAnalysisCache $cache = null
	)
	{
		$this->universe = $universe;
		$this->adapterAccessor = $adapterAccessor;
		$this->cache = $cache;
	}

	/**
	 * @return list<FormSite>
	 */
	public function sitesFor(string $templateRelPath): array
	{
		if (!isset($this->sitesByPath[$templateRelPath])) {
			$this->sitesByPath[$templateRelPath] = $this->load($templateRelPath);
		}

		return $this->sitesByPath[$templateRelPath];
	}

	/**
	 * @return list<FormSite>
	 */
	private function load(string $templateRelPath): array
	{
		if ($this->filesByPath === null) {
			$map = [];
			foreach ($this->universe->files() as $file) {
				$map[$this->universe->relativePath($file)] = $file;
			}

			$this->filesByPath = $map;
		}

		$file = $this->filesByPath[$templateRelPath] ?? null;
		if ($file === null) {
			return [];
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			return [];
		}

		if ($this->cache === null) {
			return $this->scan($source, $templateRelPath);
		}

		/**
		 * @var array{sites: list<array{
		 *     formName: string|null,
		 *     line: int,
		 *     references: list<array{
		 *         kind: ControlReference::KIND_*,
		 *         name: string|null,
		 *         containerPath: list<string>,
		 *         line: int,
		 *         guarded: bool,
		 *     }>,
		 * }>} $entry
		 */
		$entry = $this->cache->rememberContentAddressed(
			sha1($source),
			self::CACHE_NODE_ID . '|' . $this->adapterAccessor->get()->family()->id(),
			fn (): array => ['sites' => $this->scanToArrays($source, $templateRelPath)],
		);

		$sites = [];
		foreach ($entry['sites'] as $site) {
			$sites[] = FormSite::fromArray($site);
		}

		return $sites;
	}

	/**
	 * @return list<array{formName: string|null, line: int, references: list<array{kind: ControlReference::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool}>}>
	 */
	private function scanToArrays(string $latteSource, string $templateRelPath): array
	{
		$sites = [];
		foreach ($this->scan($latteSource, $templateRelPath) as $site) {
			$sites[] = $site->toArray();
		}

		return $sites;
	}

	/**
	 * @return list<FormSite>
	 */
	private function scan(string $latteSource, string $templateRelPath): array
	{
		return $this->adapterAccessor->get()->extractFacts($latteSource, $templateRelPath)->getFormSites();
	}

}
