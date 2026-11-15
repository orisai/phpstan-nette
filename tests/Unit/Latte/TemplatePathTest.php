<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte;

use OriPhpstan\Nette\Latte\TemplatePath;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class TemplatePathTest extends BaseTestCase
{

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function providePaths(): iterable
	{
		yield 'template' => ['/app/templates/Home/default.latte', true];
		yield 'relative template' => ['default.latte', true];
		yield 'php file' => ['/app/Presenters/HomePresenter.php', false];
		yield 'compiled template cache' => ['/temp/cache/latte/default.latte--abc.php', false];
		yield 'latte directory' => ['/app/x.latte/Foo.php', false];
		yield 'shorter than the extension' => ['ab', false];
		yield 'empty' => ['', false];
		yield 'collected data scope' => ['N/A', false];
	}

	/**
	 * @dataProvider providePaths
	 */
	public function testIs(string $path, bool $expected): void
	{
		self::assertSame($expected, TemplatePath::is($path));
	}

}
