<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{

	/** @var bool */
	// phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
	protected $preserveGlobalState = false;

}
