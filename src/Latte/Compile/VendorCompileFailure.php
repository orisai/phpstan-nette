<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use InvalidArgumentException;
use Latte\CompileException;
use Latte\SecurityViolationException;
use Throwable;
use function dirname;
use function strpos;
use const DIRECTORY_SEPARATOR;

// Engine::compile() turns anything a vendor tag parser, macro or pass throws into the template's
// CompileException ("Thrown exception '...'"); a throwable from this library's own code stays an
// internal error.
final class VendorCompileFailure
{

	/**
	 * @throws Throwable
	 */
	public static function message(Throwable $e): string
	{
		if ($e instanceof CompileException || $e instanceof SecurityViolationException) {
			return $e->getMessage();
		}

		if (strpos($e->getFile(), dirname(__DIR__, 2) . DIRECTORY_SEPARATOR) === 0) {
			throw $e;
		}

		return $e instanceof InvalidArgumentException
			? $e->getMessage()
			: "Thrown exception '" . $e->getMessage() . "'";
	}

}
