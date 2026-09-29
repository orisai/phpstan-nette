<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{

	/** @var bool */
	protected $preserveGlobalState = false; // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint

	/**
	 * @before
	 */
	protected function skipUnmetVersionGroups(): void
	{
		$reason = InstalledVersionsGuard::skipReason($this->getGroups());
		if ($reason !== null) {
			self::markTestSkipped($reason);
		}
	}

}
