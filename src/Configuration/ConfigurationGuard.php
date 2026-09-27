<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Configuration;

use OriPhpstan\Nette\Forms\Catalog\Stub\FormModifierCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormReplicatorCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormRuleTypeCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormValueTypeCatalog;
use PHPStan\Reflection\ReflectionProvider;
use function in_array;
use function is_array;
use function is_file;
use function is_readable;
use function sprintf;
use function strtolower;

/**
 * @phpstan-type OrisaiNetteConfig array{
 *     forms: array{
 *         enabled: bool,
 *         defaultContainerClass: string,
 *         reportUnannotatedRegistrars: bool,
 *         catalogs: list<string>,
 *         internals: array{indexShadowCompare: bool},
 *     },
 *     component: array{enabled: bool},
 *     latte: array{
 *         enabled: bool,
 *         narrowing: array{enabled: bool, storePath: string},
 *         discovery: array{
 *             enabled: bool,
 *             storePath: string,
 *             coarseInvalidationAccepted: bool,
 *             formulas: array<string, string|array<string, string>>,
 *         },
 *         engineLoader: string|null,
 *         templateFactoryContainerLoader: string|null,
 *         firstPartyPaths: list<string>,
 *         templateTypeRequired: bool,
 *         includeIsolation: bool,
 *         allowNarrowingOverride: bool,
 *         reportWrongPhpDocTypeInVarType: bool,
 *         reportAnyTypeWideningInVarType: bool,
 *     },
 *     dic: array{containerLoader: string|null},
 * }
 */
final class ConfigurationGuard
{

	private const BUILT_IN_CATALOGS = [
		FormValueTypeCatalog::class,
		FormModifierCatalog::class,
		FormRuleTypeCatalog::class,
		FormReplicatorCatalog::class,
	];

	private const FORMULAS = [
		'vendor-two-candidate',
		'vendor-layout-walk',
		'samedir-single',
		'dirname-lcfirst',
		'dirname-templates-lcfirst',
		'dirname-templates-lcfirst-fallback',
		'dirname-property-lcfirst',
	];

	/** @var OrisaiNetteConfig */
	private array $config;

	/** @var list<string> */
	private array $fileExtensions;

	private ReflectionProvider $reflectionProvider;

	private bool $validated = false;

	/**
	 * @param OrisaiNetteConfig $orisaiNette
	 * @param list<string> $fileExtensions
	 */
	public function __construct(array $orisaiNette, array $fileExtensions, ReflectionProvider $reflectionProvider)
	{
		$this->config = $orisaiNette;
		$this->fileExtensions = $fileExtensions;
		$this->reflectionProvider = $reflectionProvider;
	}

	public function validate(): void
	{
		if ($this->validated) {
			return;
		}

		$latte = $this->config['latte'];
		if ($latte['enabled'] && !in_array('latte', $this->fileExtensions, true)) {
			throw new InvalidConfiguration('orisaiNette.latte.enabled requires "latte" in fileExtensions.');
		}

		if ($latte['narrowing']['enabled'] && !$latte['enabled']) {
			throw new InvalidConfiguration('orisaiNette.latte.narrowing.enabled requires orisaiNette.latte.enabled.');
		}

		if ($latte['discovery']['enabled'] && !$latte['enabled']) {
			throw new InvalidConfiguration('orisaiNette.latte.discovery.enabled requires orisaiNette.latte.enabled.');
		}

		$loaders = [
			'dic.containerLoader' => $this->config['dic']['containerLoader'],
			'latte.engineLoader' => $latte['engineLoader'],
			'latte.templateFactoryContainerLoader' => $latte['templateFactoryContainerLoader'],
		];
		foreach ($loaders as $key => $path) {
			if ($path !== null && (!is_file($path) || !is_readable($path))) {
				throw new InvalidConfiguration(sprintf('orisaiNette.%s "%s" is not a readable file.', $key, $path));
			}
		}

		foreach ($this->config['forms']['catalogs'] as $catalog) {
			if (!$this->reflectionProvider->hasClass($catalog)) {
				throw new InvalidConfiguration(
					sprintf('orisaiNette.forms.catalogs: class "%s" does not exist.', $catalog),
				);
			}
		}

		$this->validateCatalogMethods();

		foreach ($latte['discovery']['formulas'] as $class => $formula) {
			$name = is_array($formula) ? ($formula['formula'] ?? null) : $formula;
			if (!in_array($name, self::FORMULAS, true)) {
				throw new InvalidConfiguration(sprintf(
					'orisaiNette.latte.discovery.formulas: unknown formula "%s" for %s.',
					(string) $name,
					$class,
				));
			}
		}

		$this->validated = true;
	}

	private function validateCatalogMethods(): void
	{
		$declaredBy = [];
		foreach ([...self::BUILT_IN_CATALOGS, ...$this->config['forms']['catalogs']] as $catalogName) {
			if (!$this->reflectionProvider->hasClass($catalogName)) {
				continue;
			}

			$catalog = $this->reflectionProvider->getClass($catalogName);
			foreach ($catalog->getNativeReflection()->getMethods() as $method) {
				$name = strtolower($method->getName());
				if (isset($declaredBy[$name]) && $declaredBy[$name] !== $catalog->getName()) {
					throw new InvalidConfiguration(sprintf(
						'orisaiNette.forms.catalogs: method "%s" is declared by both %s and %s.',
						$method->getName(),
						$declaredBy[$name],
						$catalog->getName(),
					));
				}

				$declaredBy[$name] = $catalog->getName();
			}
		}
	}

	public function isFormsEnabled(): bool
	{
		return $this->config['forms']['enabled'];
	}

	public function isComponentEnabled(): bool
	{
		return $this->config['component']['enabled'];
	}

	public function isLatteEnabled(): bool
	{
		return $this->config['latte']['enabled'];
	}

	public function isLatteNarrowingEnabled(): bool
	{
		return $this->isLatteEnabled() && $this->config['latte']['narrowing']['enabled'];
	}

	public function isLatteDiscoveryEnabled(): bool
	{
		return $this->isLatteEnabled() && $this->config['latte']['discovery']['enabled'];
	}

	public function isDicEnabled(): bool
	{
		return $this->config['dic']['containerLoader'] !== null;
	}

	public function isBridgeEnabled(): bool
	{
		return $this->isFormsEnabled() && $this->isLatteEnabled();
	}

}
