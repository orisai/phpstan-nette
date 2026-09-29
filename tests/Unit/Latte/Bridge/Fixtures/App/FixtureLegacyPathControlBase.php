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

// Replica of the app's legacy samedir control base (dirname + lcfirst convention).
class FixtureLegacyPathControlBase extends Control
{

	/** @var string|null */
	protected $file;

	public function createTemplate(?string $class = null): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);
		$template->setFile($this->getTemplateFilePath());

		return $template;
	}

	/**
	 * @return string
	 */
	protected function getTemplateFilePath()
	{
		if ($this->file !== null && file_exists($this->file)) {
			return $this->file;
		}

		$reflector = new ReflectionClass($this);
		if (!$this->file) {
			$this->file = $reflector->getShortName();
		}

		return dirname($reflector->getFileName()) . DIRECTORY_SEPARATOR . lcfirst($this->file) . '.latte';
	}

}
