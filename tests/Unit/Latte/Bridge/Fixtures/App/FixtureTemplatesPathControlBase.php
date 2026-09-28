<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use ReflectionClass;
use function assert;
use function dirname;
use function file_exists;
use function lcfirst;
use const DIRECTORY_SEPARATOR;

// Replica of the app's templates-subdir control base (dirname + templates + lcfirst convention).
abstract class FixtureTemplatesPathControlBase extends Control
{

	private ?string $file = null;

	public function createTemplate(): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);
		$template->setFile($this->getTemplateFilePath());

		return $template;
	}

	protected function getTemplateFilePath(): string
	{
		if ($this->file !== null && file_exists($this->file)) {
			return $this->file;
		}

		$reflector = new ReflectionClass($this);
		if (!$this->file) {
			$this->file = $reflector->getShortName();
		}

		return dirname($reflector->getFileName()) . DIRECTORY_SEPARATOR . 'templates'
			. DIRECTORY_SEPARATOR
			. lcfirst($this->file) . '.latte';
	}

}
