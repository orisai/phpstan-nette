<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use function dirname;
use function glob;

final class FormShapeCorpusPass extends FormShapeTestCase
{

	public function bootContainer(): void
	{
		self::getContainer();
	}

	public function analyseCorpus(FormShapeCache $cache): void
	{
		$files = glob(dirname(__DIR__) . '/Doubles/Forms/MatrixAssert/*.php');
		foreach ($files === false ? [] : $files as $file) {
			$this->captureFixtureShapes($file, $cache);
		}
	}

}
