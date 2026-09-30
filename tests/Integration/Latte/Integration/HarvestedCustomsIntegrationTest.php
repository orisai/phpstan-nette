<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Utils\FileSystem;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use function str_replace;
use function uniqid;
use const PHP_BINARY;

// Real-app-style spawn (production wiring, not PipelineFactory): proves the harvested-filter/
// function consumption end to end - a fixture engine-loader registers a typed filter and function
// (Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTypedCustoms), and the spawned analysis run
// must resolve them to the REAL signature (a wrong-arg-type call reports the underlying method's
// own argument.type error, not orisai.nette.latte.unknownFilter/a bare "function not found") while an unrelated,
// genuinely unregistered name keeps reporting exactly what it reports today.
/**
 * @group latte2
 */
final class HarvestedCustomsIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const TYPED_CUSTOMS_ENGINE_LOADER = __DIR__ . '/../../../Unit/Latte/Customs/Fixtures/engine-loader-typed-customs.php';

	public function testHarvestedFilterWrongArgTypeReportsTheRealSignatureErrorNotUnknownFilter(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType string \$s}\n{\$s|myFilter}\n");

		self::assertStringContainsString('myFilter', $output);
		self::assertStringContainsString('int', $output);
		self::assertStringNotContainsString('orisai.nette.latte.unknownFilter', $output);
		self::assertStringNotContainsString('Unknown Latte filter', $output);
	}

	public function testHarvestedFilterCorrectArgTypeCompilesClean(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType int \$n}\n{\$n|myFilter}\n");

		self::assertSame('(no errors)', $output);
	}

	public function testUnknownFilterStillReportsUnknownFilterEvenWithATypedCustomsSourceConfigured(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType string \$s}\n{\$s|definitelyNotARealFilterXyz}\n");

		self::assertStringContainsString('Unknown Latte filter', $output);
	}

	public function testHarvestedFunctionWrongArgTypeReportsTheRealSignatureErrorNotFunctionNotFound(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType string \$s}\n{myFunction(\$s)}\n");

		self::assertStringContainsString('myFunction', $output);
		self::assertStringContainsString('int', $output);
		self::assertStringNotContainsString('not found', $output);
	}

	public function testUnknownFunctionStillReportsFunctionNotFound(): void
	{
		$output = $this->spawnWithTypedCustoms("{definitelyNotARealFunctionXyz()}\n");

		self::assertStringContainsString('definitelyNotARealFunctionXyz', $output);
		self::assertStringContainsString('not found', $output);
	}

	public function testHarvestedFilterCaseMismatchIsReportedWithBothSpellings(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType int \$n}\n{\$n|MyFilter}\n");

		self::assertStringContainsString('differs in case', $output);
		self::assertStringContainsString('MyFilter', $output);
		self::assertStringContainsString('myFilter', $output);
	}

	public function testHarvestedFunctionCaseMismatchIsReportedWithBothSpellings(): void
	{
		$output = $this->spawnWithTypedCustoms("{varType int \$n}\n{MyFunction(\$n)}\n");

		self::assertStringContainsString('differs in case', $output);
		self::assertStringContainsString('MyFunction', $output);
		self::assertStringContainsString('myFunction', $output);
	}

	public function testBuiltInStockFunctionCaseMismatchIsReportedWithoutAnyCustomsSourceConfigured(): void
	{
		$output = $this->spawn("{varType int \$n}\n{Clamp(\$n, 0, 10)}\n", []);

		self::assertStringContainsString('differs in case', $output);
		self::assertStringContainsString('Clamp', $output);
		self::assertStringContainsString('clamp', $output);
		self::assertStringNotContainsString('Warning:', $output);
	}

	public function testBuiltInStockFilterCaseMismatchIsReportedWithoutAnyCustomsSourceConfigured(): void
	{
		$output = $this->spawn("{varType string \$s}\n{\$s|Upper}\n", []);

		self::assertStringContainsString('differs in case', $output);
		self::assertStringContainsString('Upper', $output);
		self::assertStringContainsString('upper', $output);
	}

	private function spawnWithTypedCustoms(string $latte): string
	{
		return $this->spawn($latte, ['orisai.nette.latte.engineLoader' => self::TYPED_CUSTOMS_ENGINE_LOADER]);
	}

	/**
	 * @param array<string, bool|int|string|list<string>> $extraParameters
	 */
	private function spawn(string $latte, array $extraParameters): string
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-harvested-customs-test-' . uniqid('', true);
		FileSystem::createDir($scratch);
		$srcDir = $scratch . '/src';
		FileSystem::write($srcDir . '/target.latte', $latte);

		try {
			$isolated = LattePhpstanConfig::create(
				self::REAL_CONFIG_PATH,
				[$srcDir],
				$scratch . '/pstmp',
				$extraParameters,
			);

			try {
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
				$process->setTimeout(120.0);
				$process->run();

				$output = str_replace($projectRoot . '/', '', $process->getOutput());

				return $output === '' ? '(no errors)' : $output;
			} finally {
				$isolated->cleanup();
			}
		} finally {
			FileSystem::delete($scratch);
		}
	}

}
