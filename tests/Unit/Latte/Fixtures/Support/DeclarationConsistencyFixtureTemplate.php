<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Support;

use RuntimeException;
use Throwable;

final class DeclarationConsistencyFixtureTemplate
{

	public string $exact;

	public Throwable $narrow;

	public string $unrelated;

	public RuntimeException $wide;

	public $untyped;

}
