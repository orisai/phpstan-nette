<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Support;

use function array_key_exists;
use function array_key_first;
use function count;

final class BoundedMap
{

	private int $limit;

	/** @var array<string, mixed> */
	private array $entries = [];

	public function __construct(int $limit)
	{
		$this->limit = $limit;
	}

	public function has(string $key): bool
	{
		return array_key_exists($key, $this->entries);
	}

	/**
	 * @return mixed
	 */
	public function get(string $key)
	{
		if (!array_key_exists($key, $this->entries)) {
			return null;
		}

		$value = $this->entries[$key];
		unset($this->entries[$key]);
		$this->entries[$key] = $value;

		return $value;
	}

	/**
	 * @param mixed $value
	 */
	public function set(string $key, $value): void
	{
		unset($this->entries[$key]);
		$this->entries[$key] = $value;
		if (count($this->entries) > $this->limit) {
			unset($this->entries[array_key_first($this->entries)]);
		}
	}

	public function clear(): void
	{
		$this->entries = [];
	}

}
