<?php declare(strict_types = 1);

use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\FixturePresenterMappingContainer;

require_once __DIR__ . '/../../../../../tests/autoload.php';

return [
	'fixture' => new FixturePresenterMappingContainer([
		'Fleet' => 'Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Fleet\*Presenter',
	]),
];
