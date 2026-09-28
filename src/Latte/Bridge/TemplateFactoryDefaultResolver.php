<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use Nette\Application\UI\TemplateFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory as BridgeTemplateFactory;
use Nette\DI\Container;
use ReflectionProperty;
use Throwable;
use function get_class;
use function is_array;
use function is_file;
use function is_object;
use function is_string;
use function property_exists;

// Same optional orisaiNette.dic.containerLoader seam as Customs\EngineSource: no loader file (or any failure
// on the way to a bridge TemplateFactory) degrades to null, which drops the factory-default rung
// of the template-class resolution ladder.
//
// Two values come off the SAME single container load. The template class answers the resolution
// ladder's fallback rung; the WIRING answers the vendor's other injection condition, `$value !==
// null`: TemplateFactory's own $user/$httpRequest are nullable only because nette/security and
// nette/http integration are optional, so whether an application wires them is not readable from
// the constructor signature at all - only from the container it compiled. Both are live-container
// reads no analysed file reflects, so both belong to a cache envelope rather than a file hash (see
// PhpFactsCache::isFresh() for the facts half and LatteResultCacheMeta for the template-scope
// half).
final class TemplateFactoryDefaultResolver
{

	public const KEY_USER = 'user';

	public const KEY_HTTP_REQUEST = 'httpRequest';

	private const WIRING_KEYS = [self::KEY_USER, self::KEY_HTTP_REQUEST];

	private ?string $containerLoaderFile;

	private bool $resolved = false;

	private ?string $templateClass = null;

	/** @var array<string, class-string>|null */
	private ?array $wiring = null;

	public function __construct(?string $containerLoaderFile)
	{
		$this->containerLoaderFile = $containerLoaderFile;
	}

	public function resolve(): ?string
	{
		$this->load();

		return $this->templateClass;
	}

	// null is "there is no container to ask", which is NOT the same answer as an empty array ("the
	// container is there and wires neither"): the first must fall back to the conservative,
	// container-free verdict, the second is positive evidence that the dependency is absent.

	/**
	 * @return array<string, class-string>|null
	 */
	public function resolveWiring(): ?array
	{
		$this->load();

		return $this->wiring;
	}

	private function load(): void
	{
		if ($this->resolved) {
			return;
		}

		$this->resolved = true;

		foreach ($this->containers() as $container) {
			$factory = self::bridgeFactoryOf($container);
			if ($factory === null) {
				continue;
			}

			$templateClass = self::readProperty($factory, 'templateClass');
			$this->templateClass = is_string($templateClass) ? $templateClass : null;
			$this->wiring = self::readWiring($factory);

			return;
		}
	}

	/**
	 * @return list<Container>
	 */
	private function containers(): array
	{
		if ($this->containerLoaderFile === null || !is_file($this->containerLoaderFile)) {
			return [];
		}

		try {
			$loaded = require $this->containerLoaderFile;
		} catch (Throwable $e) {
			return [];
		}

		if ($loaded instanceof Container) {
			$loaded = ['default' => $loaded];
		}

		if (!is_array($loaded)) {
			return [];
		}

		$containers = [];
		foreach ($loaded as $container) {
			if ($container instanceof Container) {
				$containers[] = $container;
			}
		}

		return $containers;
	}

	private static function bridgeFactoryOf(Container $container): ?BridgeTemplateFactory
	{
		try {
			$factory = $container->getByType(TemplateFactory::class, false);
		} catch (Throwable $e) {
			return null;
		}

		return $factory instanceof BridgeTemplateFactory ? $factory : null;
	}

	/**
	 * @return array<string, class-string>
	 */
	private static function readWiring(BridgeTemplateFactory $factory): array
	{
		$wiring = [];
		foreach (self::WIRING_KEYS as $key) {
			$value = self::readProperty($factory, $key);
			if (is_object($value)) {
				$wiring[$key] = get_class($value);
			}
		}

		return $wiring;
	}

	/**
	 * @return mixed
	 */
	private static function readProperty(BridgeTemplateFactory $factory, string $name)
	{
		// A vendor upgrade renaming or dropping one of these degrades to "not wired", the
		// conservative direction - and breaks TemplateFactoryInjectionParityTest, which pins the
		// names against the installed vendor rather than letting the degradation pass silently.
		if (!property_exists(BridgeTemplateFactory::class, $name)) {
			return null;
		}

		$property = new ReflectionProperty(BridgeTemplateFactory::class, $name);
		$property->setAccessible(true);

		return $property->getValue($factory);
	}

}
