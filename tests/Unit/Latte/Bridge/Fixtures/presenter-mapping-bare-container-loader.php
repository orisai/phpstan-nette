<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixturePresenterMappingContainer;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

return new FixturePresenterMappingContainer([
	'Fixture' => 'Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\*Presenter',
]);
