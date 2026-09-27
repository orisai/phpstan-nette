<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Graph;

final class StructuralContext
{

	public const KIND_BRANCH = 'branch';

	public const KIND_LOOP = 'loop';

	public const KIND_TRY = 'try';

	/** @var list<array{kind: string, key: string}> */
	private array $frames;

	/** @param list<array{kind: string, key: string}> $frames */
	public function __construct(array $frames)
	{
		$this->frames = $frames;
	}

	public function isConditional(): bool
	{
		return $this->frames !== [];
	}

}
