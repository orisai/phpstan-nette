<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

final class NestedReplicatorArrayOk
{

	/** @var list<array<string, mixed>> */
	public array $items;

}
