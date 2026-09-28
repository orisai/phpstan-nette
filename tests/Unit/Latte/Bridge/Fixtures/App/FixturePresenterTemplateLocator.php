<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Application\Helpers;
use ReflectionClass;
use function assert;
use function dirname;

// Replica of the app's samedir presenter locator trait.
trait FixturePresenterTemplateLocator
{

	final public function formatTemplateFiles(): array
	{
		$name = $this->getName();
		assert($name !== null);

		[, $presenter] = Helpers::splitName($name);

		$file = (new ReflectionClass($this))->getFileName();
		assert($file !== false);

		$dir = dirname($file);

		return [
			"$dir/$presenter.$this->view.latte",
		];
	}

}
