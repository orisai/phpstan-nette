<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function explode;
use function implode;
use function rtrim;
use function sort;
use function str_replace;
use function uniqid;
use const PHP_BINARY;
use const SORT_STRING;

// End-to-end proof that orisaiNette.latte.reportWrongPhpDocTypeInVarType and orisaiNette.latte.reportAnyTypeWideningInVarType
// reach LatteVarTypeExpressionRule through DI - LatteVarTypeExpressionRuleTest pins the checker's own
// behaviour by constructing it directly, which cannot catch a parameter wired to the wrong argument.
// Each fixture is analysed twice, its own flag off then on; the OTHER flag stays on both times, so a
// swapped pair of constructor arguments changes the answer.
final class VarTypeExpressionFlagTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const WIDENING_PARAMETER = 'orisaiNette.latte.reportAnyTypeWideningInVarType';

	private const WRONG_PHPDOC_TYPE_PARAMETER = 'orisaiNette.latte.reportWrongPhpDocTypeInVarType';

	public function testWideningOptionOffIsSilentAndOnReportsTheWidenedDeclaration(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-vartype-widening-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		FileSystem::write(
			$srcDir . '/widening.latte',
			"{var \$q = 1}\n{varType int \$widened}\n{var \$widened = \$q}\n{\$widened}\n",
		);

		try {
			self::assertSame(
				"(no errors)\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/off', self::WIDENING_PARAMETER, false),
				'core leniency: a constant-valued expression may be declared as its own wider type',
			);
			self::assertSame(
				"$relSrc/widening.latte:2:{varType} for \$widened with type int is not subtype of"
					. " native type 1.\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/on', self::WIDENING_PARAMETER, true),
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	public function testWrongPhpDocTypeOptionOffIsSilentAndOnReportsTheConflictingDeclaration(): void
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-vartype-phpdoc-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		$relSrc = str_replace($projectRoot . '/', '', $srcDir);

		// The source's NATIVE return type is the bare `array` the {varType} narrows perfectly well;
		// only its PHPDoc one conflicts, which is exactly the split this option gates.
		FileSystem::write(
			$srcDir . '/phpdoc-type.latte',
			'{var $rows = \Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support\VarTypeExpressionFixtureSource::rows()}'
				. "\n{varType array<int> \$nums}\n{var \$nums = \$rows}\n{count(\$nums)}\n",
		);

		try {
			self::assertSame(
				"(no errors)\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/off', self::WRONG_PHPDOC_TYPE_PARAMETER, false),
			);
			self::assertSame(
				"$relSrc/phpdoc-type.latte:2:{varType} for \$nums with type array<int> is not subtype of"
					. " type array<string>.\n",
				$this->spawn($projectRoot, $srcDir, $scratch . '/on', self::WRONG_PHPDOC_TYPE_PARAMETER, true),
			);
		} finally {
			FileSystem::delete($scratch);
		}
	}

	private function spawn(
		string $projectRoot,
		string $srcDir,
		string $tmpDir,
		string $parameter,
		bool $enabled
	): string
	{
		$isolated = LattePhpstanConfig::create(
			self::REAL_CONFIG_PATH,
			[$srcDir],
			$tmpDir,
			[$parameter => $enabled],
		);

		$process = new Process(
			[
				PHP_BINARY,
				$projectRoot . '/' . VendorDirectory::name() . '/bin/phpstan',
				'analyse',
				'--no-progress',
				'--level=8',
				'--error-format=raw',
				'-c',
				$isolated->getConfigPath(),
			],
			$projectRoot,
		);
		$process->run();

		$lines = [];
		foreach (explode("\n", str_replace($projectRoot . '/', '', $process->getOutput())) as $line) {
			$line = rtrim($line);
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		if ($lines === []) {
			return "(no errors)\n";
		}

		sort($lines, SORT_STRING);

		return implode("\n", $lines) . "\n";
	}

}
