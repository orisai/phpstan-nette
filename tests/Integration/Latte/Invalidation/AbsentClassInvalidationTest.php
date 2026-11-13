<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Nette\Application\UI\Control;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function strpos;

// Matrix group H - a class the render walk found ABSENT appears. The persisted render facts were
// computed while the paired template class did not exist, and no file of their read set changes
// when it is created, so the facts must record the absence itself. Deleting only the result cache
// would not show it: the cold run reads the same persisted facts, hence the stated settled states.
final class AbsentClassInvalidationTest extends LatteInvalidationMatrixCase
{

	private const LATE_CONTROL = 'ScratchLateControl';

	private const LATE_TEMPLATE = 'ScratchLateTemplate';

	public function testPairedTemplateClassAppears(): void
	{
		$this->assertScenario(
			'absent-template-class',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::LATE_TEMPLATE . '.php',
						$this->templateSource(
							self::LATE_TEMPLATE,
							'\Nette\Bridges\ApplicationLatte\Template',
							"\tpublic string \$baseUrl = '';\n",
						),
					),
					'expect' => [
						self::LATE_TEMPLATE . '.php:5 :: shipmonk.deadProperty.neverRead :: Property '
						. self::LATE_TEMPLATE . '::$baseUrl is never read',
					],
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->delete(
						self::LATE_TEMPLATE . '.php',
					),
					'expect' => $this->absentErrors(),
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(
					self::LATE_CONTROL . '.php',
					$this->controlSource(self::LATE_CONTROL, self::LATE_TEMPLATE, 'late.latte'),
				);
				$scenario->write('late.latte', "{\$baseUrl}\n");
				DiscoveryStore::bootstrap(
					$scenario->getSourceDir() . '/discovery',
					[$scenario->projectRelative('late.latte')],
				);
			},
			$this->absentErrors(),
		);
	}

	/**
	 * @return list<string>
	 */
	private function absentErrors(): array
	{
		$errors = [
			self::LATE_CONTROL . '.php:8 :: class.notFound :: Class ' . self::LATE_TEMPLATE . ' not found.',
			self::LATE_CONTROL . '.php:8 :: orisai.nette.latte.pairingOpaque :: Template class pairing is opaque in channel convention.',
		];

		// nette/application 3.2 narrowed the declared return to a class-string.
		if (strpos(
			(string) (new ReflectionMethod(Control::class, 'formatTemplateClass'))->getDocComment(),
			'class-string',
		) !== false) {
			$errors[] = self::LATE_CONTROL . '.php:8 :: return.type :: Method ' . self::LATE_CONTROL
				. '::formatTemplateClass() should return class-string<Nette\Application\UI\Template>|null but returns string.';
		}

		$errors[] = 'late.latte:1 :: variable.undefined :: Undefined variable: $baseUrl';

		return $errors;
	}

}
