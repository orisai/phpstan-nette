<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Parser\CleaningParser;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use Tests\OriPhpstan\Nette\Toolkit\FormShapeTestCase;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\BodyVisibilityHelpers;
use function assert;
use function is_dir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The load-bearing separation behind accepting an empty contribution as proof that a callee adds
 * nothing: a body that could not be read must never be able to pass for a body that was read and
 * found to register nothing.
 *
 * The hazard is concrete rather than theoretical. PathRoutingParser hands every file outside
 * PHPStan's CLI-narrowed analysed set to CleaningParser, and an app helper called from a builder is
 * routinely such a file. CleaningParser rewrites method bodies, so wiring the wrong parser used to
 * mean "adds nothing" would be concluded about a helper that adds plenty — the exact false
 * ABSENT this feature exists to avoid. The proof lives in the resolver rather than in the wiring,
 * which is why these tests inject the stripping parser deliberately.
 */
final class CalleeBodyVisibilityTest extends FormShapeTestCase
{

	private const FIXTURE = __DIR__ . '/Support/BodyVisibilityHelpers.php';

	/** @var list<string> */
	private array $dirs = [];

	protected function tearDown(): void
	{
		parent::tearDown();
		foreach ($this->dirs as $dir) {
			if (is_dir($dir)) {
				FileSystem::delete($dir);
			}
		}
	}

	/**
	 * The hazard itself, made visible. To a stripping parser the helper that registers a control and
	 * the helper with no body at all have the SAME body, so nothing downstream of the parse can tell
	 * them apart — which is why the check reads the source rather than the statement list.
	 */
	public function testAStrippedBodyIsIndistinguishableFromAnEmptyOneInTheAstAlone(): void
	{
		$stripping = $this->strippingParser();

		self::assertSame([], $this->methodNode($stripping, 'adds')->stmts);
		self::assertSame([], $this->methodNode($stripping, 'addsNothing')->stmts);
		self::assertSame([], $this->methodNode($stripping, 'emptyBody')->stmts);

		// and to the rich parser they are not
		self::assertCount(1, $this->methodNode($this->createRichParser(), 'adds')->stmts ?? []);
		self::assertSame([], $this->methodNode($this->createRichParser(), 'emptyBody')->stmts);
	}

	public function testTheRichParserResolvesEveryReadableCallee(): void
	{
		$resolver = $this->resolverWith($this->createRichParser());

		self::assertNotNull($this->resolve($resolver, 'adds'));
		self::assertNotNull($this->resolve($resolver, 'addsNothing'));
		self::assertNotNull($this->resolve($resolver, 'emptyBody'));
		self::assertNotNull($this->resolve($resolver, 'closureOnly'));
	}

	public function testAStrippingParserCannotPassOffARewrittenBodyAsAnEmptyOne(): void
	{
		$resolver = $this->resolverWith($this->strippingParser());

		self::assertNull(
			$this->resolve($resolver, 'adds'),
			'a helper that registers a control was rewritten to an empty body; concluding "adds nothing" '
				. 'from it is the false ABSENT this check exists to prevent',
		);
		self::assertNull($this->resolve($resolver, 'addsNothing'));
		self::assertNull(
			$this->resolve($resolver, 'closureOnly'),
			'CleaningParser keeps closures but drops the statements around them, and the wrapper it '
				. 'synthesises carries no source position',
		);

		// The one body a stripping parse reproduces faithfully. Resolving it is not a leak: the
		// stripped answer and the real one agree, because there is nothing to strip.
		self::assertNotNull($this->resolve($resolver, 'emptyBody'));
	}

	/**
	 * @return array{method: ClassMethod, file: string, paramName: string, paramClass: string}|null
	 */
	private function resolve(CalleeShapeResolver $resolver, string $method): ?array
	{
		return $resolver->resolveContainerParam(
			new MethodCall(new Variable('this'), new Identifier($method), [new Arg(new Variable('form'))]),
			0,
			BodyVisibilityHelpers::class,
		);
	}

	private function resolverWith(Parser $parser): CalleeShapeResolver
	{
		$dir = sys_get_temp_dir() . '/callee-body-visibility-' . uniqid('', true);
		FileSystem::createDir($dir);
		$this->dirs[] = $dir;

		return new CalleeShapeResolver($parser, self::createReflectionProvider(), new FormShapeCache($dir));
	}

	/**
	 * Built here rather than pulled from the container by name: PHPStan's testing container rebinds
	 * `currentPhpVersionSimpleParser` to the rich one, and what has to be pinned is the real
	 * CleaningParser behaviour that PathRoutingParser reaches for on a non-analysed file.
	 */
	private function strippingParser(): Parser
	{
		$direct = self::getContainer()->getService('currentPhpVersionSimpleDirectParser');
		assert($direct instanceof Parser);

		// A hand-written stand-in would pin this test's model of the hazard rather than the hazard.
		return new CleaningParser( // @phpstan-ignore phpstanApi.constructor (the real rewriter is what has to be exercised)
			$direct,
			self::getContainer()->getByType(PhpVersion::class),
		);
	}

	private function methodNode(Parser $parser, string $name): ClassMethod
	{
		$node = (new NodeFinder())->findFirst(
			$parser->parseFile(self::FIXTURE),
			static fn (Node $n): bool => $n instanceof ClassMethod && $n->name->toString() === $name,
		);
		assert($node instanceof ClassMethod);

		return $node;
	}

}
