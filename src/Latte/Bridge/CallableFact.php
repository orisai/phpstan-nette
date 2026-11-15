<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use function array_merge;
use function strcmp;
use function usort;

final class CallableFact
{

	public const KIND_STATIC = 'static';

	public const KIND_INSTANCE = 'instance';

	public const KIND_FUNCTION = 'function';

	public const KIND_STUB = 'stub';

	public const KIND_UNTYPED = 'untyped';

	public const OPEN_DYNAMIC_NAME = 'dynamic name';

	public const OPEN_LOADER = 'filter loader';

	private string $name;

	/** @var self::KIND_* */
	private string $kind;

	private string $target;

	private bool $contentAware;

	/** @var Certainty::* */
	private string $certainty;

	/** @var list<array{file: string, line: int}> */
	private array $sites;

	/**
	 * @param self::KIND_* $kind
	 * @param Certainty::* $certainty
	 * @param list<array{file: string, line: int}> $sites
	 */
	public function __construct(
		string $name,
		string $kind,
		string $target,
		bool $contentAware,
		string $certainty,
		array $sites
	)
	{
		$this->name = $name;
		$this->kind = $kind;
		$this->target = $target;
		$this->contentAware = $contentAware;
		$this->certainty = $certainty;
		usort($sites, static function (array $a, array $b): int {
			$byFile = strcmp($a['file'], $b['file']);

			return $byFile !== 0 ? $byFile : $a['line'] <=> $b['line'];
		});
		$this->sites = $sites;
	}

	/**
	 * @param array{name: string, kind: self::KIND_*, target: string, contentAware: bool, certainty: Certainty::*, sites: list<array{file: string, line: int}>} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			$data['name'],
			$data['kind'],
			$data['target'],
			$data['contentAware'],
			$data['certainty'],
			$data['sites'],
		);
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	public function getTarget(): string
	{
		return $this->target;
	}

	public function isContentAware(): bool
	{
		return $this->contentAware;
	}

	/**
	 * @return Certainty::*
	 */
	public function getCertainty(): string
	{
		return $this->certainty;
	}

	/**
	 * @return list<array{file: string, line: int}>
	 */
	public function getSites(): array
	{
		return $this->sites;
	}

	private function describesSameCallable(self $other): bool
	{
		return $this->kind === $other->kind
			&& $this->target === $other->target
			&& $this->contentAware === $other->contentAware;
	}

	// Registrations of one name have no order the analysis can prove - from different methods of one
	// class, or from different renderers of one template - so a name registered with two different
	// callables keeps only the name.
	public function mergedWith(self $other): self
	{
		$same = $this->describesSameCallable($other);
		/** @var Certainty::* $certainty */
		$certainty = Certainty::join($this->certainty, $other->certainty);

		return new self(
			$this->name,
			$same ? $this->kind : self::KIND_UNTYPED,
			$same ? $this->target : '',
			$same && $this->contentAware,
			$certainty,
			array_merge($this->sites, $other->sites),
		);
	}

	/**
	 * @return array{name: string, kind: self::KIND_*, target: string, contentAware: bool, certainty: Certainty::*, sites: list<array{file: string, line: int}>}
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->name,
			'kind' => $this->kind,
			'target' => $this->target,
			'contentAware' => $this->contentAware,
			'certainty' => $this->certainty,
			'sites' => $this->sites,
		];
	}

}
