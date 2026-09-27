<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Php\PhpVersion;
use function array_keys;
use function array_map;

final class FileFactIndex
{

	private const NODE_ID = 'registration-facts';

	private FormShapeCache $cache;

	private Parser $parser;

	private RegistrationRecognizer $recognizer;

	private ?PhpVersion $phpVersion;

	public function __construct(
		FormShapeCache $cache,
		Parser $parser,
		RegistrationRecognizer $recognizer,
		?PhpVersion $phpVersion = null
	)
	{
		$this->cache = $cache;
		$this->parser = $parser;
		$this->recognizer = $recognizer;
		// Production autowires the resolved PhpVersion so both the per-file fact entries and the
		// fold-blob manifest carry the config identity; direct unit construction omits it — those
		// never share a cache directory across phpVersion identities.
		$this->phpVersion = $phpVersion;
	}

	/**
	 * Every config-borne input that changes what extractFacts() yields from unchanged bytes, so two
	 * configs sharing a cache directory can never serve each other's entries. The recognizer itself
	 * is purely syntactic (no config knobs); the only such input is the parser's resolved phpVersion —
	 * the ParserErrorsException swallow below turns an unparseable-at-this-version file into zero
	 * facts, so a version change alone moves the answer. Any future config-borne extraction input
	 * (e.g. a bleeding-edge parser toggle) MUST be appended to this same identity string — it salts
	 * BOTH the per-file fact entries and RegistrationIndex's fold-blob manifest.
	 */
	public function configIdentity(): string
	{
		return $this->phpVersion === null ? '' : 'phpVersion:' . $this->phpVersion->getVersionId();
	}

	/**
	 * @return list<RegistrationFact>
	 */
	public function factsFor(string $file, string $contentHash): array
	{
		/**
		 * @return list<array<string, mixed>>
		 */
		$compute = fn (): array => array_map(
			static fn (RegistrationFact $fact): array => $fact->toArray(),
			$this->extractFacts($file),
		);

		// The index fold loads every file's facts; without detaching, each entry's replay would make
		// the enclosing frame depend on the whole file universe (an invalidation storm). Facts are
		// content-addressed by $contentHash, so detaching their own deps costs nothing — the resolver
		// records the answer's real dependencies into active frames when it answers.
		$recorder = $this->cache->recorder();
		$saved = $recorder->detachFrames();

		try {
			$stored = $this->cache->remember($contentHash, self::NODE_ID . '|' . $this->configIdentity(), $compute);
		} finally {
			$recorder->restoreFrames($saved);
		}

		return array_map(
			static fn (array $data): RegistrationFact => RegistrationFact::fromArray($data),
			$stored,
		);
	}

	/**
	 * @return list<RegistrationFact>
	 */
	private function extractFacts(string $file): array
	{
		$ast = $this->parseFile($file);

		$facts = [];
		// A free function is a callee like any other - the callee key is a bare name - and a hand-over
		// to one is dropped for want of a param fact unless its body is read here too.
		foreach ((new NodeFinder())->findInstanceOf($ast, Function_::class) as $function) {
			foreach ($this->recognizer->freeFunctionParamMutations($function) as $fact) {
				$facts[] = $fact;
			}
		}

		foreach ($this->fileClassLikes($ast) as $classLike) {
			// Anonymous classes leave namespacedName uninitialized (not null); the collectors key
			// only named class-likes, so skip them without tripping the typed-property access.
			if (!isset($classLike->namespacedName)) {
				continue;
			}

			$className = $classLike->namespacedName->toString();
			foreach ($classLike->getTraitUses() as $traitUseStmt) {
				foreach ($traitUseStmt->traits as $trait) {
					$facts[] = RegistrationFact::traitUse($className, $trait->toString());
				}
			}

			// $this->onReload(...) invokes a PROPERTY of this class, not a method of that name; the
			// recognizer needs the property set to tell the two apart at the hand-over sites.
			$dispatchProperties = [];
			foreach ($classLike->getProperties() as $property) {
				foreach ($property->props as $prop) {
					$dispatchProperties[$prop->name->toString()] = true;
				}
			}

			foreach ($classLike->getMethods() as $method) {
				foreach (array_keys($this->recognizer->returnedVariableNames($method)) as $returnedVarName) {
					foreach ($this->recognizer->eventRegistrations($method, $className, $returnedVarName) as $fact) {
						$facts[] = $fact;
					}
				}

				foreach ($this->recognizer->arrayCallableRegistrations($method, $className) as $fact) {
					$facts[] = $fact;
				}

				foreach ($this->recognizer->passThroughEdges($method, $className) as $fact) {
					$facts[] = $fact;
				}

				foreach ($this->recognizer->componentMutations($method, $className, $dispatchProperties) as $fact) {
					$facts[] = $fact;
				}

				foreach ($this->recognizer->paramMutations($method, $className) as $fact) {
					$facts[] = $fact;
				}
			}
		}

		return $facts;
	}

	/**
	 * @return array<Node>
	 */
	private function parseFile(string $file): array
	{
		// A file unparseable under the configured PHP version (e.g. a php8-only fixture folded by a
		// php7.4 config) contributes no facts. PHPStan analyses files with this same parser, so an
		// unparseable file is never in the analysed corpus either — dropping its registrations can only
		// widen a handler-param answer (to a permissive miss), never narrow one.
		try {
			return $this->parser->parseFile($file);
		} catch (ParserErrorsException $e) {
			return [];
		}
	}

	/**
	 * @param array<Node> $ast
	 * @return list<Class_|Trait_>
	 */
	private function fileClassLikes(array $ast): array
	{
		$classLikes = [];
		foreach ((new NodeFinder())->findInstanceOf($ast, ClassLike::class) as $node) {
			if ($node instanceof Class_ || $node instanceof Trait_) {
				$classLikes[] = $node;
			}
		}

		return $classLikes;
	}

}
