<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Rule\LatteAnalyzedFileMarkerCollector;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

final class LatteAnalyzedFileMarkerCollectorTest extends BaseTestCase
{

	public function testMatchesFileNode(): void
	{
		self::assertSame(FileNode::class, $this->collector(true)->getNodeType());
	}

	public function testReturnsNullForNonLatteFile(): void
	{
		$node = new FileNode([]);
		$scope = $this->scope('/project/app/Something.php');

		self::assertNull($this->collector(true)->processNode($node, $scope));
	}

	public function testReturnsRelativePathForALatteFileWhenEnabled(): void
	{
		$node = new FileNode([]);
		$scope = $this->scope('/project/a.latte');

		self::assertSame('a.latte', $this->collector(true)->processNode($node, $scope));
	}

	public function testReturnsNullWhenDisabledEvenForALatteFile(): void
	{
		$node = new FileNode([]);
		$scope = $this->scope('/project/a.latte');

		self::assertNull($this->collector(false)->processNode($node, $scope));
	}

	public function testDiscoveryStoreFlagAloneFiresTheMarker(): void
	{
		$node = new FileNode([]);
		$scope = $this->scope('/project/a.latte');

		self::assertSame('a.latte', $this->collector(false, true)->processNode($node, $scope));
	}

	public function testBothFlagsOffStaysNull(): void
	{
		$node = new FileNode([]);
		$scope = $this->scope('/project/a.latte');

		self::assertNull($this->collector(false, false)->processNode($node, $scope));
	}

	private function collector(bool $enabled, bool $discoveryStoreEnabled = false): LatteAnalyzedFileMarkerCollector
	{
		return new LatteAnalyzedFileMarkerCollector(
			TestGuard::latte(true, $enabled, $discoveryStoreEnabled),
			new LatteUniverse([], '/project'),
		);
	}

	private function scope(string $file): Scope
	{
		$scope = $this->createStub(Scope::class);
		$scope->method('getFile')->willReturn($file);

		return $scope;
	}

}
