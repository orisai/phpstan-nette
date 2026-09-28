<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRefResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Compile\DiscoveryClassName;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery\Fixtures\FixtureRecordSourceContainer;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryStoreLinkedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\FixtureStoreLinkedControlBase;
use function getmypid;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

final class DiscoveryRefResolverTest extends PHPStanTestCase
{

	private const LINKED_TEMPLATE_REL = 'App/discoveryStoreLinked.latte';

	public function testDisabledYieldsNoRefsAndNeverTouchesTheStore(): void
	{
		$resolver = new DiscoveryRefResolver(
			new FixtureRecordSourceContainer($this->recordSource()),
			new DiscoveryStore($this->scratchDir() . '/never-created'),
			false,
		);

		self::assertSame([], $resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL));
	}

	public function testTemplateWithAStoreFileGetsItsOwnDiscoveryClassRef(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, [self::LINKED_TEMPLATE_REL]);

			$resolver = new DiscoveryRefResolver(
				new FixtureRecordSourceContainer($this->recordSource()),
				new DiscoveryStore($storeDir),
				true,
			);

			self::assertSame(
				[DiscoveryClassName::forPath(self::LINKED_TEMPLATE_REL)],
				$resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL),
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testTemplateWithoutAStoreFileGetsNoSelfRef(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, []);

			$resolver = new DiscoveryRefResolver(
				new FixtureRecordSourceContainer($this->recordSource()),
				new DiscoveryStore($storeDir),
				true,
			);

			self::assertSame([], $resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRecordsAddRendererAndReadSetClassRefs(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, [self::LINKED_TEMPLATE_REL]);
			(new DiscoveryStore($storeDir))->replaceWith(
				[
					self::LINKED_TEMPLATE_REL => [
						[
							'class' => DiscoveryStoreLinkedControl::class,
							'view' => null,
							'kind' => 'setFile',
							'certainty' => 'unknown',
						],
					],
				],
				[DiscoveryStoreLinkedControl::class],
				[],
			);

			$resolver = new DiscoveryRefResolver(
				new FixtureRecordSourceContainer($this->recordSource()),
				new DiscoveryStore($storeDir),
				true,
			);

			$refs = $resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL);

			self::assertContains(DiscoveryClassName::forPath(self::LINKED_TEMPLATE_REL), $refs);
			self::assertContains(DiscoveryStoreLinkedControl::class, $refs);
			self::assertContains(
				FixtureStoreLinkedControlBase::class,
				$refs,
				'every read-set file resolves to its declared class - an app-root ancestor edit must '
				. 'reach the linked template through a DIRECT edge',
			);
			self::assertSame($refs, $resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL));
		} finally {
			FileSystem::delete($dir);
		}
	}

	public function testRecordClassThatNoLongerExistsIsSkipped(): void
	{
		$dir = $this->scratchDir();

		try {
			$storeDir = $dir . '/store';
			DiscoveryStore::bootstrap($storeDir, [self::LINKED_TEMPLATE_REL]);
			(new DiscoveryStore($storeDir))->replaceWith(
				[
					self::LINKED_TEMPLATE_REL => [
						['class' => 'App\\No\\Longer\\There', 'view' => null, 'kind' => 'setFile', 'certainty' => 'unknown'],
					],
				],
				['App\\No\\Longer\\There'],
				[],
			);

			$resolver = new DiscoveryRefResolver(
				new FixtureRecordSourceContainer($this->recordSource()),
				new DiscoveryStore($storeDir),
				true,
			);

			self::assertSame(
				[DiscoveryClassName::forPath(self::LINKED_TEMPLATE_REL)],
				$resolver->refClassNamesFor(self::LINKED_TEMPLATE_REL),
				'a vanished record class must never be referenced - it would be a class.notFound error',
			);
		} finally {
			FileSystem::delete($dir);
		}
	}

	private function recordSource(): DiscoveryRecordSource
	{
		$appRoot = realpath(__DIR__ . '/../Fixtures/App');
		self::assertNotFalse($appRoot);
		$fixturesRoot = realpath(__DIR__ . '/../Fixtures');
		self::assertNotFalse($fixturesRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$templateFactoryDefault = new TemplateFactoryDefaultResolver(null);
		$discoveryResolver = new DiscoveryResolver(null, [], $fixturesRoot);
		$walk = new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			$templateFactoryDefault,
			$discoveryResolver,
		);

		return new DiscoveryRecordSource(
			new PhpFactsCache(
				new LatteAnalysisCache($this->scratchDir() . '/cache', 'testv1'),
				$templateFactoryDefault,
				$discoveryResolver,
				[],
			),
			$walk,
		);
	}

	private function scratchDir(): string
	{
		return sys_get_temp_dir() . '/latte-discovery-refresolver-test-' . getmypid() . '-' . uniqid('', true);
	}

}
