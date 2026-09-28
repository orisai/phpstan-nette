<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Compile;

use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function restore_error_handler;
use function set_error_handler;
use function trigger_error;
use const E_USER_NOTICE;

final class VendorErrorContainmentTest extends BaseTestCase
{

	public function testReturnsBodysReturnValue(): void
	{
		$result = VendorErrorContainment::run(
			static fn (): int => 42,
			static function (int $severity, string $message): void {
			},
		);

		self::assertSame(42, $result);
	}

	public function testInvokesOnErrorSynchronouslyForEachTriggeredError(): void
	{
		$captured = [];

		VendorErrorContainment::run(
			static function (): int {
				trigger_error('first', E_USER_NOTICE);
				trigger_error('second', E_USER_NOTICE);

				return 1;
			},
			static function (int $severity, string $message) use (&$captured): void {
				$captured[] = [$severity, $message];
			},
		);

		self::assertSame([[E_USER_NOTICE, 'first'], [E_USER_NOTICE, 'second']], $captured);
	}

	public function testOnErrorNeverInvokedWhenNoErrorIsTriggered(): void
	{
		$captured = [];

		VendorErrorContainment::run(
			static fn (): int => 1,
			static function (int $severity, string $message) use (&$captured): void {
				$captured[] = [$severity, $message];
			},
		);

		self::assertSame([], $captured);
	}

	public function testRestoresPreviousHandlerAfterSuccess(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		try {
			VendorErrorContainment::run(
				static fn (): int => 1,
				static function (int $severity, string $message): void {
				},
			);

			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	public function testRestoresPreviousHandlerWhenBodyThrows(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		$caught = null;

		try {
			try {
				VendorErrorContainment::run(
					static function (): void {
						throw new RuntimeException('boom');
					},
					static function (int $severity, string $message): void {
					},
				);
			} catch (RuntimeException $e) {
				$caught = $e;
			}

			self::assertSame('boom', $caught->getMessage());
			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	public function testNestedRunsEachCaptureOnlyTheirOwnErrorsAndRestoreCorrectly(): void
	{
		$sentinel = static fn (): bool => true;
		set_error_handler($sentinel);

		try {
			$outerCaptured = [];
			$innerCaptured = [];

			VendorErrorContainment::run(
				static function () use (&$innerCaptured): int {
					VendorErrorContainment::run(
						static function (): int {
							trigger_error('inner', E_USER_NOTICE);

							return 1;
						},
						static function (int $severity, string $message) use (&$innerCaptured): void {
							$innerCaptured[] = $message;
						},
					);

					// Proves the outer run()'s own handler - not the pre-both-runs sentinel - is
					// back in place immediately after the inner run() restores its own.
					trigger_error('outer', E_USER_NOTICE);

					return 2;
				},
				static function (int $severity, string $message) use (&$outerCaptured): void {
					$outerCaptured[] = $message;
				},
			);

			self::assertSame(['inner'], $innerCaptured);
			self::assertSame(['outer'], $outerCaptured);
			self::assertSame($sentinel, self::currentErrorHandler());
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * @return (callable(mixed...): mixed)|null
	 */
	private static function currentErrorHandler(): ?callable
	{
		$probe = static fn (): bool => true;
		$current = set_error_handler($probe);
		restore_error_handler();

		return $current;
	}

}
