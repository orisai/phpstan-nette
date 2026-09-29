<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Includes;

use OriPhpstan\Nette\Latte\Includes\ArgTyper;
use OriPhpstan\Nette\Latte\Includes\EdgeScope;
use OriPhpstan\Nette\Latte\Includes\IncludeTarget;
use OriPhpstan\Nette\Latte\Includes\TemplateContext;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;

// Latte 3.1's `{extends file, args}` renders the parent with `$this->parentArgs + $params`: the
// explicit args reach the layout's scope on top of the child's finished head scope and win over a
// same-named top-level local. Latte 2 and 3.0 accept no args on {extends}/{layout}, so the
// overlay is empty there and the layout scope is the head scope alone.
final class EdgeScopeLayoutArgsTest extends BaseTestCase
{

	public function testExtendsArgsOverlayTheChildsHeadScope(): void
	{
		$scope = $this->resolve('extends', "x => 'a', n => \$count", ['local' => 'string']);

		self::assertSame(
			['count' => 'int', 'local' => 'string', 'x' => 'string', 'n' => 'int'],
			$scope['vars'],
		);
		self::assertSame(['x' => 'string', 'n' => 'int'], $scope['namedKeys']);
		self::assertFalse($scope['open']);
	}

	public function testExtendsArgWinsOverTheSameNamedTopLevelLocal(): void
	{
		$scope = $this->resolve('extends', "x => 'a'", ['x' => 'int']);

		self::assertSame(['count' => 'int', 'x' => 'string'], $scope['vars']);
		self::assertSame(['x' => 'string'], $scope['namedKeys']);
	}

	public function testLayoutWithoutArgsProvidesTheHeadScopeAlone(): void
	{
		$scope = $this->resolve('layout', '', ['local' => 'string']);

		self::assertSame(['count' => 'int', 'local' => 'string'], $scope['vars']);
		self::assertSame([], $scope['namedKeys']);
		self::assertFalse($scope['open']);
	}

	/**
	 * @param array<string, string> $topLevelVars
	 * @return array{vars: array<string, string>, namedKeys: array<string, string>, open: bool}
	 */
	private function resolve(string $tag, string $argsSource, array $topLevelVars): array
	{
		return EdgeScope::resolve(
			new IncludeTarget($tag, IncludeTarget::KIND_STATIC_FILE, 'page.latte', 'page.latte', $argsSource, 1),
			TemplateContext::root(['count' => 'int']),
			new ArgTyper(TestAdapter::accessor()),
			static fn (): array => $topLevelVars,
			false,
		);
	}

}
