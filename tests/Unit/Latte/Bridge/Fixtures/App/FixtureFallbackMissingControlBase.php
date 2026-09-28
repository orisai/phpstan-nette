<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use function is_file;

// Same shared-fallback shape as FixtureFallbackControlBase, but its fallback template is
// deliberately not committed - the default-to-first (derived path) branch.
abstract class FixtureFallbackMissingControlBase extends FixtureTemplatesPathControlBase
{

	protected function getTemplateFilePath(): string
	{
		$file = parent::getTemplateFilePath();
		if (is_file($file)) {
			return $file;
		}

		$tmpFile = __DIR__ . '/templates/@fixtureMissing.latte';
		if (is_file($tmpFile)) {
			return $tmpFile;
		}

		return $file;
	}

}
