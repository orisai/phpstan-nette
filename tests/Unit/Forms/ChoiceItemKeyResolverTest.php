<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Catalog\ChoiceItemKeyResolver;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use ReflectionClass;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use Tests\OriPhpstan\Nette\Unit\Forms\Support\ChoiceConstHolder;
use function assert;
use function count;

final class ChoiceItemKeyResolverTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private function resolver(): ChoiceItemKeyResolver
	{
		return new ChoiceItemKeyResolver(self::createReflectionProvider());
	}

	private function parseExpr(string $code): Expr
	{
		$parser = (new ParserFactory())->createForHostVersion();

		$stmts = $parser->parse('<?php ' . $code . ';');
		assert($stmts !== null && count($stmts) === 1);
		$stmt = $stmts[0];
		assert($stmt instanceof Expression);

		return $stmt->expr;
	}

	private function describe(?Type $type): ?string
	{
		return $type === null ? null : $type->describe(VerbosityLevel::precise());
	}

	public function testLiteralAssocArrayKeys(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['a' => 'A', 'b' => 'B']"), true, null);
		self::assertSame("'a'|'b'", $this->describe($type));
	}

	public function testLiteralListSequentialKeys(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['x', 'y', 'z']"), true, null);
		self::assertSame('0|1|2', $this->describe($type));
	}

	public function testLiteralIntKeys(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr('[10 => "Ten", 20 => "Twenty"]'), true, null);
		self::assertSame('10|20', $this->describe($type));
	}

	public function testValueNodesIrrelevantToKeyResolution(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['a' => foo(), 'b' => bar()]"), true, null);
		self::assertSame("'a'|'b'", $this->describe($type));
	}

	public function testUseKeysFalseUsesStringCastValues(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['x', 'y']"), false, null);
		self::assertSame("'x'|'y'", $this->describe($type));
	}

	public function testUseKeysFalseFromAssocUsesValues(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['k1' => 'x', 'k2' => 'y']"), false, null);
		self::assertSame("'x'|'y'", $this->describe($type));
	}

	public function testOptgroupNestedArrayWidens(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['g' => ['a' => 'A']]"), true, null);
		self::assertNull($type);
	}

	public function testMixedExplicitAndImplicitKeysWidens(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr("['a' => 'A', 'B']"), true, null);
		self::assertNull($type);
	}

	public function testEmptyArrayWidens(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr('[]'), true, null);
		self::assertNull($type);
	}

	public function testVariableWidens(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr('$items'), true, null);
		self::assertNull($type);
	}

	public function testFunctionCallWidens(): void
	{
		$type = $this->resolver()->resolveKeyUnion($this->parseExpr('array_combine($a, $b)'), true, null);
		self::assertNull($type);
	}

	public function testClassConstStringKeysViaReflection(): void
	{
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::STRING_KEYS');
		$type = $this->resolver()->resolveKeyUnion($expr, true, null);
		self::assertSame("'draft'|'live'", $this->describe($type));
	}

	public function testClassConstIntKeysViaReflection(): void
	{
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::INT_KEYS');
		$type = $this->resolver()->resolveKeyUnion($expr, true, null);
		self::assertSame('1|2', $this->describe($type));
	}

	public function testClassConstUseKeysFalseUsesValues(): void
	{
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::STRING_VALUES');
		$type = $this->resolver()->resolveKeyUnion($expr, false, null);
		self::assertSame("'x'|'y'|'z'", $this->describe($type));
	}

	public function testClassConstNonArrayWidens(): void
	{
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::NOT_AN_ARRAY');
		$type = $this->resolver()->resolveKeyUnion($expr, true, null);
		self::assertNull($type);
	}

	public function testMissingClassConstWidens(): void
	{
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::MISSING');
		$type = $this->resolver()->resolveKeyUnion($expr, true, null);
		self::assertNull($type);
	}

	public function testConstWithoutReflectionProviderWidens(): void
	{
		$resolver = new ChoiceItemKeyResolver(null);
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::STRING_KEYS');
		self::assertNull($resolver->resolveKeyUnion($expr, true, null));
	}

	public function testClassConstReadRecordsDeclaringClassFile(): void
	{
		$recorder = new DependencyRecorder();
		$recorder->beginFrame();

		$resolver = new ChoiceItemKeyResolver(self::createReflectionProvider(), $recorder);
		$expr = $this->parseExpr('\\' . ChoiceConstHolder::class . '::STRING_KEYS');
		$resolver->resolveKeyUnion($expr, true, null);

		$deps = $recorder->endFrame();
		$file = (new ReflectionClass(ChoiceConstHolder::class))->getFileName();
		self::assertNotFalse($file);
		self::assertArrayHasKey($file, $deps);
	}

}
