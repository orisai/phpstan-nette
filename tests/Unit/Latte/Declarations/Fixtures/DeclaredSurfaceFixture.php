<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures;

/**
 * @property string $virtualOnly
 */
final class DeclaredSurfaceFixture extends DeclaredSurfaceFixtureParent
{

	public int $typedNoDefault;

	public string $defaulted = '';

	/** @var string */
	public $docblockTyped;

}
