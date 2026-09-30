<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Configuration;

use Latte\Engine;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormModifierCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormReplicatorCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormRuleTypeCatalog;
use OriPhpstan\Nette\Forms\Catalog\Stub\FormValueTypeCatalog;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use PHPStan\Reflection\ReflectionProvider;
use function array_keys;
use function in_array;
use function is_array;
use function is_file;
use function is_readable;
use function sprintf;
use function strtolower;
use function version_compare;

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

	private const FORMULA_OPTIONS = ['formula', 'sharedFallback', 'nameProperty'];

	private const REQUIRED_FORMULA_OPTIONS = [
		'dirname-templates-lcfirst-fallback' => 'sharedFallback',
		'dirname-property-lcfirst' => 'nameProperty',
	];

	/** @var OrisaiNetteConfig */
	private array $config;

	/** @var list<string> */
	private array $fileExtensions;

	private ReflectionProvider $reflectionProvider;

	private ProjectInstalledVersions $installedVersions;

	private bool $validated = false;

	/**
	 * @param OrisaiNetteConfig $orisaiNette
	 * @param list<string> $fileExtensions
	 */
	public function __construct(
		array $orisaiNette,
		array $fileExtensions,
		ReflectionProvider $reflectionProvider,
		ProjectInstalledVersions $installedVersions
	)
	{
		$this->config = $orisaiNette;
		$this->fileExtensions = $fileExtensions;
		$this->reflectionProvider = $reflectionProvider;
		$this->installedVersions = $installedVersions;
	}

	public function validate(): void
	{
		if ($this->validated) {
			return;
		}

		$latte = $this->config['latte'];
		if ($latte['enabled'] && !in_array('latte', $this->fileExtensions, true)) {
			throw new InvalidConfiguration('orisai.nette.latte.enabled requires "latte" in fileExtensions.');
		}

		if ($latte['narrowing']['enabled'] && !$latte['enabled']) {
			throw new InvalidConfiguration('orisai.nette.latte.narrowing.enabled requires orisai.nette.latte.enabled.');
		}

		if ($latte['enabled']) {
			$this->validateLatteVersionPairs();
		}

		$loaders = [
			'dic.containerLoader' => $this->config['dic']['containerLoader'],
			'latte.engineLoader' => $latte['engineLoader'],
			'latte.templateFactoryContainerLoader' => $latte['templateFactoryContainerLoader'],
		];
		foreach ($loaders as $key => $path) {
			if ($path !== null && (!is_file($path) || !is_readable($path))) {
				throw new InvalidConfiguration(sprintf('orisai.nette.%s "%s" is not a readable file.', $key, $path));
			}
		}

		foreach ($this->config['forms']['catalogs'] as $catalog) {
			if (!$this->reflectionProvider->hasClass($catalog)) {
				throw new InvalidConfiguration(
					sprintf('orisai.nette.forms.catalogs: class "%s" does not exist.', $catalog),
				);
			}
		}

		$this->validateCatalogMethods();

		foreach ($latte['discovery']['formulas'] as $class => $formula) {
			$options = is_array($formula) ? $formula : ['formula' => $formula];
			$name = $options['formula'] ?? null;
			if (!in_array($name, self::FORMULAS, true)) {
				throw new InvalidConfiguration(sprintf(
					'orisai.nette.latte.discovery.formulas: unknown formula "%s" for %s.',
					(string) $name,
					$class,
				));
			}

			foreach (array_keys($options) as $option) {
				if (!in_array($option, self::FORMULA_OPTIONS, true)) {
					throw new InvalidConfiguration(sprintf(
						'orisai.nette.latte.discovery.formulas: unknown option "%s" for %s.',
						$option,
						$class,
					));
				}
			}

			$required = self::REQUIRED_FORMULA_OPTIONS[$name] ?? null;
			if ($required !== null && !isset($options[$required])) {
				throw new InvalidConfiguration(sprintf(
					'orisai.nette.latte.discovery.formulas: formula "%s" for %s requires option "%s".',
					$name,
					$class,
					$required,
				));
			}
		}

		$this->validated = true;
	}

	// UIExtension exists from nette/application 3.1.6 and FormsExtension from nette/forms 3.1.7;
	// application 3.2.0-3.2.6 and forms 3.2.0-3.2.6 declare a conflict with Latte 3.1.
	private function validateLatteVersionPairs(): void
	{
		$latte = $this->installedVersions->getVersion('latte/latte') ?? Engine::VERSION;
		if (!ShapeFamily::supports($latte)) {
			throw new InvalidConfiguration(sprintf(
				'orisai.nette.latte.enabled requires a supported latte/latte version (2.11, 3.0 or 3.1); installed %s.',
				$this->prettyVersion('latte/latte') ?? $latte,
			));
		}

		if (self::isBelow($latte, '3.0.0')) {
			return;
		}

		$forms = $this->installedVersions->getVersion('nette/forms');
		$application = $this->installedVersions->getVersion('nette/application');

		if ($forms !== null && self::isBelow($forms, '3.1.7')) {
			throw new InvalidConfiguration(
				sprintf(
					'Latte 3 requires nette/forms >= 3.1.7 (FormsExtension); installed %s.',
					$this->prettyVersion('nette/forms') ?? $forms,
				),
			);
		}

		if ($application !== null && self::isBelow($application, '3.1.6')) {
			throw new InvalidConfiguration(
				sprintf(
					'Latte 3 requires nette/application >= 3.1.6 (UIExtension); installed %s.',
					$this->prettyVersion('nette/application') ?? $application,
				),
			);
		}

		if (
			!self::isBelow($latte, '3.1.0')
			&& (
				($forms !== null && self::isBelow($forms, '3.2.7'))
				|| ($application !== null && self::isBelow($application, '3.2.7'))
			)
		) {
			throw new InvalidConfiguration(sprintf(
				'Latte 3.1 requires nette/forms >= 3.2.7 and nette/application >= 3.2.7; installed %s/%s.',
				$this->prettyVersion('nette/forms') ?? $forms ?? 'none',
				$this->prettyVersion('nette/application') ?? $application ?? 'none',
			));
		}
	}

	private function prettyVersion(string $package): ?string
	{
		return $this->installedVersions->getPrettyVersion($package);
	}

	private static function isBelow(string $version, string $minimum): bool
	{
		return version_compare($version, $minimum) < 0;
	}

	private function validateCatalogMethods(): void
	{
		if ($this->config['forms']['catalogs'] === []) {
			return;
		}

		// Inherited methods count too, so a user catalog extending a built-in one is rejected as a duplicate.
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
						'orisai.nette.forms.catalogs: method "%s" is declared by both %s and %s.',
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

	public function hasTemplateFactoryContainerLoader(): bool
	{
		return $this->config['latte']['templateFactoryContainerLoader'] !== null;
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
