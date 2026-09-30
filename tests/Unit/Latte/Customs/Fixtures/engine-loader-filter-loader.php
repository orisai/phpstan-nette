<?php declare(strict_types = 1);

use Latte\Engine;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\FixtureLoaderFilters;

require_once __DIR__ . '/../../../../../tests/autoload.php';

$engine = new Engine();
$engine->addFilterLoader(
	static fn (string $name): ?array => $name === 'dyn' ? [FixtureLoaderFilters::class, 'dyn'] : null,
);
$engine->addFilterLoader(
	static fn (string $name): ?array => ['formatDyn' => [FixtureLoaderFilters::class, 'dyn']][$name] ?? null,
);

return $engine;
