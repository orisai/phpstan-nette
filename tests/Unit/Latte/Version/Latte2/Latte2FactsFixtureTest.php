<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Version\Latte2;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\FactsFixtures;
use Tests\OriPhpstan\Nette\Toolkit\FactsJson;
use Tests\OriPhpstan\Nette\Toolkit\SnapshotAssertions;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;

// The Latte 2 adapter's facts for every fixture, pinned as the reference the Latte 3 parity test
// compares against (Latte3FactsParityTest). Regenerate with UPDATE_SNAPSHOTS=1 on the default profile.
/**
 * @group latte2
 */
final class Latte2FactsFixtureTest extends BaseTestCase
{

	use SnapshotAssertions;

	/**
	 * @dataProvider provideFixtures
	 */
	public function testFactsMatchTheCommittedJson(string $lattePath, string $relativePath, string $jsonPath): void
	{
		$facts = TestAdapter::create()->extractFacts(FileSystem::read($lattePath), $relativePath);

		$this->assertSnapshot($jsonPath, FactsJson::encode($facts));
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public function provideFixtures(): iterable
	{
		yield from FactsFixtures::all();
	}

}
