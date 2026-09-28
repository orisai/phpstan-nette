<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

use OriPhpstan\Nette\Latte\Runtime\Diag;

function nonConstantIdentifier(): string
{
	return 'dynamic';
}

Diag::report(nonConstantIdentifier(), 'message');
