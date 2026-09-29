<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Parser;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Includes\ProviderAvailabilityChecker;
use OriPhpstan\Nette\Latte\Parser\LatteRoutingParser;
use OriPhpstan\Nette\Latte\Runtime\Diag;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery\Fixtures\FixtureRecordSourceContainer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsControlRenderer;
use Tests\OriPhpstan\Nette\Unit\Latte\Includes\Fixtures\App\FactoryVarsStandaloneControl;
use function array_map;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

// End-to-end through the REAL wiring path (LatteRoutingParser -> AnalysisPipeline ->
// ProviderMacroScanner -> ProviderAvailabilityChecker -> Diagnostic -> DiagnosticMaterializer's
// Diag::report(), the same channel LatteDiagnosticRule turns into a reported RuleError).
// ProviderAvailabilityCheckerTest already pins the guard's own four silent cases directly against
// the checker; this file only proves the wiring actually carries a finding through to that point.
final class LatteRoutingParserProviderAvailabilityTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const AppFixtureDir = __DIR__ . '/../Includes/Fixtures/App';

	private const TemplateRel = 'page.latte';

	public function testControlNoneRendererReportsAControlMacro(): void
	{
		$stmts = $this->parse("{control foo}\n", [FactoryVarsStandaloneControl::class]);

		self::assertSame(['orisaiNette.latte.providerUnavailable'], $this->reportedIdentifiers($stmts));
	}

	// CONTROL_SELF (this renderer's own instance really reaches the factory, inheriting the vendor
	// Control::createTemplate() body) - the guard stays silent through the FULL wiring path, not
	// just against the checker directly.
	public function testControlSelfRendererStaysSilent(): void
	{
		$stmts = $this->parse("{control foo}\n", [FactoryVarsControlRenderer::class]);

		self::assertSame([], $this->reportedIdentifiers($stmts));
	}

	public function testTemplateWithNoProviderMacroStaysSilentEvenWhenControlNone(): void
	{
		$stmts = $this->parse("<p>{\$x}</p>\n", [FactoryVarsStandaloneControl::class]);

		self::assertSame([], $this->reportedIdentifiers($stmts));
	}

	/**
	 * @param list<string> $rendererClasses
	 * @return array<Stmt>
	 */
	private function parse(string $latte, array $rendererClasses): array
	{
		$dir = $this->scratchDir();

		try {
			$root = $dir . '/templates';
			FileSystem::write($root . '/' . self::TemplateRel, $latte);

			$checker = new ProviderAvailabilityChecker(
				new FixtureRecordSourceContainer($this->recordSource($dir)),
				$this->store($dir, $rendererClasses),
				true,
			);

			$contextResolver = PipelineFactory::createContextResolver($root);

			$parser = new LatteRoutingParser(
				$this->createMock(Parser::class),
				TestAdapter::accessor(),
				PipelineFactory::create($root, null, false),
				$contextResolver,
				PipelineFactory::createIncludeContractChecker($root, $contextResolver, null, false),
				PipelineFactory::createDeclarationConsistencyChecker($root),
				PipelineFactory::createTemplateEdgeIndex($root),
				PipelineFactory::createSiteScopeStore($root),
				PipelineFactory::createRichAttributeDecorator(),
				$root,
				true,
				false,
				null,
				null,
				$checker,
			);

			return $parser->parseFile($root . '/' . self::TemplateRel);
		} finally {
			FileSystem::delete($dir);
		}
	}

	/**
	 * @param array<Stmt> $stmts
	 * @return list<string>
	 */
	private function reportedIdentifiers(array $stmts): array
	{
		$identifiers = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, StaticCall::class) as $call) {
			if (
				!$call->class instanceof Name
				|| $call->class->toString() !== Diag::class
				|| !$call->name instanceof Identifier
				|| $call->name->toString() !== 'report'
				|| !isset($call->args[0])
				|| !$call->args[0] instanceof Arg
				|| !$call->args[0]->value instanceof String_
			) {
				continue;
			}

			$identifiers[] = $call->args[0]->value->value;
		}

		return $identifiers;
	}

	/**
	 * @param list<string> $rendererClasses
	 */
	private function store(string $dir, array $rendererClasses): DiscoveryStore
	{
		$store = new DiscoveryStore($dir . '/store');
		$store->replaceWith(
			[
				self::TemplateRel => array_map(
					static fn (string $className): array => [
						'class' => $className,
						'view' => 'default',
						'kind' => CandidatePath::KIND_FORMULA,
						'certainty' => Certainty::HAPPENS,
					],
					$rendererClasses,
				),
			],
			$rendererClasses,
			[],
		);

		return $store;
	}

	private function recordSource(string $dir): DiscoveryRecordSource
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$appRoot = realpath(self::AppFixtureDir);
		self::assertNotFalse($appRoot);

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(null, [], $appRoot);

		return new DiscoveryRecordSource(
			new PhpFactsCache(new LatteAnalysisCache($dir . '/cache'), $templateFactoryDefault, $discoveryResolver, []),
			new PhpRenderWalk(
				self::createReflectionProvider(),
				$parser,
				[$appRoot],
				$templateFactoryDefault,
				$discoveryResolver,
			),
		);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-provider-availability-integration-' . getmypid() . '-' . uniqid('', true);
	}

}
