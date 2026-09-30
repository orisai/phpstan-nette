<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use OriPhpstan\Nette\Latte\Includes\TemplateTypeChecker;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Matrix group D - the INVERSE CONTROLS. Every other group asks "did the finding move"; this one
// asks "did anything else move that should not have". Without it the matrix is worthless: making
// the whole result cache invalidate on any edit would turn every other scenario green while
// destroying the warm-cache granularity this project spent its budget on.
//
// The lever is `maxReanalysed` - the FIRST post-edit warm run's own "N files will be reanalysed"
// count, read off PHPStan's verbosity output. A coarse fix shows up here two ways and only here: a
// whole-cache salt makes the run report "Result cache not used because the metadata do not match"
// (no count at all, caught by the isCacheRestored() assertion), and a coarse dependency edge makes
// the count jump to the corpus size.
/**
 * @group latte2
 */
final class InvalidationControlsTest extends LatteInvalidationMatrixCase
{

	// Scenario 19 - a COMMENT-only edit. The edited file itself always reanalyses (PHPStan gates
	// that on the content hash), so 1 is the floor, not the fix's doing; anything above it would be
	// this bridge propagating an edit with no semantic content.
	public function testCommentOnlyEditReanalysesNothingButTheEditedFile(): void
	{
		$this->assertScenario(
			'ctl-comment',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->commentedControlSource(),
					),
					'expect' => [],
					'maxReanalysed' => 1,
				],
			],
		);
	}

	// Scenario 20 - a WHITESPACE-only edit.
	public function testWhitespaceOnlyEditReanalysesNothingButTheEditedFile(): void
	{
		$this->assertScenario(
			'ctl-whitespace',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL . '.php',
						$this->controlSource(self::CONTROL, self::BASE_TEMPLATE) . "\n",
					),
					'expect' => [],
					'maxReanalysed' => 1,
				],
			],
		);
	}

	// Scenario 21 - the sharpest control in the matrix. An UNRELATED renderer's body edit flips ITS
	// OWN template's verdict, so the analysis must report a brand-new finding on unrelated.latte -
	// while reanalysing ONE file and leaving shared.latte both unanalysed and silent. Granularity
	// and cross-file correctness asserted in the same run: the aggregate stage is the only placement
	// that can satisfy both at once.
	public function testUnrelatedRendererBodyEditReachesOnlyItsOwnTemplate(): void
	{
		$this->assertScenario(
			'ctl-unrelated',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchUnrelatedControl.php',
						$this->controlSource('ScratchUnrelatedControl', self::BASE_TEMPLATE, 'unrelated.latte'),
					),
					'expect' => [
						$this->mismatchError(
							self::BASE_TEMPLATE,
							'unrelated.latte',
							'ScratchUnrelatedControl',
							self::OTHER_TEMPLATE,
						),
					],
					'maxReanalysed' => 1,
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(
					'ScratchUnrelatedControl.php',
					$this->controlSource('ScratchUnrelatedControl', self::OTHER_TEMPLATE, 'unrelated.latte'),
				);
				$scenario->write('unrelated.latte', $this->templateFileSource(self::OTHER_TEMPLATE, "<p>u</p>\n"));
			},
		);
	}

	// Scenario 24 (added by this task) - a TEMPLATE's own body edit, with its {templateType} and
	// every include site untouched. The template is the file the aggregate stage now reports FOR, so
	// an implementation that re-derives per-template state coarsely would show up here.
	public function testTemplateBodyOnlyEditReanalysesNothingButTheTemplate(): void
	{
		$this->assertScenario(
			'ctl-template-body',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'shared.latte',
						$this->templateFileSource(self::BASE_TEMPLATE, "<p>shared</p>\n<p>and more</p>\n"),
					),
					'expect' => [],
					'maxReanalysed' => 1,
				],
			],
		);
	}

	// Scenario 22 - a Latte NEON PARAMETER change. PHPStan hashes the project config into the result
	// cache metadata, so this one legitimately discards the whole cache - the assertion is that the
	// new value actually reaches the consumer, which is also the only coverage
	// orisai.nette.latte.templateTypeRequired has anywhere: the flag is off in this repo's own phpstan.neon, so
	// the identifier is inert there and the probe could not tell holed from immune for it.
	public function testLatteParameterChangeReachesTheConsumer(): void
	{
		$this->assertScenario(
			'ctl-parameter',
			[
				[
					'mutate' => function (): void {
						$this->setParameter('orisai.nette.latte.templateTypeRequired', true);
					},
					'expect' => [
						'floor.latte:1 :: ' . TemplateTypeChecker::REQUIRED_IDENTIFIER
						. ' :: Template has no {templateType} and renderer ScratchFloorControl pairs the default '
						. 'template class.',
					],
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write(
					'ScratchFloorControl.php',
					$this->controlSource('ScratchFloorControl', null, 'floor.latte'),
				);
				$scenario->write('floor.latte', $this->templateFileSource(null, "<p>floor</p>\n"));
			},
		);
	}

	private function commentedControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

// A comment carrying no semantic content whatsoever.
class ScratchControl extends \Nette\Application\UI\Control
{

	public function formatTemplateClass(): ?string
	{
		return ScratchBaseTemplate::class;
	}

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/shared.latte');
	}

}

PHP;
	}

}
