<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support;

final class FixtureTemplate
{

	public string $title = '';

	public ?int $count = null;

	/** @var array<string> */
	public array $tags = [];

	/** @var array<int, string> */
	public array $labels = [];

}
