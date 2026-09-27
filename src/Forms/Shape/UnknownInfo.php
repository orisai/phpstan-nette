<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

use function array_filter;
use function array_merge;
use function array_unique;
use function array_values;
use function sort;

// Two independent axes over the same reason vocabulary. The VALUE axis (getReasons) records build
// steps that lost a field's value; the NAME axis (getNameReasons) records build steps that added a
// component under a name the walk could not determine. They differ: addSubmit($dynamic) contributes
// no value at all, so it is not a value unknown, yet the component's NAME is lost and the shape's
// name set is no longer complete. Consumers that answer "does this component exist" must consult
// both; consumers that answer "what is this field's value" consult the value axis alone.
final class UnknownInfo
{

	/** @var list<string> */
	private array $reasons;

	/** @var list<string> */
	private array $nameReasons;

	/**
	 * @param list<string> $reasons
	 * @param list<string> $nameReasons
	 */
	public function __construct(array $reasons = [], array $nameReasons = [])
	{
		$this->reasons = $reasons;
		$this->nameReasons = $nameReasons;
	}

	public function hasUnknown(): bool
	{
		return $this->reasons !== [];
	}

	public function hasNameUnknown(): bool
	{
		return $this->nameReasons !== [];
	}

	/** @return list<string> */
	public function getReasons(): array
	{
		return $this->reasons;
	}

	/** @return list<string> */
	public function getNameReasons(): array
	{
		return $this->nameReasons;
	}

	public function withReason(string $reason): self
	{
		return new self(self::add($this->reasons, $reason), $this->nameReasons);
	}

	public function withNameReason(string $reason): self
	{
		return new self($this->reasons, self::add($this->nameReasons, $reason));
	}

	public function merge(self $other): self
	{
		return new self(
			self::union($this->reasons, $other->reasons),
			self::union($this->nameReasons, $other->nameReasons),
		);
	}

	// Value-axis only: every stripper is a compensation that re-resolved a field's VALUE from
	// another source (a factory, a parent builder), which says nothing about a name the walk
	// never saw.
	public function withoutReason(string $reason): self
	{
		return new self(
			array_values(array_filter(
				$this->reasons,
				static fn (string $existing): bool => $existing !== $reason,
			)),
			$this->nameReasons,
		);
	}

	/**
	 * @param list<string> $reasons
	 * @return list<string>
	 */
	private static function add(array $reasons, string $reason): array
	{
		$reasons[] = $reason;
		$reasons = array_values(array_unique($reasons));
		sort($reasons);

		return $reasons;
	}

	/**
	 * @param list<string> $left
	 * @param list<string> $right
	 * @return list<string>
	 */
	private static function union(array $left, array $right): array
	{
		// Both sides are kept sorted and deduped by construction, so an empty side needs no work at
		// all - the case every merge of two value-only shapes takes.
		if ($left === [] || $right === []) {
			return $left === [] ? $right : $left;
		}

		$merged = array_values(array_unique(array_merge($left, $right)));
		sort($merged);

		return $merged;
	}

}
