<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\CompileException;
use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Latte\Feature;
use LogicException;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\CacheLatte\CacheExtension;
use Nette\Bridges\FormsLatte\FormsExtension;
use Nette\Caching\Storages\DevNullStorage;
use OriPhpstan\Nette\Latte\Compile\CompileResult;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use function array_merge;
use function class_exists;
use function preg_match;
use function preg_quote;
use function str_replace;
use function strpos;
use const E_USER_DEPRECATED;

// Engine::parse() -> applyPasses() -> generate(), the same three steps Engine::compile() runs, over
// a fixed extension set with AnalysisExtension registered last. Unknown tags and attributes are
// claimed as passthrough and retried, the way LatteCompiler does for Latte 2 - Latte 3 has no
// unknown-tag hook, only the CompileException from TemplateParser::getTagParser() /
// TemplateParserHtml::prepareNAttrs().
final class Latte3Compiler
{

	private const MAX_UNKNOWN_TAG_RETRIES = 20;

	// Latte 2 core/bridge tags Latte 3 dropped: their "Unexpected tag" is a migration error, never a
	// custom tag worth a passthrough.
	private const LATTE2_ONLY_TAGS = [
		'includeblock' => true,
		'ifCurrent' => true,
		'status' => true,
		'use' => true,
	];

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

				$unknown = $this->matchUnknown($message);
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

		try {
			$code = VendorErrorContainment::run(
				static function () use ($engine, $node, $templateName): string {
					$engine->applyPasses($node);

					return $engine->generate($node, $templateName);
				},
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
		} catch (CompileException $e) {
			return CompileResult::failure(
				$className,
				new Diagnostic(
					'orisaiNette.latte.parseError',
					$e->getMessage(),
					$e->position !== null ? $e->position->line : 1,
				),
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

	// Engine's own defaults (CoreExtension, SandboxExtension) plus the nette bridges the DI extension
	// would add and the translator tags Latte 2 had in its core; the harvested set replaces this
	// fixed list later. Strict types stay off on every line until a harvested engine says otherwise.
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
		$engine = new Engine();
		$engine->setFeature(Feature::StrictTypes, false);
		$engine->addExtension(new UIExtension(null));
		$engine->addExtension(new FormsExtension());
		if (class_exists(CacheExtension::class)) {
			$engine->addExtension(new CacheExtension(new DevNullStorage()));
		}

		$engine->addExtension(new TranslatorExtension(null));

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

	/**
	 * @return array{string, bool}|null
	 */
	private function matchUnknown(string $message): ?array
	{
		if (preg_match('~^Unexpected tag \{([\w:.-]+)~', $message, $m) === 1) {
			return isset(self::LATTE2_ONLY_TAGS[$m[1]]) ? null : [$m[1], false];
		}

		if (preg_match('~^Unexpected attribute n:(?:inner-|tag-)?([\w:.-]+)~', $message, $m) === 1) {
			return isset(self::LATTE2_ONLY_TAGS[$m[1]]) ? null : [$m[1], true];
		}

		return null;
	}

	// Latte 2's PassthroughMacro was AUTO_CLOSE: paired when a closing tag exists, void otherwise.
	// A Latte 3 parser is one or the other (a generator expects its closing tag), so the source
	// decides - textually, so a {/foo} inside a {* comment *} or a string also pairs the name, the
	// same way a name used both paired and unpaired in one template falls back to Latte's own
	// parse error.
	private function hasClosingTag(string $source, string $name): bool
	{
		return preg_match('~\{/' . preg_quote($name, '~') . '\s*\}~', $source) === 1;
	}

	private static function lineOf(string $message): int
	{
		return preg_match('~on line (\d+)~', $message, $m) === 1 ? (int) $m[1] : 1;
	}

}
