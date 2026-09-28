<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixtureFactoryDefaultTemplate;

final class TemplateFactoryDefaultResolverTest extends BaseTestCase
{

	public function testKeyedContainersResolveTheFactoryDefault(): void
	{
		$resolver = new TemplateFactoryDefaultResolver(__DIR__ . '/Fixtures/factory-default-container-loader.php');

		self::assertSame(FixtureFactoryDefaultTemplate::class, $resolver->resolve());
		self::assertSame([], $resolver->resolveWiring());
	}

	// The loader contract's shorthand: a bare container reads as ['default' => $container].
	public function testBareContainerResolvesTheFactoryDefault(): void
	{
		$resolver = new TemplateFactoryDefaultResolver(
			__DIR__ . '/Fixtures/factory-default-bare-container-loader.php',
		);

		self::assertSame(FixtureFactoryDefaultTemplate::class, $resolver->resolve());
		self::assertSame([], $resolver->resolveWiring());
	}

	public function testContainerWithoutABridgeFactoryResolvesNothing(): void
	{
		$resolver = new TemplateFactoryDefaultResolver(
			__DIR__ . '/Fixtures/factory-default-container-loader-no-factory.php',
		);

		self::assertNull($resolver->resolve());
		self::assertNull($resolver->resolveWiring());
	}

	public function testNoLoaderResolvesNothing(): void
	{
		$resolver = new TemplateFactoryDefaultResolver(null);

		self::assertNull($resolver->resolve());
		self::assertNull($resolver->resolveWiring());
	}

}
