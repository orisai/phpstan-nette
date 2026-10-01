<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use LogicException;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use function dirname;
use function glob;
use function sprintf;

final class FormShapeCorpusPass extends FormShapeTestCase
{

	public function bootContainer(): void
	{
		self::getContainer();
	}

	public function analyseCorpus(FormShapeCache $cache): void
	{
		$pattern = dirname(__DIR__) . '/Doubles/Forms/MatrixAssert/*.php';
		$files = glob($pattern);
		if ($files === false || $files === []) {
			throw new LogicException(sprintf('The form shape corpus %s is empty.', $pattern));
		}

		foreach ($files as $file) {
			$this->captureFixtureShapes($file, $cache);
		}
	}

}
