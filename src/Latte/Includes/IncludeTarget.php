<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

final class IncludeTarget
{

	public const KIND_STATIC_FILE = 'file';

	public const KIND_STATIC_BLOCK = 'block';

	public const KIND_DYNAMIC = 'dynamic';

	// PHP-side discovery marker (TemplateEdgeIndex's store ingestion): an incoming-only edge whose
	// includer is a RENDERER CLASS NAME rather than a template path, carrying no variable payload.
	// Never produced by .latte extraction, so it never reaches outgoingSites()/checkSite().
	public const KIND_DISCOVERY = 'discovery';

	public const TAG_DISCOVERY = 'discovery';

	private string $tag;

	private string $kind;

	private string $rawTarget;

	private ?string $resolvedPath;

	private string $argsSource;

	private int $latteLine;

	public function __construct(
		string $tag,
		string $kind,
		string $rawTarget,
		?string $resolvedPath,
		string $argsSource,
		int $latteLine
	)
	{
		$this->tag = $tag;
		$this->kind = $kind;
		$this->rawTarget = $rawTarget;
		$this->resolvedPath = $resolvedPath;
		$this->argsSource = $argsSource;
		$this->latteLine = $latteLine;
	}

	/**
	 * @param array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			$data['tag'],
			$data['kind'],
			$data['rawTarget'],
			$data['resolvedPath'],
			$data['argsSource'],
			$data['latteLine'],
		);
	}

	public function getTag(): string
	{
		return $this->tag;
	}

	public function getKind(): string
	{
		return $this->kind;
	}

	public function getRawTarget(): string
	{
		return $this->rawTarget;
	}

	public function getResolvedPath(): ?string
	{
		return $this->resolvedPath;
	}

	public function getArgsSource(): string
	{
		return $this->argsSource;
	}

	public function getLatteLine(): int
	{
		return $this->latteLine;
	}

	/**
	 * @return array{tag: string, kind: string, rawTarget: string, resolvedPath: string|null, argsSource: string, latteLine: int}
	 */
	public function toArray(): array
	{
		return [
			'tag' => $this->tag,
			'kind' => $this->kind,
			'rawTarget' => $this->rawTarget,
			'resolvedPath' => $this->resolvedPath,
			'argsSource' => $this->argsSource,
			'latteLine' => $this->latteLine,
		];
	}

}
