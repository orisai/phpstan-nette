<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use LogicException;
use function count;
use function implode;
use function in_array;
use function sprintf;

// The generated-code shapes one consumer matches on one shape family: static calls grouped by the
// role they play for the consumer, and names (classes, functions, callables, local variables) per
// role. A role absent from both maps is a shape the family never generates.
final class PatternSet
{

	/** @var array<string, array<string, list<string>>> */
	private array $staticCalls;

	/** @var array<string, list<string>> */
	private array $names;

	/**
	 * @param array<string, array<string, list<string>>> $staticCalls
	 * @param array<string, list<string>> $names
	 */
	public function __construct(array $staticCalls = [], array $names = [])
	{
		$this->staticCalls = $staticCalls;
		$this->names = $names;
	}

	public function isStaticCall(string $role, string $class, string $method): bool
	{
		return in_array($method, $this->staticCalls[$role][$class] ?? [], true);
	}

	public function has(string $role): bool
	{
		return ($this->names[$role] ?? []) !== [] || ($this->staticCalls[$role] ?? []) !== [];
	}

	public function hasName(string $role, string $name): bool
	{
		return in_array($name, $this->names[$role] ?? [], true);
	}

	/**
	 * @return list<string>
	 */
	public function names(string $role): array
	{
		return $this->names[$role] ?? [];
	}

	public function name(string $role): string
	{
		$names = $this->names[$role] ?? [];
		if (count($names) !== 1) {
			throw new LogicException(sprintf('Role "%s" has %d names, exactly one expected.', $role, count($names)));
		}

		return $names[0];
	}

	public function describe(): string
	{
		$parts = [];
		foreach ($this->staticCalls as $role => $classes) {
			$calls = [];
			foreach ($classes as $class => $methods) {
				$calls[] = $class . '::' . implode('|', $methods);
			}

			$parts[] = $role . ': ' . implode(', ', $calls);
		}

		foreach ($this->names as $role => $names) {
			$parts[] = $role . ': ' . implode(', ', $names);
		}

		return implode('; ', $parts);
	}

}
