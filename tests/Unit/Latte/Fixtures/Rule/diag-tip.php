<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

use OriPhpstan\Nette\Latte\Runtime\Diag;

Diag::report(
	'orisai.nette.latte.orphanTemplate',
	'Template is not reachable through any include, layout, or PHP render channel.',
	'Included only from templates that are themselves unreachable: dead.latte.',
);
