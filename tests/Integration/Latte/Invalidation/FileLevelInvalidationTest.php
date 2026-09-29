<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Matrix group A - FILE-level events: a class file or a template file appears, disappears, or the
// class-to-file mapping is rearranged under the analysis. These reach the discovery store's own
// channels (a new/removed file is an exported-node event; a record change moves the store file's
// RECORDS_HASH class constant), so they are expected to work both before and after the aggregate
// move - and their job in the matrix is to prove the move did not cost them.
//
// Supersedes DiscoveryPerFileConsumerInvalidationTest's single ADDED-RENDERER case, which is
// scenario 1 here; that test kept its own two store LAYOUTS (store inside vs outside the analysed
// paths), which this matrix does not vary, so it stays where it is rather than being folded away.
/**
 * @group latte2
 */
final class FileLevelInvalidationTest extends LatteInvalidationMatrixCase
{

	// Scenario 1 - a class file ADDED. Nothing PHPStan sees about shared.latte changed: same bytes,
	// same {templateType}, and the new renderer was never a dependency of it.
	public function testClassFileAdded(): void
	{
		$this->assertScenario(
			'file-class-added',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::SECOND_CONTROL . '.php',
						$this->controlSource(self::SECOND_CONTROL, self::OTHER_TEMPLATE),
					),
					'expect' => [$this->secondControlMismatch()],
				],
			],
		);
	}

	// Scenario 2 - a class file REMOVED: the finding has to disappear from a template whose own
	// bytes never moved.
	public function testClassFileRemoved(): void
	{
		$this->assertScenario(
			'file-class-removed',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->delete(
						self::SECOND_CONTROL . '.php',
					),
					'expect' => [],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedSecondControl($scenario),
			[$this->secondControlMismatch()],
		);
	}

	// Scenario 3 - a file RENAMED (an add and a remove in the same run), the class it declares
	// untouched. The finding must survive the move rather than flicker off and on.
	public function testClassFileRenamed(): void
	{
		$this->assertScenario(
			'file-renamed',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->rename(
						self::SECOND_CONTROL . '.php',
						'RenamedSecondControl.php',
					),
					'expect' => [$this->secondControlMismatch()],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedSecondControl($scenario),
			[$this->secondControlMismatch()],
		);
	}

	// Scenario 4 - a class MOVED between two files that both already exist, so neither file is added
	// or removed and the class-to-file mapping is the only thing that changed.
	public function testClassMovedBetweenExistingFiles(): void
	{
		$this->assertScenario(
			'file-class-moved',
			[
				[
					'mutate' => function (InvalidationScenario $scenario): void {
						$scenario->delete(self::SECOND_CONTROL . '.php');
						$scenario->write(
							self::OTHER_TEMPLATE . '.php',
							$this->templateSource(self::OTHER_TEMPLATE)
							. $this->movedControlSource(self::OTHER_TEMPLATE),
						);
					},
					'expect' => [$this->secondControlMismatch()],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedSecondControl($scenario),
			[$this->secondControlMismatch()],
		);
	}

	// Scenario 5 - a template FILE added. Its renderer already existed and is not touched: the
	// setFile target simply starts existing, which is what turns it into a store record and gives
	// the renderer's verdict a template to be compared against for the first time.
	public function testTemplateFileAdded(): void
	{
		$this->assertScenario(
			'file-template-added',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'second.latte',
						$this->templateFileSource(self::OTHER_TEMPLATE, "<p>second</p>\n"),
					),
					'expect' => [$this->secondTemplateMismatch()],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedSecondTemplateRenderer($scenario),
		);
	}

	// Scenario 6 - a template FILE removed.
	public function testTemplateFileRemoved(): void
	{
		$this->assertScenario(
			'file-template-removed',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->delete('second.latte'),
					'expect' => [],
				],
			],
			function (InvalidationScenario $scenario): void {
				$this->seedSecondTemplateRenderer($scenario);
				$scenario->write('second.latte', $this->templateFileSource(self::OTHER_TEMPLATE, "<p>second</p>\n"));
			},
			[$this->secondTemplateMismatch()],
		);
	}

	private function seedSecondControl(InvalidationScenario $scenario): void
	{
		$scenario->write(
			self::SECOND_CONTROL . '.php',
			$this->controlSource(self::SECOND_CONTROL, self::OTHER_TEMPLATE),
		);
	}

	private function seedSecondTemplateRenderer(InvalidationScenario $scenario): void
	{
		$scenario->write(
			self::SECOND_CONTROL . '.php',
			$this->controlSource(self::SECOND_CONTROL, self::BASE_TEMPLATE, 'second.latte'),
		);
	}

	private function secondControlMismatch(): string
	{
		return $this->mismatchError(self::OTHER_TEMPLATE, 'shared.latte', self::SECOND_CONTROL);
	}

	private function secondTemplateMismatch(): string
	{
		return $this->mismatchError(
			self::BASE_TEMPLATE,
			'second.latte',
			self::SECOND_CONTROL,
			self::OTHER_TEMPLATE,
		);
	}

	private function movedControlSource(string $pairedTemplate): string
	{
		return <<<PHP

class ScratchSecondControl extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		return $pairedTemplate::class;
	}

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP;
	}

}
