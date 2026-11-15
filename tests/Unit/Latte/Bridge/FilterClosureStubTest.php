<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use OriPhpstan\Nette\Latte\Bridge\FilterClosureStub;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterBasePresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters\FilterHelpers;
use function sprintf;

final class FilterClosureStubTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private const Result = '/** @var %s $ʟ_filterResult */ $ʟ_filterResult = \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); return $ʟ_filterResult;';

	/**
	 * @return iterable<string, array{string, string|null}>
	 */
	public static function provideSignatures(): iterable
	{
		yield 'void' => ['function (int $i): void {}', 'static function (int $i): void {  }'];
		yield 'never' => ['function (): never { exit; }', 'static function (): never { throw new \LogicException(); }'];
		yield 'union and nullable' => [
			'function (int|string $a, ?\DateTimeInterface $b = null): int|false { return 1; }',
			'static function (int|string $a, ?\DateTimeInterface $b = null): int|false { ' . sprintf(
				self::Result,
				'int|false',
			) . ' }',
		];

		yield 'disjunctive normal form' => [
			'function ((\Countable&\Traversable)|null $a): string { return ""; }',
			'static function ((\Countable&\Traversable)|null $a): string { ' . sprintf(self::Result, 'string') . ' }',
		];

		yield 'literal defaults' => [
			"function (array \$a = ['x' => 1, -2], bool \$b = true, float \$c = 1.5, string \$d = 'q'): string { return ''; }",
			"static function (array \$a = ['x' => 1, -2], bool \$b = true, float \$c = 1.5, string \$d = 'q'): string { " . sprintf(
				self::Result,
				'string',
			) . ' }',
		];

		yield 'by reference and variadic' => [
			'function (&$a, string ...$rest) { return 1; }',
			'static function (&$a, string ...$rest) { return \OriPhpstan\Nette\Latte\Runtime\Helpers::untypedFilter(); }',
		];

		yield 'self resolves to the declaring class' => [
			'function (self $a, int $b = self::LEVEL): parent { return $a; }',
			'static function (\\' . FilterBasePresenter::class . ' $a, int $b = \\' . FilterBasePresenter::class . '::LEVEL): \Nette\Application\UI\Presenter { ' . sprintf(
				self::Result,
				'\Nette\Application\UI\Presenter',
			) . ' }',
		];

		yield 'filter info first' => [
			'function (\Latte\Runtime\FilterInfo $info, string $s): string { return $s; }',
			'static function (\Latte\Runtime\FilterInfo $info, string $s): string { ' . sprintf(
				self::Result,
				'string',
			) . ' }',
		];

		yield 'non-public constant default' => ['function (int $a = \\' . FilterHelpers::class . '::NOPE): int { return $a; }', null];
		yield 'expression default' => ['function (int $a = PHP_INT_MAX): int { return $a; }', null];
	}

	/**
	 * @dataProvider provideSignatures
	 */
	public function testPrintsTheNativeSignature(string $closure, ?string $expected): void
	{
		$stub = FilterClosureStub::of(
			$this->closure($closure),
			self::createReflectionProvider()->getClass(FilterBasePresenter::class),
			self::createReflectionProvider(),
		);

		self::assertSame($expected, $stub === null ? null : $stub[0]);
	}

	public function testFilterInfoFirstParameterIsContentAware(): void
	{
		$reflectionProvider = self::createReflectionProvider();
		$declaring = $reflectionProvider->getClass(FilterBasePresenter::class);

		$aware = FilterClosureStub::of(
			$this->closure('function (\Latte\Runtime\FilterInfo $i, string $s): string { return $s; }'),
			$declaring,
			$reflectionProvider,
		);
		$plain = FilterClosureStub::of(
			$this->closure('function (string $s): string { return $s; }'),
			$declaring,
			$reflectionProvider,
		);

		self::assertNotNull($aware);
		self::assertNotNull($plain);
		self::assertTrue($aware[1]);
		self::assertFalse($plain[1]);
	}

	private function closure(string $source): Closure
	{
		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		$closure = (new NodeFinder())->findFirstInstanceOf(
			$parser->parseString('<?php ' . $source . ';'),
			Closure::class,
		);
		self::assertInstanceOf(Closure::class, $closure);

		return $closure;
	}

}
