<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\Discovery\CandidatePath;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryFact;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryResolver;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\SetFileFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use OriPhpstan\Nette\Latte\Bridge\TemplateFactoryDefaultResolver;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use function array_keys;
use function basename;
use function count;
use function glob;
use function ksort;
use function realpath;
use function substr;
use const SORT_STRING;

// Scale/determinism proof over the committed Fixtures/Fleet/ corpus - ~50 generated
// presenter/control replicas covering every fact domain. Wall time is observed in gate
// output, never asserted (a hard time gate would flake under parallel load).
final class PhpRenderWalkScaleTest extends PHPStanTestCase
{

	private const GeneratorFile = __DIR__ . '/Fixtures/fleet-generator.php';

	private const FleetDir = __DIR__ . '/Fixtures/Fleet';

	private const MappingLoaderFile = __DIR__ . '/Fixtures/fleet-mapping-container-loader.php';

	private const FleetNamespace = 'Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\Fleet\\';

	// Drift guard: the fleet is generator-owned - a hand edit to a committed Fleet/ file (or a
	// generator change without regeneration) must fail here, byte-for-byte.
	public function testCommittedFleetIsByteIdenticalToTheGeneratorOutput(): void
	{
		$generated = $this->generatedFleet();

		$paths = glob(self::FleetDir . '/*.php');
		self::assertNotFalse($paths);

		$committed = [];
		foreach ($paths as $path) {
			$committed[basename($path)] = FileSystem::read($path);
		}

		self::assertSame(array_keys($generated), array_keys($committed));
		self::assertSame($generated, $committed);
	}

	public function testFleetExtractionIsDeterministicAcrossTwoIndependentRuns(): void
	{
		$classNames = $this->fleetClassNames();
		self::assertCount(70, $classNames);

		$first = $this->canonicalHashes($classNames);
		$second = $this->canonicalHashes($classNames);

		self::assertCount(70, $first);
		self::assertSame($first, $second);
	}

	public function testFleetExercisesEveryFactDomain(): void
	{
		$walk = $this->freshWalk();

		$qualifying = 0;
		$channels = [];
		$candidateChannels = [];
		$assignmentTotal = 0;
		$maybeAssignments = 0;
		$setFileKinds = [];
		$renderSiteTotal = 0;
		$discoveryCandidateKinds = [];
		$discoveryLayoutTotal = 0;
		$discoveryOpaqueTotal = 0;
		$discoveryProbeTotal = 0;
		$discoveryProbeKinds = [];

		foreach ($this->fleetClassNames() as $className) {
			$facts = $walk->factsFor($className);

			$templateClass = $facts->getTemplateClass();
			if ($templateClass !== null) {
				$qualifying++;
				$channels[$templateClass->getChannel()] = ($channels[$templateClass->getChannel()] ?? 0) + 1;
			}

			foreach ($facts->getTemplateClassCandidates() as $candidate) {
				$candidateChannels[$candidate->getChannel()] = ($candidateChannels[$candidate->getChannel()] ?? 0) + 1;
			}

			$assignmentTotal += count($facts->getAssignments());
			foreach ($facts->getAssignments() as $assignment) {
				if ($assignment->getCertainty() === Certainty::MAYBE) {
					$maybeAssignments++;
				}
			}

			foreach ($facts->getSetFileTargets() as $target) {
				$setFileKinds[$target->getKind()] = ($setFileKinds[$target->getKind()] ?? 0) + 1;
			}

			$renderSiteTotal += count($facts->getRenderSites());

			$discovery = $facts->getDiscovery();
			if ($discovery !== null) {
				foreach ($discovery->getViewCandidates() as $candidates) {
					foreach ($candidates as $candidate) {
						$kind = $candidate->getKind();
						$discoveryCandidateKinds[$kind] = ($discoveryCandidateKinds[$kind] ?? 0) + 1;
					}
				}

				$discoveryLayoutTotal += count($discovery->getLayoutCandidates());
				$discoveryOpaqueTotal += count($discovery->getOpaques());
				$discoveryProbeTotal += count($discovery->getExistenceSet());

				foreach ($discovery->getExistenceSet() as $probe) {
					$discoveryProbeKinds[$probe['kind']] = ($discoveryProbeKinds[$probe['kind']] ?? 0) + 1;
				}
			}
		}

		ksort($channels, SORT_STRING);
		ksort($candidateChannels, SORT_STRING);
		ksort($setFileKinds, SORT_STRING);

		self::assertSame(60, $qualifying);
		self::assertSame(
			[
				TemplateClassFact::CHANNEL_GENERIC_BINDING => 5,
				TemplateClassFact::CHANNEL_PHPDOC => 5,
				TemplateClassFact::CHANNEL_TEMPLATE_FLOOR => 50,
			],
			$channels,
		);
		self::assertSame(
			[
				TemplateClassFact::CHANNEL_CONVENTION => 5,
				TemplateClassFact::CHANNEL_GENERIC_BINDING => 10,
				TemplateClassFact::CHANNEL_PHPDOC => 5,
			],
			$candidateChannels,
		);
		self::assertSame(55, $assignmentTotal);
		self::assertSame(5, $maybeAssignments);
		self::assertSame(
			[
				SetFileFact::KIND_CONVENTION => 10,
				SetFileFact::KIND_LITERAL => 5,
				SetFileFact::KIND_OPAQUE => 5,
			],
			$setFileKinds,
		);
		self::assertSame(15, $renderSiteTotal);

		ksort($discoveryCandidateKinds, SORT_STRING);
		self::assertSame(
			[
				CandidatePath::KIND_CONVENTION => 10,
				CandidatePath::KIND_FORMULA => 20,
				CandidatePath::KIND_SET_FILE => 5,
			],
			$discoveryCandidateKinds,
		);
		self::assertSame(20, $discoveryLayoutTotal);
		self::assertSame(10, $discoveryOpaqueTotal);
		self::assertSame(70, $discoveryProbeTotal);

		ksort($discoveryProbeKinds, SORT_STRING);
		self::assertSame(
			[
				DiscoveryFact::PROBE_DIR => 5,
				DiscoveryFact::PROBE_FILE => 55,
				DiscoveryFact::PROBE_LISTING => 10,
			],
			$discoveryProbeKinds,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function generatedFleet(): array
	{
		/** @var array<string, string> $files */
		$files = require self::GeneratorFile;

		return $files;
	}

	/**
	 * @return list<string>
	 */
	private function fleetClassNames(): array
	{
		$classNames = [];
		foreach (array_keys($this->generatedFleet()) as $file) {
			$classNames[] = self::FleetNamespace . substr($file, 0, -4);
		}

		return $classNames;
	}

	/**
	 * @param list<string> $classNames
	 * @return array<string, string>
	 */
	private function canonicalHashes(array $classNames): array
	{
		$walk = $this->freshWalk();

		$hashes = [];
		foreach ($classNames as $className) {
			$hashes[$className] = $walk->factsFor($className)->getCanonicalHash();
		}

		return $hashes;
	}

	private function freshWalk(): PhpRenderWalk
	{
		$appRoot = realpath(self::FleetDir);
		self::assertNotFalse($appRoot);

		/** @var Parser $parser */
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');

		$formulas = [];
		foreach (['01', '02', '03', '04', '05'] as $nn) {
			$formulas[self::FleetNamespace . 'FleetFallback' . $nn . 'Control'] = [
				'formula' => 'dirname-templates-lcfirst-fallback',
				'sharedFallback' => '@fleetShared' . $nn . '.latte',
			];
		}

		return new PhpRenderWalk(
			self::createReflectionProvider(),
			$parser,
			[$appRoot],
			new TemplateFactoryDefaultResolver(null),
			new DiscoveryResolver(self::MappingLoaderFile, $formulas, $appRoot),
		);
	}

}
