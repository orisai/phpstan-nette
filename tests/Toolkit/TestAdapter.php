<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Customs\EngineSource;
use OriPhpstan\Nette\Latte\Customs\ExtensionSourceSalt;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Version\AdapterCollaborators;
use OriPhpstan\Nette\Latte\Version\LatteEngineReader;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterAccessor;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;

// The adapter, engine reader and harvester the installed Latte gets in production, over
// throwaway collaborators (no analysis cache, no discovery store).
final class TestAdapter
{

	public static function factory(): LatteVersionAdapterFactory
	{
		return new LatteVersionAdapterFactory(ProjectInstalledVersions::get());
	}

	// A factory answering for a Latte line that need not be the installed one, for the services
	// that only ask it which line's semantics to model.
	public static function factoryFor(string $latteVersion): LatteVersionAdapterFactory
	{
		return new LatteVersionAdapterFactory(ProjectInstalledVersions::fromRawData([[
			'root' => [],
			'versions' => [
				ProjectInstalledVersions::PACKAGE => ['version' => '1.0.0.0'],
				'latte/latte' => ['version' => $latteVersion],
			],
		]]));
	}

	public static function create(?CustomsHarvester $harvester = null): LatteVersionAdapter
	{
		return self::accessor($harvester)->get();
	}

	public static function accessor(?CustomsHarvester $harvester = null): LatteVersionAdapterAccessor
	{
		return new LatteVersionAdapterAccessor(
			self::factory(),
			new AdapterCollaborators(new DeclarationScanner(), null, $harvester),
		);
	}

	public static function engineReader(): LatteEngineReader
	{
		return self::factory()->createEngineReader();
	}

	public static function harvester(EngineSource $engineSource, ?LatteUniverse $universe = null): CustomsHarvester
	{
		return new CustomsHarvester(
			$engineSource,
			self::factory(),
			new ExtensionSourceSalt(null, null, ProjectInstalledVersions::get()),
			$universe,
		);
	}

}
