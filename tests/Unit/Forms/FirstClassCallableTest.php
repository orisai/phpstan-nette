<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Analyzer\FormShapeAnalyzer;
use OriPhpstan\Nette\Forms\Analyzer\MappedTypeDetector;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Component\FormOriginTracer;
use OriPhpstan\Nette\Forms\Graph\ComponentAffectingNodeVisitor;
use OriPhpstan\Nette\Forms\Graph\ExistenceCheckMarkingNodeVisitor;
use OriPhpstan\Nette\Forms\Graph\FirstClassCallableDetector;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\NodeId;
use OriPhpstan\Nette\Forms\Graph\StructuralContext;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use OriPhpstan\Nette\Forms\Graph\VariableEscapeDetector;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use OriPhpstan\Nette\Forms\Inference\EnclosingFunctionLikeLocator;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeTraverser;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ShapeSnapshotAssertions;
use function assert;
use function dirname;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * First-class callables (`$form->addText(...)`) carry a VariadicPlaceholder in `->args`;
 * `getArgs()`'s assert is disabled under PHPStan, so the placeholder leaks through as a fake
 * Arg. These pins hold the machinery's contract: an FCC on a tracked form/container/control
 * receiver is an ESCAPE (shape/slot degrades to OPEN through the existing escape reasons),
 * and pure pattern-matchers treat an FCC as a no-match. All nodes are built synthetically —
 * no 8.1 syntax is parsed anywhere.
 */
final class FirstClassCallableTest extends TypeInferenceTestCase
{

	use ShapeSnapshotAssertions;

	private const FORM_CLASS = 'Nette\Forms\Form';

	private string $cacheDir;

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [__DIR__ . '/Inference/phpstan-test.neon'];
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->cacheDir = sys_get_temp_dir() . '/fcc-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->cacheDir)) {
			FileSystem::delete($this->cacheDir);
		}
	}

	private static function fcc(string $receiverVar, string $method): MethodCall
	{
		return new MethodCall(
			new Variable($receiverVar),
			new Identifier($method),
			[new VariadicPlaceholder()],
		);
	}

	private static function formParam(string $name): Param
	{
		return new Param(new Variable($name), null, new Name(self::FORM_CLASS));
	}

	/**
	 * @param callable(Scope): void $body
	 */
	private function withScope(callable $body): void
	{
		$ran = false;
		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/Summary.php',
			static function (Node $node, Scope $scope) use ($body, &$ran): void {
				if ($ran || !$node instanceof Expression) {
					return;
				}

				$ran = true;
				$body($scope);
			},
		);

		self::assertTrue($ran);
	}

	private function summaryFactory(): NodeContributionSummaryFactory
	{
		return new NodeContributionSummaryFactory(new NetteEffectiveControlValueTypeResolver(
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			$this->callees(),
		));
	}

	private static function addTag(Node $node, string $operationKind): TaggedNode
	{
		$tagged = new TaggedNode(NodeId::fromPath('', []), $operationKind, new StructuralContext([]));
		$node->setAttribute(TaggedNode::ATTRIBUTE, $tagged);

		return $tagged;
	}

	public function testPremiseVariadicPlaceholderMakesFirstClassCallable(): void
	{
		self::assertTrue(self::fcc('form', 'addText')->isFirstClassCallable());
		self::assertFalse(
			(new MethodCall(new Variable('form'), new Identifier('addText'), [new Arg(new String_('x'))]))
				->isFirstClassCallable(),
		);
	}

	public function testDetectorFindsFccInSubtree(): void
	{
		$stmt = new Expression(new Assign(new Variable('cb'), self::fcc('form', 'addText')));
		self::assertTrue(FirstClassCallableDetector::inSubtree($stmt));

		$plain = new Expression(
			new MethodCall(new Variable('form'), new Identifier('addText'), [new Arg(new String_('x'))]),
		);
		self::assertFalse(FirstClassCallableDetector::inSubtree($plain));
	}

	public function testDetectorMatchesOnlyTrackedReceiverRoots(): void
	{
		self::assertTrue(FirstClassCallableDetector::onTrackedReceiver(
			new Expression(self::fcc('form', 'setDefaults')),
			'form',
		));
		self::assertTrue(FirstClassCallableDetector::onTrackedReceiver(
			new Expression(new Assign(new Variable('cb'), self::fcc('form', 'setDefaults'))),
			'form',
		));

		$offsetReceiver = new MethodCall(
			new ArrayDimFetch(new Variable('form'), new String_('a')),
			new Identifier('setValue'),
			[new VariadicPlaceholder()],
		);
		self::assertTrue(FirstClassCallableDetector::onTrackedReceiver(new Expression($offsetReceiver), 'form'));

		self::assertFalse(FirstClassCallableDetector::onTrackedReceiver(
			new Expression(self::fcc('other', 'setDefaults')),
			'form',
		));
		self::assertFalse(FirstClassCallableDetector::onTrackedReceiver(
			new Expression(new StaticCall(new Name('Helper'), new Identifier('run'), [new VariadicPlaceholder()])),
			'form',
		));
	}

	public function testVariableEscapeDetectorTreatsFccAsNoArgumentPass(): void
	{
		$stmt = new Expression(self::fcc('this', 'helper'));

		self::assertFalse(VariableEscapeDetector::passedAsDirectArgument($stmt, 'form'));
	}

	public function testMappedTypeDetectorTreatsFccAsNoMatch(): void
	{
		$stmt = new Expression(self::fcc('form', 'setMappedType'));

		self::assertNull(MappedTypeDetector::detect([$stmt], 'form'));
		self::assertNull(MappedTypeDetector::detectTopLevel([$stmt], 'form'));
	}

	public function testExistenceCheckVisitorIgnoresFccRemoveComponent(): void
	{
		$visitor = new ExistenceCheckMarkingNodeVisitor();

		self::assertNull($visitor->enterNode(self::fcc('form', 'removeComponent')));

		$access = new ArrayDimFetch(new Variable('form'), new String_('x'));
		$plain = new MethodCall(new Variable('form'), new Identifier('removeComponent'), [new Arg($access)]);
		$visitor->enterNode($plain);
		self::assertTrue((bool) $access->getAttribute(ExistenceCheckMarkingNodeVisitor::ATTRIBUTE));
	}

	public function testRegistrationRecognizerSkipsFccPassThrough(): void
	{
		$fccMethod = new ClassMethod('build', [
			'params' => [self::formParam('f')],
			'stmts' => [new Expression(self::fcc('this', 'helper'))],
		]);

		self::assertSame([], (new RegistrationRecognizer())->passThroughEdges($fccMethod, 'C'));

		$plainMethod = new ClassMethod('build', [
			'params' => [self::formParam('f')],
			'stmts' => [
				new Expression(new MethodCall(
					new Variable('this'),
					new Identifier('helper'),
					[new Arg(new Variable('f'))],
				)),
			],
		]);

		self::assertCount(1, (new RegistrationRecognizer())->passThroughEdges($plainMethod, 'C'));
	}

	public function testOriginTracerChainBailsOnFcc(): void
	{
		$helper = new ClassMethod('decorate', ['params' => [self::formParam('f')]]);

		$fccFactory = new ClassMethod('createComponentFoo', [
			'stmts' => [
				new Expression(self::fcc('this', 'decorate')),
				new Return_(new Variable('form')),
			],
		]);
		$fccClass = new Class_(new Identifier('C'), ['stmts' => [$fccFactory, $helper]]);

		self::assertNull((new FormOriginTracer())->originChainKey($fccClass, $helper, 'f'));

		$plainFactory = new ClassMethod('createComponentFoo', [
			'stmts' => [
				new Expression(new MethodCall(
					new Variable('this'),
					new Identifier('decorate'),
					[new Arg(new Variable('form'))],
				)),
				new Return_(new Variable('form')),
			],
		]);
		$plainClass = new Class_(new Identifier('C'), ['stmts' => [$plainFactory, $helper]]);

		self::assertSame('foo', (new FormOriginTracer())->originChainKey($plainClass, $helper, 'f'));
	}

	public function testContainerModelTreatsFccAsNoComponentAccess(): void
	{
		$model = self::getContainer()->getByType(ContainerModel::class);

		$this->withScope(static function (Scope $scope) use ($model): void {
			self::assertNull($model->resolveControlValueType(self::fcc('form', 'getComponent'), $scope));
			self::assertNull($model->resolveControlValueType(self::fcc('form', 'offsetGet'), $scope));
		});
	}

	public function testSummaryFactoryOpensFccTaggedAdd(): void
	{
		$factory = $this->summaryFactory();

		$this->withScope(static function (Scope $scope) use ($factory): void {
			$fcc = self::fcc('form', 'addText');
			$tagged = self::addTag($fcc, 'addText');

			$summary = $factory->fromTaggedNode(new Expression($fcc), $fcc, $tagged, $scope);

			self::assertSame(NodeContributionSummary::OP_ADD, $summary->getOp());
			self::assertNull($summary->getName());
			self::assertTrue($summary->hasDynamicName());
			self::assertNull($summary->getResolution());
			self::assertSame([UnknownReason::FIRST_CLASS_CALLABLE], $summary->getNodeUnknownReasons());
		});
	}

	public function testFccChainModifierEscapesControl(): void
	{
		$factory = $this->summaryFactory();

		$this->withScope(static function (Scope $scope) use ($factory): void {
			$add = new MethodCall(new Variable('form'), new Identifier('addText'), [new Arg(new String_('x'))]);
			$tagged = self::addTag($add, 'addText');
			$tip = new MethodCall($add, new Identifier('setNullable'), [new VariadicPlaceholder()]);

			$summary = $factory->fromTaggedNode(
				new Expression($tip),
				$add,
				$tagged,
				$scope,
				'form',
				self::FORM_CLASS,
			);

			self::assertSame('x', $summary->getName());
			$resolution = $summary->getResolution();
			self::assertNotNull($resolution);
			self::assertSame(ControlValueResolution::KIND_UNKNOWN_TYPE, $resolution->getKind());
			self::assertSame([UnknownReason::FORM_ALIASED], $resolution->getUnknownReasons());
		});
	}

	public function testWalkOpensShapeOnFccOnTrackedReceiver(): void
	{
		$analyzer = $this->analyzer();

		$this->withScope(static function (Scope $scope) use ($analyzer): void {
			// distinct startLine attributes: the walk memo keys on the function-like position
			$fccMethod = new ClassMethod('build', [
				'params' => [self::formParam('f')],
				'stmts' => [new Expression(self::fcc('f', 'setDefaults'))],
			], ['startLine' => 10]);

			$shape = $analyzer->analyzeContainerParam('f', $fccMethod, self::FORM_CLASS, [], $scope);
			self::assertShape($shape, <<<'OUTPUT'
				Nette\Forms\Form{
				  ...<IComponent>,
				}
				OUTPUT);
			// The render shows the shape is open but not which reason opened it, and the reason is the
			// point of this test: the FCC escape, not some incidental unknown.
			self::assertContains(UnknownReason::FIRST_CLASS_CALLABLE, $shape->getUnknown()->getReasons());

			$plainMethod = new ClassMethod('build', [
				'params' => [self::formParam('f')],
				'stmts' => [
					new Expression(new MethodCall(
						new Variable('f'),
						new Identifier('setDefaults'),
						[new Arg(new Array_([]))],
					)),
				],
			], ['startLine' => 20]);

			$plainShape = $analyzer->analyzeContainerParam('f', $plainMethod, self::FORM_CLASS, [], $scope);
			self::assertShape($plainShape, <<<'OUTPUT'
				Nette\Forms\Form{}
				OUTPUT, 'the same call spelled without the placeholder leaves the shape closed');
		});
	}

	public function testFccModifierOnBoundControlVariableOpensSlot(): void
	{
		$analyzer = $this->analyzer();

		$this->withScope(function (Scope $scope) use ($analyzer): void {
			$fccMethod = $this->boundControlMethod(new Expression(self::fcc('c', 'setNullable')), 40);
			$shape = $analyzer->analyzeContainerParam(
				'f',
				$fccMethod,
				self::FORM_CLASS,
				$this->tagAndCollect($fccMethod, $scope),
				$scope,
			);
			self::assertShape($shape, <<<'OUTPUT'
				Nette\Forms\Form{
				  x: Nette\Forms\Controls\TextInput<*any*, *unknown*>,
				  ...<IComponent>,
				}
				OUTPUT);
			// The render shows the shape is open but not which reason opened it, and the reason is the
			// point of this test: the bound control escaped, not some incidental unknown.
			self::assertContains(UnknownReason::FORM_ALIASED, $shape->getUnknown()->getReasons());

			$plainMethod = $this->boundControlMethod(
				new Expression(new MethodCall(new Variable('c'), new Identifier('setNullable'))),
				50,
			);
			$plainShape = $analyzer->analyzeContainerParam(
				'f',
				$plainMethod,
				self::FORM_CLASS,
				$this->tagAndCollect($plainMethod, $scope),
				$scope,
			);
			self::assertShape($plainShape, <<<'OUTPUT'
				Nette\Forms\Form{
				  x: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, non-empty-string|null>,
				}
				OUTPUT, 'the same modifier spelled without the placeholder leaves the slot intact');
		});
	}

	private function boundControlMethod(Expression $modifierStmt, int $startLine): ClassMethod
	{
		return new ClassMethod('build', [
			'params' => [self::formParam('f')],
			'stmts' => [
				new Expression(new Assign(
					new Variable('c'),
					new MethodCall(new Variable('f'), new Identifier('addText'), [new Arg(new String_('x'))]),
				)),
				$modifierStmt,
			],
		], ['startLine' => $startLine]);
	}

	/**
	 * @return list<array{node: Node, stmt: Node, scope: Scope, tagged: TaggedNode}>
	 */
	private function tagAndCollect(ClassMethod $method, Scope $scope): array
	{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ComponentAffectingNodeVisitor());
		$traverser->traverse([$method]);

		return (new EnclosingFunctionLikeLocator())->taggedRecords($method, $scope);
	}

	public function testFccOfByRefClosureVarEscapesInsteadOfAbsorbing(): void
	{
		$analyzer = $this->analyzer();

		$this->withScope(static function (Scope $scope) use ($analyzer): void {
			$method = new ClassMethod('build', [
				'params' => [self::formParam('f')],
				'stmts' => [
					new Expression(new Assign(
						new Variable('build'),
						new Closure([
							'uses' => [new ClosureUse(new Variable('f'), true)],
							'stmts' => [
								new Expression(new MethodCall(
									new Variable('f'),
									new Identifier('addText'),
									[new Arg(new String_('x'))],
								)),
							],
						]),
					)),
					new Expression(new FuncCall(new Variable('build'), [new VariadicPlaceholder()])),
				],
			]);

			$traverser = new NodeTraverser();
			$traverser->addVisitor(new ComponentAffectingNodeVisitor());
			$traverser->traverse([$method]);
			$records = (new EnclosingFunctionLikeLocator())->taggedRecords($method, $scope);

			$shape = $analyzer->analyzeContainerParam('f', $method, self::FORM_CLASS, $records, $scope);

			self::assertShape($shape, <<<'OUTPUT'
				Nette\Forms\Form{
				  ...<IComponent>,
				}
				OUTPUT, 'the by-ref closure escapes instead of absorbing, so x is never claimed');
			// The render shows the shape is open but not which reason opened it, and the reason is the
			// point of this test: the escape, not some incidental unknown.
			self::assertContains(UnknownReason::FORM_ALIASED, $shape->getUnknown()->getReasons());
		});
	}

	private function analyzer(): FormShapeAnalyzer
	{
		return new FormShapeAnalyzer(
			$this->summaryFactory(),
			new FormShapeCache($this->cacheDir),
			$this->callees(),
		);
	}

	private function callees(): CalleeShapeResolver
	{
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);

		return new CalleeShapeResolver(
			$parser,
			self::createReflectionProvider(),
			new FormShapeCache($this->cacheDir),
		);
	}

}
