<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\DeclaredVarsResolver;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @group latte2
 */
final class DeclaredVarsResolverTest extends BaseTestCase
{

	public function testForBlockResolvesDefineBodyVarType(): void
	{
		$dir = $this->isolatedDir('latte-declaredvars-block');
		FileSystem::write($dir . '/a.latte', "{define b}{varType string \$x}{/define}\n");

		try {
			$resolver = $this->resolverFor($dir);
			self::assertSame(['x' => 'string'], $resolver->forBlock($dir . '/a.latte', 'b'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testForBlockExcludesConditionalVarType(): void
	{
		$dir = $this->isolatedDir('latte-declaredvars-conditional');
		FileSystem::write($dir . '/a.latte', "{define b}{if \$c}{varType string \$x}{/if}{/define}\n");

		try {
			$resolver = $this->resolverFor($dir);
			self::assertSame([], $resolver->forBlock($dir . '/a.latte', 'b'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testForBlockKeepsNestedDefinesIndependent(): void
	{
		$dir = $this->isolatedDir('latte-declaredvars-nested');
		FileSystem::write(
			$dir . '/a.latte',
			"{define outer}{varType string \$a}{define inner}{varType int \$b}{/define}{/define}\n",
		);

		try {
			$resolver = $this->resolverFor($dir);
			self::assertSame(['a' => 'string'], $resolver->forBlock($dir . '/a.latte', 'outer'));
			self::assertSame(['b' => 'int'], $resolver->forBlock($dir . '/a.latte', 'inner'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testForBlockReturnsEmptyForUnknownBlockName(): void
	{
		$dir = $this->isolatedDir('latte-declaredvars-unknown-block');
		FileSystem::write($dir . '/a.latte', "{define b}{varType string \$x}{/define}\n");

		try {
			$resolver = $this->resolverFor($dir);
			self::assertSame([], $resolver->forBlock($dir . '/a.latte', 'nope'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testForBlockReturnsEmptyForUnknownFile(): void
	{
		$dir = $this->isolatedDir('latte-declaredvars-unknown-file');
		FileSystem::write($dir . '/a.latte', "{define b}{varType string \$x}{/define}\n");

		try {
			$resolver = $this->resolverFor($dir);
			self::assertSame([], $resolver->forBlock($dir . '/does-not-exist.latte', 'b'));
		} finally {
			FileSystem::delete($dir);
		}
	}

	private function resolverFor(string $dir): DeclaredVarsResolver
	{
		$universe = new LatteUniverse([$dir], $dir);
		$edgeIndex = new TemplateEdgeIndex($universe, new TemplateFactExtractor());

		return new DeclaredVarsResolver(new DeclarationScanner(), $edgeIndex);
	}

	private function isolatedDir(string $prefix): string
	{
		return sys_get_temp_dir() . '/' . $prefix . '-test-' . getmypid() . '-' . uniqid('', true);
	}

}
