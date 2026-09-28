<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration\Fixtures\Support;

/**
 * @property string $virtualOnly
 */
final class DeclaredParametersFixtureTemplate
{

	public int $typedNoDefault;

	public string $defaulted = '';

	/** @var string */
	public $docblockTyped;

}
