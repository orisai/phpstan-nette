<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Toolkit;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function dirname;
use const PHP_BINARY;

final class VersionGroupGateCoverageTest extends BaseTestCase
{

	// Loading every test class here would leave them declared in a reused paratest worker, which then
	// cannot load those files as tests; reflection therefore runs in a child process.
	private const SCAN = <<<'PHP'
[, $testsDir] = $argv;
require $testsDir . '/autoload.php';
$ungated = [];
$checked = 0;
foreach (\Nette\Utils\Finder::findFiles('*Test.php')->from($testsDir)->exclude('Fixtures') as $file) {
	$relative = (string) substr((string) $file, strlen($testsDir) + 1, -4);
	$className = 'Tests\\OriPhpstan\\Nette\\' . str_replace('/', '\\', $relative);
	if (!class_exists($className)) {
		$ungated[] = $className . ' (not autoloadable)';
		continue;
	}
	$class = new ReflectionClass($className);
	if (!$class->isSubclassOf(\PHPUnit\Framework\TestCase::class)) {
		continue;
	}
	$checked++;
	for ($current = $class; $current !== false; $current = $current->getParentClass()) {
		if (in_array(\Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate::class, class_uses($current->getName()), true)) {
			continue 2;
		}
	}
	$ungated[] = $className;
}
sort($ungated);
echo json_encode(['checked' => $checked, 'ungated' => $ungated]);
PHP;

	public function testEveryTestClassIsGatedByVersionGroups(): void
	{
		$process = new Process([PHP_BINARY, '-r', self::SCAN, '--', dirname(__DIR__, 2)]);
		$process->mustRun();

		/** @var array{checked: int, ungated: list<string>} $result */
		$result = Json::decode($process->getOutput(), Json::FORCE_ARRAY);

		self::assertSame([], $result['ungated']);
		self::assertGreaterThan(200, $result['checked']);
	}

}
