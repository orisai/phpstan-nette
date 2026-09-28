<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

use RuntimeException;

// Every render hook here has the SAME body shape - one call to a helper that always throws - so the
// only thing that can decide collection is how that helper spells its return type. renderCallsPlain
// is the discriminator: its helper throws just as unconditionally, but declares nothing.
class NeverTerminatingRenderProbeControl
{

	public function renderCallsNever(): void
	{
		$this->abortNever();
	}

	public function renderCallsPhpstanTagNever(): void
	{
		$this->abortPhpstanTagNever();
	}

	public function renderCallsNoReturn(): void
	{
		$this->abortNoReturn();
	}

	public function renderCallsNeverReturn(): void
	{
		$this->abortNeverReturn();
	}

	public function renderCallsNeverReturns(): void
	{
		$this->abortNeverReturns();
	}

	public function renderCallsPlain(): void
	{
		$this->abortPlain();
	}

	/**
	 * @return never
	 */
	public function abortNever()
	{
		throw new RuntimeException('never');
	}

	/**
	 * @phpstan-return never
	 */
	public function abortPhpstanTagNever()
	{
		throw new RuntimeException('phpstan-return never');
	}

	/**
	 * @return no-return
	 */
	public function abortNoReturn()
	{
		throw new RuntimeException('no-return');
	}

	/**
	 * @return never-return
	 */
	public function abortNeverReturn()
	{
		throw new RuntimeException('never-return');
	}

	/**
	 * @return never-returns
	 */
	public function abortNeverReturns()
	{
		throw new RuntimeException('never-returns');
	}

	public function abortPlain(): void
	{
		throw new RuntimeException('undeclared');
	}

}
