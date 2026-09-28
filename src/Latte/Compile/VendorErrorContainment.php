<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use function restore_error_handler;
use function set_error_handler;

// Every vendor Latte invocation can trigger_error() with no ambient handler active - PHPStan's
// own FileAnalyser handler is bypassed on cold/invalidated result-cache runs (ResultCacheManager's
// ExportedNodeFetcher pre-pass parses outside FileAnalyser's collectErrors() wrap), so every call
// site must own a scoped handler rather than rely on one already being installed. $onError fires
// synchronously inside the handler so a caller can read position state (e.g. Compiler::getLine())
// at the moment the error happens, not from a list processed afterward.
final class VendorErrorContainment
{

	/**
	 * @template T
	 * @param callable(): T $body
	 * @param callable(int, string): void $onError
	 * @return T
	 */
	public static function run(callable $body, callable $onError)
	{
		set_error_handler(static function (int $severity, string $message) use ($onError): bool {
			$onError($severity, $message);

			return true;
		});

		try {
			return $body();
		} finally {
			restore_error_handler();
		}
	}

}
