<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Compile;

use Error;
use InvalidArgumentException;
use Latte\CompileException;
use Latte\SecurityViolationException;
use OriPhpstan\Nette\Latte\Compile\VendorCompileFailure;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class VendorCompileFailureTest extends BaseTestCase
{

	public function testLatteExceptionsKeepTheirMessage(): void
	{
		self::assertSame('Missing {/if}', VendorCompileFailure::message(new CompileException('Missing {/if}')));
		self::assertSame('Not allowed.', VendorCompileFailure::message(new SecurityViolationException('Not allowed.')));
		self::assertSame('Bad name.', VendorCompileFailure::message(new InvalidArgumentException('Bad name.')));
	}

	public function testOtherVendorThrowableIsWrappedTheWayEngineDoes(): void
	{
		self::assertSame(
			"Thrown exception 'Class \"Foo\" not found'",
			VendorCompileFailure::message(new Error('Class "Foo" not found')),
		);
	}

	public function testThrowableFromLibraryCodeIsRethrown(): void
	{
		try {
			ShapeFamily::detect('dev-master', null);
			self::fail('ShapeFamily::detect() accepted an unsupported version.');
		} catch (InvalidArgumentException $e) {
			$own = $e;
		}

		$this->expectExceptionObject($own);
		VendorCompileFailure::message($own);
	}

}
