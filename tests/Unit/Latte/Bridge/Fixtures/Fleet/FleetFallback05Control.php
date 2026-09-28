<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet;

use Nette\Application\UI\Template;
use stdClass;
use function is_file;

final class FleetFallback05Control
{

	/** @var Template|stdClass */
	public $template;

	public function render(): void
	{
		$this->template->setFile($this->getTemplateFilePath());
	}

	private function getTemplateFilePath(): string
	{
		$file = __DIR__ . '/templates/fleetFallback05Control.latte';
		if (is_file($file)) {
			return $file;
		}

		$tmpFile = __DIR__ . '/templates/@fleetShared05.latte';
		if (is_file($tmpFile)) {
			return $tmpFile;
		}

		return $file;
	}

}
