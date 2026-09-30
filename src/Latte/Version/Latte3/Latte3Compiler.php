<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\CompileException;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Expression\FunctionCallNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\NodeTraverser;
use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Latte\Extension;
use Latte\Feature;
use LogicException;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\CacheLatte\CacheExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Caching\Storages\DevNullStorage;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Compile\VendorCompileFailure;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use OriPhpstan\Nette\Latte\Version\DefaultCallables;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use ReflectionProperty;
use Throwable;
use function array_merge;
use function array_shift;
use function class_exists;
use function get_class;
use function get_object_vars;
use function is_a;
use function preg_match;
use function preg_quote;
use function str_replace;
use function strpos;
use const E_USER_DEPRECATED;
use const E_USER_WARNING;

// Engine::parse() -> applyPasses() -> generate(), the same three steps Engine::compile() runs, over
// the harvested engine's extensions and features (or, with nothing harvested, a fixed extension
// set) with AnalysisExtension registered last. Unknown tags and attributes are
// claimed as passthrough and retried, the way LatteCompiler does for Latte 2 - Latte 3 has no
// unknown-tag hook, only the CompileException from TemplateParser::getTagParser() /
// TemplateParserHtml::prepareNAttrs().
final class Latte3Compiler
{

	private const MAX_UNKNOWN_TAG_RETRIES = 20;

	private const FIXED_SET_SALT = 'fixed-set';

	// Latte 3.0 only; 3.1 has no FunctionCallableNode.
	private const FUNCTION_CALLABLE_NODE = 'Latte\\Compiler\\Nodes\\Php\\Expression\\FunctionCallableNode';

	private const FUNCTION_CASE_MISMATCH_PATTERN = "~^Case mismatch on function name '([^']+)', correct name is '([^']+)'\\.$~";

	// Latte 2 core/bridge tags Latte 3 dropped: their "Unexpected tag" is a migration error, never a
	// custom tag worth a passthrough.
	private const LATTE2_ONLY_TAGS = [
		'includeblock' => true,
		'ifCurrent' => true,
		'status' => true,
		'use' => true,
	];

	// Tags only a paired parser asks for; no extension registers them on their own.
	private const INTERMEDIATE_TAGS = [
		'else' => true,
		'elseif' => true,
		'elseifset' => true,
		'case' => true,
	];

	private ?CustomsHarvester $harvester;

	private ?ShapeFamily $family;

	public function __construct(?CustomsHarvester $harvester = null, ?ShapeFamily $family = null)
	{
		$this->harvester = $harvester;
		$this->family = $family;
	}

	// What the compile engine depends on beyond the source: the harvested extensions, functions and
	// features, or the fixed set - whose CacheExtension depends on nette/caching being installed.
	public function engineSalt(): string
	{
		$harvested = $this->harvested();

		if ($harvested->isExtensionHarvest()) {
			return $harvested->getSaltHash();
		}

		return self::FIXED_SET_SALT . (class_exists(CacheExtension::class) ? '|cache' : '');
	}

	public function parse(string $source): ParsedTemplate
	{
		$diagnostics = [];
		$claimedNames = [];
		$passthroughTags = [];
		$passthroughAttributes = [];

		for ($attempt = 0; $attempt <= self::MAX_UNKNOWN_TAG_RETRIES; $attempt++) {
			$typeCapture = new TypeCapturingParsers();
			$recorder = new TagRecorder();
			$engine = $this->createEngine($typeCapture, $recorder, $passthroughTags, $passthroughAttributes);
			$deprecations = [];

			try {
				$node = VendorErrorContainment::run(
					static fn () => $engine->parse($source),
					static function (int $severity, string $message) use (&$deprecations): void {
						if ($severity === E_USER_DEPRECATED) {
							$deprecations[] = new Diagnostic(
								'orisaiNette.latte.deprecated',
								$message,
								self::lineOf($message),
							);
						}
					},
				);

				return ParsedTemplate::parsed(
					$engine,
					$node,
					$typeCapture->getCaptured(),
					$recorder,
					array_merge($diagnostics, $deprecations),
				);
			} catch (CompileException $e) {
				$line = $e->position !== null ? $e->position->line : 1;
				$message = $e->getMessage();

				if (
					preg_match(
						'~^Unexpected /\} in tag \{([\w:.-]+)~',
						$message,
						$m,
					) === 1
					&& isset($passthroughTags[$m[1]])
				) {
					$passthroughTags[$m[1]] = true;

					continue;
				}

				$unknown = $this->matchUnknown($message, $engine);
				if ($unknown === null) {
					return ParsedTemplate::failed(new Diagnostic('orisaiNette.latte.parseError', $message, $line));
				}

				[$name, $isAttribute] = $unknown;
				if ($isAttribute) {
					$passthroughAttributes[$name] = true;
				} else {
					$passthroughTags[$name] = $this->hasClosingTag($source, $name);
				}

				if (!isset($claimedNames[$name])) {
					$claimedNames[$name] = true;
					$diagnostics[] = new Diagnostic(
						'orisaiNette.latte.unknownMacro',
						"Unknown Latte macro or attribute '$name'.",
						$line,
					);
				}
			} catch (Throwable $e) {
				return ParsedTemplate::failed(new Diagnostic(
					'orisaiNette.latte.parseError',
					VendorCompileFailure::message($e),
					$recorder->lastTagLine() ?? 1,
				));
			}
		}

		return ParsedTemplate::failed(new Diagnostic('orisaiNette.latte.parseError', 'Too many unknown macros.', 1));
	}

	public function generate(ParsedTemplate $parsed, string $className, string $templateName): CompileResult
	{
		$failure = $parsed->getFailure();
		if ($failure !== null) {
			return CompileResult::failure($className, $failure);
		}

		$engine = $parsed->getEngine();
		$node = $parsed->getNode();
		if ($engine === null || $node === null) {
			throw new LogicException('A parsed template carries its engine and node.');
		}

		$deprecations = [];
		$functionCallLines = $this->family === null || $this->family->latteLine === ShapeFamily::LATTE_30
			? self::functionCallLines($node)
			: [];

		try {
			$code = VendorErrorContainment::run(
				static function () use ($engine, $node, $templateName): string {
					$engine->applyPasses($node);

					return $engine->generate($node, $templateName);
				},
				static function (int $severity, string $message) use (&$deprecations, &$functionCallLines): void {
					if ($severity === E_USER_DEPRECATED) {
						$deprecations[] = new Diagnostic(
							'orisaiNette.latte.deprecated',
							$message,
							self::lineOf($message),
						);
					} elseif (
						$severity === E_USER_WARNING
						&& preg_match(self::FUNCTION_CASE_MISMATCH_PATTERN, $message, $m) === 1
					) {
						$deprecations[] = new Diagnostic(
							'orisaiNette.latte.functionCaseMismatch',
							"Latte function '$m[1]' differs in case from the registered '$m[2]' - Latte 3.0 resolves it "
							. 'with a warning, Latte 3.1 does not resolve it.',
							isset($functionCallLines[$m[1]]) && $functionCallLines[$m[1]] !== []
								? array_shift($functionCallLines[$m[1]])
								: 1,
						);
					}
				},
			);
		} catch (CompileException $e) {
			return CompileResult::failure(
				$className,
				new Diagnostic(
					'orisaiNette.latte.parseError',
					$e->getMessage(),
					$e->position !== null ? $e->position->line : 1,
				),
			);
		} catch (Throwable $e) {
			return CompileResult::failure(
				$className,
				new Diagnostic('orisaiNette.latte.parseError', VendorCompileFailure::message($e), 1),
			);
		}

		// Engine::generate() names the class by its configuration hash (Engine::getTemplateClass());
		// the analysis needs the caller's deterministic name in the otherwise unchanged code.
		$hashedClass = $engine->getTemplateClass($templateName);
		if (strpos($code, $hashedClass) === false) {
			throw new LogicException("Generated code does not declare the expected class $hashedClass.");
		}

		return CompileResult::success(
			str_replace($hashedClass, $className, $code),
			$className,
			array_merge($parsed->getDiagnostics(), $deprecations),
		);
	}

	// The stock filters and functions of the fixed set, so the table the rewriter resolves against
	// and the names the compiler accepts agree; harvested ones join the tables as HarvestedCustoms.
	// CoreExtension's inline lambdas (|limit, hasBlock(), hasTemplate()) have no static target of
	// their own and UIExtension registers isLinkCurrent()/isModuleCurrent() only with a presenter;
	// Helpers stands in for all of them.
	public function defaultCallables(): DefaultCallables
	{
		$engine = $this->createFixedEngine();

		return new DefaultCallables(
			$engine->getFilters(),
			$engine->getFunctions() + [
				'isLinkCurrent' => [Helpers::class, 'presenterIsLinkCurrent'],
				'isModuleCurrent' => [Helpers::class, 'presenterIsModuleCurrent'],
			],
			['limit' => [Helpers::class, 'limit']],
			['hasblock' => [Helpers::class, 'hasBlock'], 'hastemplate' => [Helpers::class, 'hasTemplate']],
		);
	}

	// Engine's own defaults (CoreExtension, SandboxExtension) plus the nette bridges the DI extension
	// would add and the translator tags Latte 2 had in its core. Strict types stay off on every line.
	private function createFixedEngine(): Engine
	{
		$engine = new Engine();
		$engine->setFeature(Feature::StrictTypes, false);
		$engine->addExtension(new UIExtension(null));
		$engine->addExtension(new FormsExtension());
		if (class_exists(CacheExtension::class)) {
			$engine->addExtension(new CacheExtension(new DevNullStorage()));
		}

		$engine->addExtension(new TranslatorExtension(null));

		return $engine;
	}

	// The harvested extensions in the project's order on a fresh engine, which already carries the
	// CoreExtension and SandboxExtension every Engine constructs with; functions added to the
	// project engine directly (not through an extension) are registered too, so CoreExtension's
	// customFunctions pass compiles their calls the way the project's engine does. The features are
	// copied as the engine stores them - the key format differs between 3.0 and 3.1.
	private function createHarvestedEngine(HarvestedCustoms $harvested): Engine
	{
		$engine = new Engine();

		$ownClasses = [];
		foreach ($engine->getExtensions() as $extension) {
			$ownClasses[get_class($extension)] = true;
		}

		foreach ($harvested->getExtensions() as $extension) {
			if (!$extension instanceof Extension) {
				continue;
			}

			$class = get_class($extension);
			if (isset($ownClasses[$class])) {
				unset($ownClasses[$class]);

				continue;
			}

			$engine->addExtension($extension);
		}

		$registered = $engine->getFunctions();
		foreach ($harvested->getFunctions() as $name => $function) {
			if (!isset($registered[$name])) {
				$engine->addFunction($name, $function);
			}
		}

		$features = new ReflectionProperty(Engine::class, 'features');
		$features->setAccessible(true);
		$features->setValue($engine, $harvested->getFeatures());

		return $engine;
	}

	// The analysis extension is added last with the effective tag map of everything before it, so
	// TagRecorder wraps the parser Latte itself would have dispatched to.

	/**
	 * @param array<string, bool> $passthroughTags
	 * @param array<string, true> $passthroughAttributes
	 */
	private function createEngine(
		TypeCapturingParsers $typeCapture,
		TagRecorder $recorder,
		array $passthroughTags,
		array $passthroughAttributes
	): Engine
	{
		$harvested = $this->harvested();
		$engine = $harvested->isExtensionHarvest()
			? $this->createHarvestedEngine($harvested)
			: $this->createFixedEngine();

		$baseTags = [];
		foreach ($engine->getExtensions() as $extension) {
			foreach ($extension->getTags() as $name => $parser) {
				$baseTags[$name] = $parser;
			}
		}

		$engine->addExtension(
			new AnalysisExtension($typeCapture, $passthroughTags, $passthroughAttributes, $baseTags, $recorder),
		);

		return $engine;
	}

	private function harvested(): HarvestedCustoms
	{
		return $this->harvester !== null ? $this->harvester->harvest() : HarvestedCustoms::empty();
	}

	// Only a name the engine has never heard of is a custom tag worth a passthrough: a known tag or
	// attribute reported as unexpected is misplaced ({else} outside {if}, {ifcontent} for
	// n:ifcontent, n:inner-name), and one in a <script> or <style> is an unescaped brace.

	/**
	 * @return array{string, bool}|null
	 */
	private function matchUnknown(string $message, Engine $engine): ?array
	{
		if (strpos($message, '(in JavaScript or CSS') !== false) {
			return null;
		}

		if (preg_match('~^Unexpected tag \{([\w:.-]+)~', $message, $m) === 1) {
			$isAttribute = false;
		} elseif (preg_match('~^Unexpected attribute n:(?:inner-|tag-)?([\w:.-]+)~', $message, $m) === 1) {
			$isAttribute = true;
		} else {
			return null;
		}

		$name = $m[1];
		if (isset(self::LATTE2_ONLY_TAGS[$name]) || isset(self::INTERMEDIATE_TAGS[$name])) {
			return null;
		}

		foreach ($engine->getExtensions() as $extension) {
			if ($extension instanceof AnalysisExtension) {
				continue;
			}

			$tags = $extension->getTags();
			if (isset($tags[$name]) || isset($tags['n:' . $name])) {
				return null;
			}
		}

		return [$name, $isAttribute];
	}

	// Latte 2's PassthroughMacro was AUTO_CLOSE: paired when a closing tag exists - its own or the
	// generic {/} - and void otherwise.
	// A Latte 3 parser is one or the other (a generator expects its closing tag), so the source
	// decides - textually, so a {/foo} inside a {* comment *} or a string also pairs the name, the
	// same way a name used both paired and unpaired in one template falls back to Latte's own
	// parse error.
	private function hasClosingTag(string $source, string $name): bool
	{
		return preg_match('~\{/(?:' . preg_quote($name, '~') . ')?\s*\}~', $source) === 1;
	}

	// Latte 3.0's customFunctionsPass resolves a function spelled in another case than registered and
	// warns once per call or first-class callable, in traversal order, without a line; their lines are
	// read before the pass replaces them. Latte 3.1 does not warn.

	/**
	 * @return array<string, list<int>>
	 */
	private static function functionCallLines(Node $node): array
	{
		$lines = [];
		(new NodeTraverser())->traverse($node, static function (Node $node) use (&$lines): void {
			$position = $node->position;
			if ($node instanceof FunctionCallNode) {
				$name = $node->name;
			} elseif (is_a($node, self::FUNCTION_CALLABLE_NODE)) {
				$name = get_object_vars($node)['name'] ?? null;
			} else {
				return;
			}

			if ($name instanceof NameNode && $position !== null) {
				$lines[(string) $name][] = $position->line;
			}
		});

		return $lines;
	}

	private static function lineOf(string $message): int
	{
		return preg_match('~on line (\d+)~', $message, $m) === 1 ? (int) $m[1] : 1;
	}

}
