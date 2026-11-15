<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;
use function strpos;

// A filter registered on the template before rendering reaches the template and its includes through
// the renderer's render facts: editing the registration, the closure's signature or the file declaring
// a method the template cannot call directly re-analyses every template the renderer reaches - none of
// them is an edit PHPStan itself propagates to a .latte file.
final class RenderTimeFilterInvalidationTest extends LatteInvalidationMatrixCase
{

	private const HELPERS = 'ScratchFilterHelpers';

	private const TRAIT = 'ScratchFilterTrait';

	public function testRegistrationAddedAndRemoved(): void
	{
		$this->assertScenario(
			'filter-registration',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->registeringControlSource('[\\' . self::HELPERS . "::class, 'fmt']"),
					),
					'expect' => [],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->registeringControlSource(null),
					),
					'expect' => self::withUnusedHelper(self::unknownErrors()),
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedFilterCorpus($scenario, null, 'string'),
			self::withUnusedHelper(self::unknownErrors()),
		);
	}

	public function testClosureSignatureEditedInTheRegisteringFile(): void
	{
		$this->assertScenario(
			'filter-closure',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->registeringControlSource('static fn (string $value): string => $value'),
					),
					'expect' => [],
					'reanalysed' => ['ScratchControl.php', 'partial.latte', 'shared.latte'],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->registeringControlSource('static fn (int $value): string => (string) $value'),
					),
					'expect' => self::argumentErrors('closure'),
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedFilterCorpus(
				$scenario,
				'static fn (int $value): string => (string) $value',
				null,
			),
			self::argumentErrors('closure'),
		);
	}

	public function testStaticMethodSignatureEditedInItsDeclaringFile(): void
	{
		$this->assertScenario(
			'filter-static-method',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						self::HELPERS . '.php',
						self::helpersSource('string'),
					),
					'expect' => [],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedFilterCorpus(
				$scenario,
				'[\\' . self::HELPERS . "::class, 'fmt']",
				'int',
			),
			self::argumentErrors('static method ' . self::HELPERS . '::fmt()'),
		);
	}

	public function testPrivateMethodSignatureEditedInTheTraitDeclaringIt(): void
	{
		$this->assertScenario(
			'filter-trait-method',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						self::TRAIT . '.php',
						self::traitSource('string'),
					),
					'expect' => [],
					'reanalysed' => ['ScratchControl.php', self::TRAIT . '.php', 'partial.latte', 'shared.latte'],
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						self::TRAIT . '.php',
						self::traitSource('int'),
					),
					'expect' => self::argumentErrors('closure'),
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(self::TRAIT . '.php', self::traitSource('int'));
				$this->seedFilterCorpus($scenario, "[\$this, 'fmt']", null);
			},
			self::argumentErrors('closure'),
		);
	}

	private function seedFilterCorpus(InvalidationScenario $scenario, ?string $callable, ?string $helperType): void
	{
		if ($helperType !== null) {
			$scenario->write(self::HELPERS . '.php', self::helpersSource($helperType));
		}

		$scenario->write(self::CONTROL . '.php', $this->registeringControlSource($callable));
		$scenario->write(
			'shared.latte',
			$this->templateFileSource(self::BASE_TEMPLATE, "{='x'|fmt}\n{include 'partial.latte'}\n"),
		);
		$scenario->write('partial.latte', "{='y'|fmt}\n");
	}

	private function registeringControlSource(?string $callable): string
	{
		$registration = $callable === null ? '' : "\t\t\$template->addFilter('fmt', $callable);\n";
		$baseTemplate = self::BASE_TEMPLATE;
		$control = self::CONTROL;
		$use = $callable !== null && strpos($callable, '$this') !== false ? "\tuse " . self::TRAIT . ";\n\n" : '';

		return <<<PHP
<?php declare(strict_types = 1);

class $control extends \Nette\Application\UI\Control
{

$use	public function formatTemplateClass(): ?string
	{
		return $baseTemplate::class;
	}

	public function render(): void
	{
		/** @var $baseTemplate \$template */
		\$template = \$this->getTemplate();
$registration		\$template->setFile(__DIR__ . '/shared.latte');
	}

}

PHP;
	}

	private static function helpersSource(string $type): string
	{
		$helpers = self::HELPERS;

		return <<<PHP
<?php declare(strict_types = 1);

final class $helpers
{

	public static function fmt($type \$value): string
	{
		return '';
	}

}

PHP;
	}

	private static function traitSource(string $type): string
	{
		$trait = self::TRAIT;

		return <<<PHP
<?php declare(strict_types = 1);

trait $trait
{

	private function fmt($type \$value): string
	{
		return '';
	}

}

PHP;
	}

	/**
	 * @return list<string>
	 */
	private static function unknownErrors(): array
	{
		return [
			"partial.latte:1 :: orisai.nette.latte.unknownFilter :: Unknown Latte filter 'fmt'.",
			"shared.latte:2 :: orisai.nette.latte.unknownFilter :: Unknown Latte filter 'fmt'.",
		];
	}

	/**
	 * @param list<string> $errors
	 * @return list<string>
	 */
	private static function withUnusedHelper(array $errors): array
	{
		return array_merge(
			[self::HELPERS . '.php:6 :: shipmonk.deadMethod :: Unused ' . self::HELPERS . '::fmt'],
			$errors,
		);
	}

	/**
	 * @return list<string>
	 */
	private static function argumentErrors(string $callee): array
	{
		return [
			"partial.latte:1 :: argument.type :: Parameter #1 \$value of $callee expects int, string given.",
			"shared.latte:2 :: argument.type :: Parameter #1 \$value of $callee expects int, string given.",
		];
	}

}
