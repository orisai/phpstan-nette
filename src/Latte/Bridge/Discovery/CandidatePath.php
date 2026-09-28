<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

/**
 * @phpstan-type CandidateShape array{path: string, exists: bool, chosen: bool, kind: self::KIND_*}
 */
final class CandidatePath
{

	public const KIND_FORMULA = 'formula';

	public const KIND_LAYOUT = 'layout';

	public const KIND_SET_FILE = 'setFile';

	public const KIND_CONVENTION = 'convention';

	private string $path;

	private bool $exists;

	private bool $chosen;

	/** @var self::KIND_* */
	private string $kind;

	/**
	 * @param self::KIND_* $kind
	 */
	public function __construct(string $path, bool $exists, bool $chosen, string $kind)
	{
		$this->path = $path;
		$this->exists = $exists;
		$this->chosen = $chosen;
		$this->kind = $kind;
	}

	/**
	 * @param CandidateShape $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['path'], $data['exists'], $data['chosen'], $data['kind']);
	}

	public function getPath(): string
	{
		return $this->path;
	}

	public function exists(): bool
	{
		return $this->exists;
	}

	public function isChosen(): bool
	{
		return $this->chosen;
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	/**
	 * @return CandidateShape
	 */
	public function toArray(): array
	{
		return [
			'path' => $this->path,
			'exists' => $this->exists,
			'chosen' => $this->chosen,
			'kind' => $this->kind,
		];
	}

}
