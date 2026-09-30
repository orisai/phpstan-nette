<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Closure;
use Latte\Engine;
use Latte\Essential\TranslatorExtension;
use Latte\Extension;
use Latte\Runtime\FilterExecutor;
use LogicException;
use Nette\Bridges\ApplicationLatte\UIExtension;
use OriPhpstan\Nette\Latte\Customs\FilterLoaderProbe;
use OriPhpstan\Nette\Latte\Customs\HarvestedCustoms;
use OriPhpstan\Nette\Latte\Customs\OriginalNameCollisionMap;
use OriPhpstan\Nette\Latte\Version\LatteEngineReader;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use function array_keys;
use function get_class;
use function get_debug_type;
use function is_callable;
use function strpos;
use function strtolower;

// Reads what Engine::parse()/addExtension() would use: the extensions in registration order (their
// getTags() keys are the tag names), the static filters, functions and providers, and the feature
// flags. Filter loaders are invisible to Engine::getFilters() and are asked per name instead
// (filterLoaders()); Latte 3 has no function loaders.
final class Latte3EngineReader implements LatteEngineReader
{

	public static function create(): self
	{
		return new self();
	}

	public function read(Engine $engine): HarvestedCustoms
	{
		$extensions = self::withRuntimeExtensions($engine->getExtensions());
		$filters = $engine->getFilters();
		$functions = $engine->getFunctions();

		$providerTypes = [];
		foreach ($engine->getProviders() as $name => $provider) {
			$providerTypes[$name] = get_debug_type($provider);
		}

		return HarvestedCustoms::fromExtensions(
			$filters,
			$functions,
			$extensions,
			self::tagClassesByName($extensions),
			OriginalNameCollisionMap::build(self::origToLower($filters)),
			OriginalNameCollisionMap::build(self::origToLower($functions)),
			self::features($engine),
			$providerTypes,
		)->withFilterLoaders(self::filterLoaders($engine));
	}

	// FilterExecutor::__get() is the runtime's own question: it answers a static filter, else asks
	// the loaders in _dynamic order and keeps the first answer as a static one, else throws. The
	// answer is read back from _static because __get() hands out a FilterInfo-aware filter wrapped.
	private static function filterLoaders(Engine $engine): ?FilterLoaderProbe
	{
		$executor = self::privateValue(Engine::class, 'filters', $engine);
		if (!$executor instanceof FilterExecutor) {
			return null;
		}

		if (self::privateValue(FilterExecutor::class, '_dynamic', $executor) === []) {
			return null;
		}

		return new FilterLoaderProbe(
			static function (string $name) use ($executor) {
				try {
					$executor->__get($name);
				} catch (LogicException $e) {
					return null;
				}

				/** @var array<string, array{callable, bool|null}> $static */
				$static = self::privateValue(FilterExecutor::class, '_static', $executor);

				return $static[$name][0] ?? null;
			},
			true,
		);
	}

	/**
	 * @param class-string $class
	 * @return mixed
	 */
	private static function privateValue(string $class, string $name, object $object)
	{
		$property = new ReflectionProperty($class, $name);
		$property->setAccessible(true);

		return $property->getValue($object);
	}

	// Extensions nette/application adds to the engine only at render time: TemplateFactory adds
	// UIExtension when LatteFactory::create() was called without a control (application 3.2) and
	// Template::setTranslator() adds TranslatorExtension. A harvested engine without them would call
	// {link}, {control} or {_} unknown although they work at runtime.

	/**
	 * @param array<Extension> $registered
	 * @return list<Extension>
	 */
	private static function withRuntimeExtensions(array $registered): array
	{
		$extensions = [];
		$hasUi = false;
		$hasTranslator = false;
		foreach ($registered as $extension) {
			$extensions[] = $extension;
			$hasUi = $hasUi || $extension instanceof UIExtension;
			$hasTranslator = $hasTranslator || $extension instanceof TranslatorExtension;
		}

		if (!$hasUi) {
			$extensions[] = new UIExtension(null);
		}

		if (!$hasTranslator) {
			$extensions[] = new TranslatorExtension(null);
		}

		return $extensions;
	}

	// TemplateParser::addTags(): a later registration overwrites an earlier one, an n:-prefixed name
	// is an attribute only, and a generator tag parser also becomes the n:, n:inner- and n:tag-
	// attribute of its name.

	/**
	 * @param list<Extension> $extensions
	 * @return array<string, class-string>
	 */
	private static function tagClassesByName(array $extensions): array
	{
		$classesByName = [];
		foreach ($extensions as $extension) {
			$class = get_class($extension);
			foreach ($extension->getTags() as $name => $parser) {
				$classesByName[$name] = $class;
				if (strpos($name, 'n:') === 0 || !self::isGenerator($parser)) {
					continue;
				}

				foreach (['n:', 'n:inner-', 'n:tag-'] as $prefix) {
					$classesByName[$prefix . $name] = $class;
				}
			}
		}

		return $classesByName;
	}

	/**
	 * @param mixed $parser
	 */
	private static function isGenerator($parser): bool
	{
		$subject = $parser instanceof stdClass ? $parser->subject : $parser;

		return is_callable($subject) && (new ReflectionFunction(Closure::fromCallable($subject)))->isGenerator();
	}

	/**
	 * @return array<string, bool>
	 */
	private static function features(Engine $engine): array
	{
		/** @var array<string, bool> $features */
		$features = self::privateValue(Engine::class, 'features', $engine);

		return $features;
	}

	/**
	 * @param array<string, mixed> $callables
	 * @return array<string, string>
	 */
	private static function origToLower(array $callables): array
	{
		$map = [];
		foreach (array_keys($callables) as $name) {
			$map[$name] = strtolower($name);
		}

		return $map;
	}

}
