<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory\Fixtures;

use Nette\Bridges\ApplicationLatte\Template;

// Declares none of the factory's own keys, so property_exists() answers false for every one of them.
final class ParityFlashlessTemplate extends Template
{

	/** @var string */
	public $declared = 'own';

}
