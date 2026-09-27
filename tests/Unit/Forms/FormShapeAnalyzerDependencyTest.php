<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use function array_keys;
use function glob;
use function implode;
use function is_dir;
use function strpos;
use function sys_get_temp_dir;
use function uniqid;
use function unserialize;

final class FormShapeAnalyzerDependencyTest extends FormShapeTestCase
{

	private const USAGE_FILE = __DIR__ . '/Support/GapThreeFormUsage.php';

	private const FACTORY_BASENAME = 'GapThreeFormFactory.php';

	private string $cacheDir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->cacheDir = sys_get_temp_dir() . '/gap3-dep-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}
	}

	public function testScopeFreeTrackedClassResolutionFileIsRecordedAsDependency(): void
	{
		$functionLikeNode = null;
		$formExpr = null;
		$scope = null;

		self::processFile(
			self::USAGE_FILE,
			static function (Node $node, Scope $nodeScope) use (&$functionLikeNode, &$formExpr, &$scope): void {
				if ($node instanceof ClassMethod && $node->name->toString() === 'build') {
					$functionLikeNode = $node;
				}

				if (
					$node instanceof MethodCall
					&& $node->name instanceof Identifier
					&& $node->name->toString() === 'addText'
				) {
					$formExpr = $node->var;
					$scope = $nodeScope;
				}
			},
		);

		self::assertNotNull($functionLikeNode);
		self::assertNotNull($formExpr);
		self::assertNotNull($scope);

		$cache = new FormShapeCache($this->cacheDir);
		$analyzer = new FormShapeAnalyzer(
			new NodeContributionSummaryFactory($this->createCatalog($cache), $cache->recorder()),
			$cache,
			$this->createCallees($cache),
		);
		$records = (new EnclosingFunctionLikeLocator())->taggedRecords($functionLikeNode, $scope);

		$shape = $analyzer->analyzeFormValue(
			$formExpr,
			$functionLikeNode,
			$records,
			$scope,
			self::USAGE_FILE,
			null,
			true,
		);

		self::assertSame('Tests\OriPhpstan\Nette\Unit\Forms\Support\GapThreeForm', $shape->getClassName());

		$deps = $this->soleCachedEntryDependencies();
		$hasFactoryDependency = false;
		foreach (array_keys($deps) as $file) {
			if (strpos($file, self::FACTORY_BASENAME) !== false) {
				$hasFactoryDependency = true;

				break;
			}
		}

		self::assertTrue(
			$hasFactoryDependency,
			'the scope-free tracked-class reflection read must be recorded as a dependency; got: '
				. implode(', ', array_keys($deps)),
		);
	}

	/** @return array<string, string> */
	private function soleCachedEntryDependencies(): array
	{
		$matches = glob($this->cacheDir . '/v*/*.ser');
		self::assertNotFalse($matches);
		self::assertCount(1, $matches);

		$entry = unserialize(FileSystem::read($matches[0]));
		self::assertIsArray($entry);
		self::assertArrayHasKey('d', $entry);
		self::assertIsArray($entry['d']);

		/** @var array<string, string> $deps */
		$deps = $entry['d'];

		return $deps;
	}

}
