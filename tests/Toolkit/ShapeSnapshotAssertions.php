<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use OriPhpstan\Nette\Forms\Shape\ComponentShapeRenderer;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\PhpDoc\TypeStringResolver;

/**
 * Full-output shape assertions for a test that holds a FormShape object rather than driving a
 * fixture through assertComponent.
 *
 * A test that asserts anything about a shape asserts its WHOLE rendered output. A handful of
 * assertArrayHasKey probes on hand-picked members passes while the rest of the shape is wrong,
 * and it silently stops covering anything the author did not think to name; two shapes that had
 * been wrong for a long time were found only once something else forced the full render into
 * view. This renders through the same ComponentShapeRenderer that backs assertComponent in the
 * fixture suites, so an in-test snapshot and a fixture snapshot are the same text.
 *
 * The render shows every member, each member's presence marker and whether the shape is open
 * (a trailing ...<IComponent> member) - but not WHICH UnknownReason opened it, and not the NAME
 * axis of UnknownInfo. A test that pins either keeps that one assertion beside the snapshot.
 *
 * Static so that a test driving synthetic AST from inside a static closure can call it too.
 */
trait ShapeSnapshotAssertions
{

	protected static function renderShape(FormShape $shape): string
	{
		$renderer = new ComponentShapeRenderer(
			new ControlAcceptedTypeResolver(self::getContainer()->getByType(TypeStringResolver::class)),
		);

		return $renderer->render($shape);
	}

	protected static function assertShape(FormShape $shape, string $expected, string $message = ''): void
	{
		self::assertSame($expected, self::renderShape($shape), $message);
	}

}
