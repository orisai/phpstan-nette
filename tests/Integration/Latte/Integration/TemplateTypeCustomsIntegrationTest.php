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

// Real-app-style spawn (production wiring): proves Latte's NATIVE per-template customs mechanism
// (Engine::processParams()) end to end, against the committed
// Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTemplateTypeCustoms fixture (a plain
// {templateType} class, no engine-loader/harvest source involved - this channel is independent of
// this global harvest). The scoping pin (a deliberate divergence from the runtime's
// shared-engine leak): a template DECLARING the {templateType} sees its filter/function; an
// unrelated template using the identical name never does, even in the SAME spawn/analysed set.
final class TemplateTypeCustomsIntegrationTest extends BaseTestCase
{

	private const REAL_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const DECLARING_CLASS = 'Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureTemplateTypeCustoms';

	private const STATIC_METHOD_CLASS = 'Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture';

	public function testDeclaringTemplateWrongArgTypeReportsTheRealSignatureErrorNotUnknownFilter(): void
	{
		$output = $this->spawnSingle(
			'{templateType ' . self::DECLARING_CLASS . "}\n{varType string \$s}\n{\$s|myTplFilter}\n",
		);

		self::assertStringContainsString('myTplFilter', $output);
		self::assertStringContainsString('int', $output);
		self::assertStringNotContainsString('Unknown Latte filter', $output);
	}

	public function testDeclaringTemplateCorrectArgTypeCompilesClean(): void
	{
		$output = $this->spawnSingle(
			'{templateType ' . self::DECLARING_CLASS . "}\n{varType int \$n}\n{\$n|myTplFilter}\n",
		);

		self::assertSame('(no errors)', $output);
	}

	public function testDeclaringTemplateWrongArgTypeFunctionReportsTheRealSignatureErrorNotFunctionNotFound(): void
	{
		$output = $this->spawnSingle(
			'{templateType ' . self::DECLARING_CLASS . "}\n{varType string \$s}\n{myTplFunction(\$s)}\n",
		);

		self::assertStringContainsString('myTplFunction', $output);
		self::assertStringContainsString('int', $output);
		self::assertStringNotContainsString('not found', $output);
	}

	// The scoping pin, in one spawn: TWO templates in the SAME analysed set, only one of them
	// declaring {templateType FixtureTemplateTypeCustoms} - the OTHER, calling the identical filter
	// name, must still report orisaiNette.latte.unknownFilter. Cross-template leakage here would mean the
	// per-template overlay escaped its declaring compiled class - exactly the boundary this
	// scoping pin requires to hold.
	public function testUnrelatedTemplateInTheSameAnalysedSetStillReportsUnknownFilter(): void
	{
		$output = $this->spawnPair(
			'{templateType ' . self::DECLARING_CLASS . "}\n{varType int \$n}\n{\$n|myTplFilter}\n",
			"{varType int \$n}\n{\$n|myTplFilter}\n",
		);

		self::assertStringContainsString("unrelated.latte:2:Unknown Latte filter 'myTplFilter'.", $output);
		self::assertStringNotContainsString('declaring.latte', $output);
	}

	public function testUnrelatedTemplateInTheSameAnalysedSetStillReportsFunctionNotFound(): void
	{
		$output = $this->spawnPair(
			'{templateType ' . self::DECLARING_CLASS . "}\n{varType int \$n}\n{myTplFunction(\$n)}\n",
			"{varType int \$n}\n{myTplFunction(\$n)}\n",
		);

		self::assertStringContainsString('unrelated.latte:2:', $output);
		self::assertStringContainsString('not found', $output);
		self::assertStringNotContainsString('declaring.latte', $output);
	}

	// docStaticFilter (ProcessParamsQualificationFixture) is a public
	// STATIC method tagged @filter - qualification is already parity-probed
	// (ProcessParamsQualificationParityTest) and the dispatch NODE SHAPE is unit-tested for the
	// instance case (FilterRewriterTest, using docFilter), but neither proves PHPStan itself raises
	// no diagnostic for calling a static method through `->` on a generic-templated receiver
	// (Helpers::templateTypeInstance($class)->docStaticFilter(...)) at real strict level 8 - only a
	// full spawn does.
	public function testStaticPerTemplateFilterDispatchesCleanlyThroughARealSpawn(): void
	{
		$output = $this->spawnSingle(
			'{templateType ' . self::STATIC_METHOD_CLASS . "}\n{varType string \$s}\n{\$s|docStaticFilter}\n",
		);

		self::assertSame('(no errors)', $output);
	}

	private function spawnSingle(string $latte): string
	{
		return $this->spawnFiles(['target.latte' => $latte]);
	}

	private function spawnPair(string $declaringLatte, string $unrelatedLatte): string
	{
		return $this->spawnFiles([
			'declaring.latte' => $declaringLatte,
			'unrelated.latte' => $unrelatedLatte,
		]);
	}

	/**
	 * @param array<string, string> $filesByName
	 */
	private function spawnFiles(array $filesByName): string
	{
		$projectRoot = dirname(__DIR__, 4);
		$scratch = $projectRoot . '/var/tmp/latte-template-type-customs-test-' . uniqid('', true);
		$srcDir = $scratch . '/src';
		FileSystem::createDir($srcDir);

		foreach ($filesByName as $name => $content) {
			FileSystem::write($srcDir . '/' . $name, $content);
		}

		try {
			$isolated = LattePhpstanConfig::create(self::REAL_CONFIG_PATH, [$srcDir], $scratch . '/pstmp');

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
