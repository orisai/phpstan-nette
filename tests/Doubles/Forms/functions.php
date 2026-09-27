<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms;

/**
 * @param literal-string $id
 * @param array<int|string, mixed> $parameters
 */
function t(string $id, array $parameters = [], ?string $locale = null): string
{
	return $id;
}
