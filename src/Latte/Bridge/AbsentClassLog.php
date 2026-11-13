<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use function array_keys;
use function array_pop;
use function ltrim;
use function sort;
use const SORT_STRING;

final class AbsentClassLog
{

	/** @var list<array<string, true>> */
	private array $frames = [];

	public function begin(): void
	{
		$this->frames[] = [];
	}

	/**
	 * @return list<string>
	 */
	public function end(): array
	{
		$names = array_keys(array_pop($this->frames) ?? []);
		sort($names, SORT_STRING);

		return $names;
	}

	public function record(string $className): void
	{
		$className = ltrim($className, '\\');
		foreach ($this->frames as $index => $_) {
			$this->frames[$index][$className] = true;
		}
	}

}
