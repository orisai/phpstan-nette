<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Toolkit;

use Latte\Engine;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function array_column;

final class ScratchProjectVendorTest extends BaseTestCase
{

	public function testSpawnReflectsTheInstalledVendor(): void
	{
		$project = ScratchProject::create('scratch-vendor');
		try {
			$project->write('src/version.php', "<?php\n\n\\PHPStan\\dumpType(\\Latte\\Engine::VERSION);\n");
			$result = $project->analyse([], ['src']);
		} finally {
			$project->cleanup();
		}

		self::assertSame([], $result['errors'], $result['stderr']);
		self::assertContains("Dumped type: '" . Engine::VERSION . "'", array_column($result['messages'], 'message'));
	}

}
