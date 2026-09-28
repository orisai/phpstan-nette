<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use function is_file;

// Replica of the app's shared-fallback grid/form locators: the parent templates-subdir derivation,
// then an is_file-gated fixed shared fallback, then the derived path anyway. The app spells the
// fallback as __DIR__ . '/../BaseControl/templates/...' - a self-cancelling hop the mirror records
// in its normalized declaring-dir form.
abstract class FixtureFallbackControlBase extends FixtureTemplatesPathControlBase
{

	protected function getTemplateFilePath(): string
	{
		$file = parent::getTemplateFilePath();
		if (is_file($file)) {
			return $file;
		}

		$tmpFile = __DIR__ . '/templates/@fixtureShared.latte';
		if (is_file($tmpFile)) {
			return $tmpFile;
		}

		return $file;
	}

}
