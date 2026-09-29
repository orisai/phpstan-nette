<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

// The class named by a header {varType} is checked by PHPStan itself, as the type of the injected
// parameter: the finding is class.notFound on the template, whichever Latte compiled it.
final class UnknownVarTypeClassSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('unknown-vartype-class');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testUnknownVarTypeClassIsReportedOnTheTemplate(): void
	{
		$this->project->write('src/unknown.latte', "{varType UnknownClass \$x}\n{\$x}\n");

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => ['latte' => ['enabled' => true]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if ($message['identifier'] === 'class.notFound') {
				$findings[] = $message['line'] . ' ' . $message['message'];
			}
		}

		// Latte 3 compiles the head into prepare(), which the injector folds into latteMain, so only
		// Latte 2 keeps a typed lattePrepare to report a second time.
		$expected = [
			'2 Parameter $x of method LatteTpl_src_unknown_latte_968817c8::latteMain_ctx0() has invalid type UnknownClass.',
		];
		if (InstalledVersionsGuard::latteMajor() === 2) {
			$expected[] = '2 Parameter $x of method LatteTpl_src_unknown_latte_968817c8::lattePrepare() has invalid type UnknownClass.';
		}

		self::assertSame($expected, $findings);
	}

}
