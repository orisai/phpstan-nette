<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Latte3;

use Nette\Utils\FileSystem;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\InstalledVersionsGuard;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

// Latte 3 resolves filter and function names case-sensitively: a spelling which differs from the
// registered one only in case is reported and typed as unknown. Latte 3.0 still resolves such a
// function with a compile warning, so the call keeps its real signature there.
/**
 * @group latte3
 */
final class CaseSensitiveNamesSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('case-sensitive-names');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testMisCasedFiltersAndFunctionsAreReported(): void
	{
		$this->project->write('src/names.latte', FileSystem::read(__DIR__ . '/Fixtures/case-sensitive/names.latte'));

		$filter = static fn (string $written, string $registered): string => "orisaiNette.latte.filterCaseMismatch Latte filter '$written' differs in case from the registered '$registered' - Latte 3 resolves filter names case-sensitively.";
		$function = InstalledVersionsGuard::latteLine() === '3.0'
			? static fn (string $written, string $registered): string => "orisaiNette.latte.functionCaseMismatch Latte function '$written' differs in case from the registered '$registered' - Latte 3.0 resolves it with a warning, Latte 3.1 does not resolve it."
			: static fn (string $written, string $registered): string => "orisaiNette.latte.functionCaseMismatch Latte function '$written' differs in case from the registered '$registered' - Latte 3 resolves function names case-sensitively.";

		self::assertSame(
			[
				'2 ' . $filter('firstupper', 'firstUpper'),
				'4 ' . $filter('shoutED', 'shouted'),
				'6 ' . $function('LengthOf', 'lengthOf'),
				...InstalledVersionsGuard::latteLine() === '3.0'
					? ['6 argument.type Parameter #1 $string of function strlen expects string, array given.']
					: [],
				'8 ' . $function('Repeated', 'repeated'),
				"10 orisaiNette.latte.unknownFilter Unknown Latte filter 'nope'.",
			],
			$this->analyse(),
		);
	}

	/**
	 * @return list<string>
	 */
	private function analyse(): array
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisaiNette' => ['latte' => [
					'enabled' => true,
					'engineLoader' => __DIR__ . '/Fixtures/case-sensitive/engine-loader.php',
				]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		return $findings;
	}

}
