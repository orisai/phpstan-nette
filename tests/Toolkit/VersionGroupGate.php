<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

trait VersionGroupGate
{

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
