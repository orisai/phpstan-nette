<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{

	use VersionGroupGate;

	/** @var bool */
	protected $preserveGlobalState = false; // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint

}
