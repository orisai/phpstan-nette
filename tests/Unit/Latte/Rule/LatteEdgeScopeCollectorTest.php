<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Rule\LatteEdgeScopeCollector;
use OriPhpstan\Nette\Latte\Runtime\Helpers;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Type;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function getmypid;
use function sha1;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;

final class LatteEdgeScopeCollectorTest extends BaseTestCase
{

	// Opt-in gate: a genuinely matching edgeScope call must still capture
	// nothing when the collector itself is disabled - proven with a real file + a real manifest
	// entry the scope has a type for, so this cannot pass by accident (e.g. an unrelated skip
	// branch firing first).
	public function testCapturesNothingWhenDisabledEvenForAGenuineEdgeScopeCall(): void
	{
		$file = $this->writeTempFile('x');
		$node = $this->edgeScopeCall('k', [], ['known']);
		$scope = $this->scope($file, ['known' => 'string'], []);

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, false)))->processNode($node, $scope));
	}

	public function testSkipsFileNotEndingInLatteExtension(): void
	{
		$node = $this->edgeScopeCall('k', []);
		$scope = $this->scope('/project/app/Something.php', [], []);

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	public function testSkipsStaticCallToADifferentMethod(): void
	{
		$node = new StaticCall(new FullyQualified(Helpers::class), new Identifier('analyzed'), [
			new Arg(new String_('k')),
		]);
		$scope = $this->scope('/project/a.latte', [], []);

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	public function testSkipsWhenClassDoesNotResolveToHelpers(): void
	{
		$node = $this->edgeScopeCall('k', []);
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn('/project/a.latte');
		$scope->method('resolveName')->willReturn('Some\\Other\\Class');

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	public function testSkipsWhenKeyArgIsNotALiteralString(): void
	{
		$node = new StaticCall(new FullyQualified(Helpers::class), new Identifier('edgeScope'), [
			new Arg(new Variable('dynamicKey')),
			new Arg(new Array_([])),
		]);
		$scope = $this->scope('/project/a.latte', [], []);

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	public function testCapturesKeyAndShaOfTheAnalysedFileSource(): void
	{
		$file = $this->writeTempFile("{\$x}\n");
		$node = $this->edgeScopeCall('a.latte#1#b.latte#ctx', []);
		$scope = $this->scope($file, [], []);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame('a.latte#1#b.latte#ctx', $result['key']);
		self::assertSame(sha1(FileSystem::read($file)), $result['sha']);
		self::assertSame([], $result['vars']);
		self::assertSame([], $result['args']);
	}

	public function testReturnsNullWhenTheAnalysedFileIsUnreadable(): void
	{
		$node = $this->edgeScopeCall('k', []);
		$scope = $this->scope(sys_get_temp_dir() . '/does-not-exist-' . uniqid('', true) . '.latte', [], []);

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	public function testCapturesManifestVarsOnlyWhenScopeHasAType(): void
	{
		$file = $this->writeTempFile('x');
		$node = $this->edgeScopeCall('k', [], ['known', 'unknown']);
		$scope = $this->scope($file, ['known' => 'string'], []);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame(
			['known' => 'string'],
			$result['vars'],
			'a manifest name the scope has no type for must be omitted, not defaulted to mixed',
		);
	}

	public function testSkipsThisAndTempVarPrefixedNamesEvenWithARealScopeType(): void
	{
		$file = $this->writeTempFile('x');
		$node = $this->edgeScopeCall('k', [], ['this', "\u{29F}_tmp", 'keep']);
		$scope = $this->scope($file, ['this' => 'Template', "\u{29F}_tmp" => 'string', 'keep' => 'int'], []);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame(['keep' => 'int'], $result['vars']);
	}

	public function testOmitsManifestVarDescriptionOverTheLengthCap(): void
	{
		$file = $this->writeTempFile('x');
		$node = $this->edgeScopeCall('k', [], ['huge', 'small']);
		$scope = $this->scope($file, ['huge' => str_repeat('a', 1025), 'small' => 'int'], []);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame(
			['small' => 'int'],
			$result['vars'],
			'over-1024-char description must be omitted, not truncated',
		);
	}

	public function testKeepsManifestVarDescriptionExactlyAtTheLengthCap(): void
	{
		$file = $this->writeTempFile('x');
		$node = $this->edgeScopeCall('k', [], ['atCap']);
		$scope = $this->scope($file, ['atCap' => str_repeat('a', 1024)], []);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertArrayHasKey('atCap', $result['vars']);
		self::assertSame(str_repeat('a', 1024), $result['vars']['atCap']);
	}

	public function testCapturesArgExpressionTypesFromTheArrayLiteral(): void
	{
		$file = $this->writeTempFile('x');
		$exprA = new Variable('a');
		$exprB = new Variable('b');
		$node = $this->edgeScopeCall('k', ['first' => $exprA, 'second' => $exprB]);
		$scope = $this->scope($file, [], [
			[$exprA, 'string'],
			[$exprB, 'int'],
		]);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame(['first' => 'string', 'second' => 'int'], $result['args']);
	}

	public function testSkipsThisAndTempVarPrefixedArgNames(): void
	{
		$file = $this->writeTempFile('x');
		$exprThis = new Variable('irrelevant1');
		$exprTemp = new Variable('irrelevant2');
		$exprKeep = new Variable('irrelevant3');
		$node = $this->edgeScopeCall('k', [
			'this' => $exprThis,
			"\u{29F}_tmp" => $exprTemp,
			'keep' => $exprKeep,
		]);
		$scope = $this->scope($file, [], [
			[$exprThis, 'Template'],
			[$exprTemp, 'string'],
			[$exprKeep, 'int'],
		]);

		$result = (new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope);

		self::assertNotNull($result);
		self::assertSame(['keep' => 'int'], $result['args']);
	}

	public function testNeverThrowsWhenScopeCooperationFailsUnexpectedly(): void
	{
		$node = $this->edgeScopeCall('k', [], ['x']);
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn('/project/a.latte');
		$scope->method('resolveName')->willReturn(Helpers::class);
		$scope->method('hasVariableType')->willThrowException(new RuntimeException('boom'));

		self::assertNull((new LatteEdgeScopeCollector(TestGuard::latte(true, true)))->processNode($node, $scope));
	}

	/**
	 * @param array<string, Expr> $args
	 * @param list<string> $manifest
	 */
	private function edgeScopeCall(string $key, array $args, array $manifest = []): StaticCall
	{
		$items = [];
		foreach ($args as $name => $expr) {
			$items[] = new ArrayItem($expr, new String_($name));
		}

		$call = new StaticCall(new FullyQualified(Helpers::class), new Identifier('edgeScope'), [
			new Arg(new String_($key)),
			new Arg(new Array_($items)),
		]);
		$call->setAttribute('latte.edgeManifest', $manifest);

		return $call;
	}

	/**
	 * @param array<string, string> $variableTypes
	 * @param list<array{Expr, string}> $exprTypes
	 */
	private function scope(string $file, array $variableTypes, array $exprTypes): Scope
	{
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn($file);
		$scope->method('resolveName')->willReturn(Helpers::class);
		$scope->method('hasVariableType')->willReturnCallback(
			static fn (string $name): TrinaryLogic => isset($variableTypes[$name]) ? TrinaryLogic::createYes() : TrinaryLogic::createNo(),
		);
		$scope->method('getVariableType')->willReturnCallback(
			fn (string $name): Type => $this->describedType($variableTypes[$name] ?? ''),
		);
		$scope->method('getType')->willReturnCallback(
			function (Expr $expr) use ($exprTypes): Type {
				foreach ($exprTypes as [$candidate, $description]) {
					if ($candidate === $expr) {
						return $this->describedType($description);
					}
				}

				return $this->describedType('mixed');
			},
		);

		return $scope;
	}

	private function describedType(string $description): Type
	{
		$type = $this->createStub(Type::class);
		$type->method('describe')->willReturn($description);

		return $type;
	}

	private function writeTempFile(string $content): string
	{
		$path = sys_get_temp_dir() . '/latte-edge-scope-collector-test-' . getmypid() . '-' . uniqid(
			'',
			true,
		) . '.latte';
		FileSystem::write($path, $content);

		return $path;
	}

}
