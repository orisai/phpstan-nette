<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\UI\Control;
use Nette\Bridges\ApplicationLatte\Template;
use ReflectionClass;
use function assert;
use function basename;
use function dirname;
use function is_file;
use function lcfirst;
use const DIRECTORY_SEPARATOR;

// Replica of the app's content control base: the template NAME comes from a class property, and
// only a null property derives a name - from the directory basename, never the short class name.
// The property default lives here so an entry subclass can override it, which is what makes the
// resolved default an entry-class question rather than a declaring-class one.
class FixturePropertyNameControlBase extends Control
{

	/** @var string|null */
	protected $layout = 'default';

	public function createTemplate(): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);
		$template->setFile($this->createFileName());

		return $template;
	}

	private function createFileName(): string
	{
		if ($this->layout && is_file($this->layout)) {
			return $this->layout;
		}

		$fileName = (new ReflectionClass($this))->getFileName();
		$path = dirname($fileName);
		$name = $this->layout ?? lcfirst(basename($path, '.php'));

		return $path . DIRECTORY_SEPARATOR . $name . '.latte';
	}

}
