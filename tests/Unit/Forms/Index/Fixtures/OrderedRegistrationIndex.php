<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

use PHPStan\File\FileFinder;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;

/**
 * Drives the fold with an explicit file order — FileFinder always sorts, so the production
 * enumeration cannot exercise fold-order independence.
 */
final class OrderedRegistrationIndex extends RegistrationIndex
{

	/** @var list<string> */
	private array $order;

	/**
	 * @param list<string> $analysedPaths
	 * @param list<string> $order
	 */
	public function __construct(array $analysedPaths, FileFinder $fileFinder, FileFactIndex $facts, array $order)
	{
		parent::__construct($analysedPaths, $fileFinder, $facts);
		$this->order = $order;
	}

	/**
	 * @return list<string>
	 */
	protected function enumerateUniverse(): array
	{
		return $this->order;
	}

}
