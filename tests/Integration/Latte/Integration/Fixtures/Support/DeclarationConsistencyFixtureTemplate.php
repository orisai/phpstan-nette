<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration\Fixtures\Support;

use Throwable;

final class DeclarationConsistencyFixtureTemplate
{

	public string $exact;

	public Throwable $narrow;

	public string $unrelated;

}
