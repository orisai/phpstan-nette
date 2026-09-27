<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Component;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_keys;
use function dirname;
use function preg_match_all;
use function sort;
use function strpos;

final class ComponentDependsOnFormsOnlyThroughCertaintyTest extends BaseTestCase
{

	public function testTheOnlyFormsImportIsCertainty(): void
	{
		$imports = [];
		$files = 0;
		foreach (Finder::findFiles('*.php')->from(dirname(__DIR__, 3) . '/src/Component') as $file) {
			$files++;
			preg_match_all('~^use\s+(?:function\s+|const\s+)?([^;\s]+)~m', FileSystem::read((string) $file), $matches);
			foreach ($matches[1] as $import) {
				if (strpos($import, 'OriPhpstan\\Nette\\Forms\\') === 0) {
					$imports[$import] = true;
				}
			}
		}

		self::assertGreaterThan(0, $files);
		$imports = array_keys($imports);
		sort($imports);
		self::assertSame([Certainty::class], $imports);
	}

}
