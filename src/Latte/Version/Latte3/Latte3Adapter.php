<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use OriPhpstan\Nette\Latte\Includes\TagArgument;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\CompiledTemplate;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use function gc_collect_cycles;
use function implode;
use function memory_get_usage;
use function sha1;

// Holds no Latte 3 object itself: LatteVersionAdapterFactory may class_exists() and construct it
// where Latte 3 is not installed, so everything Latte-typed lives in the compiler's parse results.
final class Latte3Adapter implements LatteVersionAdapter
{

	private const CACHE_NODE_ID = 'latte3-compile';

	private const COLLECT_AFTER_BYTES = 64 * 1024 * 1024;

	private static int $collectedAtUsage = 0;

	private Latte3Compiler $compiler;

	private ShapeFamily $family;

	private ?LatteAnalysisCache $cache;

	private ?DiscoveryStore $discoveryStore;

	public function __construct(
		Latte3Compiler $compiler,
		ShapeFamily $family,
		?LatteAnalysisCache $cache = null,
		?DiscoveryStore $discoveryStore = null
	)
	{
		$this->compiler = $compiler;
		$this->family = $family;
		$this->cache = $cache;
		$this->discoveryStore = $discoveryStore;
	}

	public static function create(ShapeFamily $family, AdapterCollaborators $collaborators): self
	{
		return new self(
			new Latte3Compiler($collaborators->getHarvester()),
			$family,
			$collaborators->getCache(),
			$collaborators->isDiscoveryStoreEnabled() ? $collaborators->getDiscoveryStore() : null,
		);
	}

	// Keyed like LatteCompiler's Latte 2 entries - source, class, harvest and discovery salts, the
	// family and adapter - plus the relative path, which the generated code and the facts carry.
	// Only a successful compile is cached, with its facts resolved.
	public function compile(string $source, string $className, string $relativePath): CompiledTemplate
	{
		if ($this->cache === null) {
			return $this->doCompile($source, $className, $relativePath);
		}

		$contentHash = implode('|', [
			sha1($source),
			$className,
			$relativePath,
			$this->compiler->engineSalt(),
			$this->discoveryStore !== null ? $this->discoveryStore->recordsSaltForTemplateClass(
				$className,
			) : 'disabled',
			$this->family->id() . '|' . self::class,
		]);

		/** @var array{result: CompileResult, declarations: Declarations, templateFacts: TemplateFacts, formSites: list<FormSite>}|null $cached */
		$cached = $this->cache->readContentAddressed($contentHash, self::CACHE_NODE_ID);
		if ($cached !== null) {
			return new CompiledTemplate(
				$cached['result'],
				ExtractedFacts::eager($cached['declarations'], $cached['templateFacts'], $cached['formSites']),
			);
		}

		$compiled = $this->doCompile($source, $className, $relativePath);
		$result = $compiled->getResult();
		if ($result->getPhpSource() !== null) {
			$facts = $compiled->getFacts();
			$this->cache->writeContentAddressed($contentHash, self::CACHE_NODE_ID, [
				'result' => $result,
				'declarations' => $facts->getDeclarations(),
				'templateFacts' => $facts->getTemplateFacts(),
				'formSites' => $facts->getFormSites(),
			]);
		}

		return $compiled;
	}

	public function extractFacts(string $source, string $relativePath): ExtractedFacts
	{
		$facts = $this->factsOf($this->compiler->parse($source), $source, $relativePath);
		self::collectParserCycles();

		return $facts;
	}

	public function lineMarkerPattern(): string
	{
		return $this->family->lineMarkerPattern();
	}

	public function family(): ShapeFamily
	{
		return $this->family;
	}

	public function defaultCallables(): DefaultCallables
	{
		return $this->compiler->defaultCallables();
	}

	/**
	 * @return list<TagArgument>
	 */
	public function parseTagArguments(string $argsSource): array
	{
		return TagLexerArguments::parse($argsSource);
	}

	private function doCompile(string $source, string $className, string $relativePath): CompiledTemplate
	{
		$parsed = $this->compiler->parse($source);
		$facts = $this->factsOf($parsed, $source, $relativePath);
		$compiled = new CompiledTemplate($this->compiler->generate($parsed, $className, $relativePath), $facts);
		unset($parsed);
		self::collectParserCycles();

		return $compiled;
	}

	// Latte 3's parser leaves every parse behind as reference cycles (engine, parser, tags, nodes),
	// about 0.4 MB apiece, and PHPStan runs with the cycle collector disabled. A collection walks
	// everything PHPStan holds, so it runs only once enough garbage may have piled up.
	private static function collectParserCycles(): void
	{
		if (memory_get_usage() - self::$collectedAtUsage < self::COLLECT_AFTER_BYTES) {
			return;
		}

		gc_collect_cycles();
		self::$collectedAtUsage = memory_get_usage();
	}

	// Read before generate(): the passes mutate the parsed tree in place.
	private function factsOf(ParsedTemplate $parsed, string $source, string $relativePath): ExtractedFacts
	{
		return (new NodeFactsExtractor())->extract($parsed, $source, $relativePath);
	}

}
