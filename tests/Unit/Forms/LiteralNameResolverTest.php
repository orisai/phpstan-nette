<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Component\LiteralNameResolver;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Testing\PHPStanTestCase;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\LiteralNameConstHolder;
use function assert;
use function count;

final class LiteralNameResolverTest extends PHPStanTestCase
{

	private function parseExpr(string $code): Expr
	{
		$parser = (new ParserFactory())->createForHostVersion();

		$stmts = $parser->parse('<?php ' . $code . ';');
		assert($stmts !== null && count($stmts) === 1);
		$stmt = $stmts[0];
		assert($stmt instanceof Expression);

		return $stmt->expr;
	}

	// Any enclosing-class reflection: resolve() only enters the ClassConstFetch branch when a
	// class context is known, but the const class here is the FQCN target, not self/static.
	private function anyClassReflection(): ClassReflection
	{
		return self::createReflectionProvider()->getClass(LiteralNameConstHolder::class);
	}

	public function testResolvesClassConstStringViaReflection(): void
	{
		$expr = $this->parseExpr('\\' . LiteralNameConstHolder::class . '::FIELD_NAME');

		$resolved = LiteralNameResolver::resolve(
			$expr,
			[],
			$this->anyClassReflection(),
			self::createReflectionProvider(),
		);

		self::assertSame('email', $resolved);
	}

	public function testNonStringClassConstReturnsNull(): void
	{
		$expr = $this->parseExpr('\\' . LiteralNameConstHolder::class . '::NOT_A_STRING');

		self::assertNull(
			LiteralNameResolver::resolve($expr, [], $this->anyClassReflection(), self::createReflectionProvider()),
		);
	}

	public function testClassConstReadRecordsDeclaringClassFile(): void
	{
		$recorder = new DependencyRecorder();
		$recorder->beginFrame();

		$expr = $this->parseExpr('\\' . LiteralNameConstHolder::class . '::FIELD_NAME');
		LiteralNameResolver::resolve(
			$expr,
			[],
			$this->anyClassReflection(),
			self::createReflectionProvider(),
			$recorder,
		);

		$deps = $recorder->endFrame();
		$file = (new ReflectionClass(LiteralNameConstHolder::class))->getFileName();
		self::assertNotFalse($file);
		self::assertArrayHasKey($file, $deps);
	}

	public function testWithoutReflectionProviderReturnsNull(): void
	{
		$expr = $this->parseExpr('\\' . LiteralNameConstHolder::class . '::FIELD_NAME');

		self::assertNull(LiteralNameResolver::resolve($expr, [], $this->anyClassReflection(), null));
	}

}
