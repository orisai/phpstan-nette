<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

use OriPhpstan\Nette\Latte\Runtime\Diag;

Diag::report('orisaiNette.latte.unknownMacro', "Unknown Latte macro or attribute 'g_'.");
