<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Configuration;

use Nette\Schema\Context;
use Nette\Schema\Elements\Structure;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\ValidationException;
use function array_key_exists;
use function explode;
use function is_array;
use function sprintf;

/**
 * @phpstan-type NetteConfig array{
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
final class Configuration
{

	private const PATH = ['parameters', 'orisai', 'nette'];

	/** @var NetteConfig */
	private array $config;

	/**
	 * @param array<mixed> $config
	 */
	public function __construct(array $config)
	{
		$this->config = self::validate($config);
	}

	public static function schema(): Structure
	{
		return Expect::structure([
			'forms' => Expect::structure([
				'enabled' => Expect::bool()->required(),
				'defaultContainerClass' => Expect::string()->required(),
				'reportUnannotatedRegistrars' => Expect::bool()->required(),
				'catalogs' => Expect::listOf(Expect::string()->required())->required(),
				'internals' => Expect::structure([
					'indexShadowCompare' => Expect::bool()->required(),
				])->required(),
			])->required(),
			'component' => Expect::structure([
				'enabled' => Expect::bool()->required(),
			])->required(),
			'latte' => Expect::structure([
				'enabled' => Expect::bool()->required(),
				'narrowing' => Expect::structure([
					'enabled' => Expect::bool()->required(),
					'storePath' => Expect::string()->required(),
				])->required(),
				'discovery' => Expect::structure([
					'enabled' => Expect::bool()->required(),
					'storePath' => Expect::string()->required(),
					'coarseInvalidationAccepted' => Expect::bool()->required(),
					'formulas' => Expect::arrayOf(
						Expect::anyOf(
							Expect::string()->required(),
							Expect::arrayOf(
								Expect::string()->required(),
								Expect::string()->required(),
							)->required(),
						)->required(),
						Expect::string()->required(),
					)->required(),
				])->required(),
				'engineLoader' => Expect::string()->nullable()->required(),
				'templateFactoryContainerLoader' => Expect::string()->nullable()->required(),
				'firstPartyPaths' => Expect::listOf(Expect::string()->required())->required(),
				'templateTypeRequired' => Expect::bool()->required(),
				'includeIsolation' => Expect::bool()->required(),
				'allowNarrowingOverride' => Expect::bool()->required(),
				'reportWrongPhpDocTypeInVarType' => Expect::bool()->required(),
				'reportAnyTypeWideningInVarType' => Expect::bool()->required(),
			])->required(),
			'dic' => Expect::structure([
				'containerLoader' => Expect::string()->nullable()->required(),
			])->required(),
		])->required();
	}

	/**
	 * @return mixed
	 */
	public function get(string $path)
	{
		$value = $this->config;
		foreach (explode('.', $path) as $key) {
			if (!is_array($value) || !array_key_exists($key, $value)) {
				throw new InvalidConfiguration(sprintf('orisai.nette.%s is not a configuration option.', $path));
			}

			$value = $value[$key];
		}

		return $value;
	}

	/**
	 * @return NetteConfig
	 */
	public function toArray(): array
	{
		return $this->config;
	}

	/**
	 * @param array<mixed> $config
	 * @return NetteConfig
	 */
	private static function validate(array $config): array
	{
		$processor = new Processor();
		$processor->onNewContext[] = static function (Context $context): void {
			$context->path = self::PATH;
		};

		try {
			$processor->process(self::schema(), $config);
		} catch (ValidationException $exception) {
			throw new InvalidConfiguration($exception->getMessage());
		}

		/** @var NetteConfig $config */
		return $config;
	}

}
