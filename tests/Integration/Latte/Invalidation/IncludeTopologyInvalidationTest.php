<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function sort;

// Matrix group F - the include TOPOLOGY. A template is analysed once per incoming context, so a
// brand-new {include} of an unedited file changes that file's analysis although none of its own
// bytes and no exported node it depends on moved. Each template tracks the edges of its connected
// component, so the edit re-analyses exactly that component - never the unrelated pair beside it,
// never the PHP renderer and template the seeded corpus already holds.
final class IncludeTopologyInvalidationTest extends LatteInvalidationMatrixCase
{

	public function testANewIncludeOfAnUneditedTemplateReanalysesItsComponentOnly(): void
	{
		$this->assertScenario(
			'topo-new-include',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'inc/a.latte',
						"{include 'b.latte', x => 1}\n{include 'c.latte', y => 2}\n",
					),
					'expect' => $this->topologyErrors(false),
					'reanalysed' => ['inc/a.latte', 'inc/b.latte', 'inc/c.latte'],
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'inc/a.latte',
						"{include 'b.latte', x => 1}\n",
					),
					'expect' => $this->topologyErrors(true),
					'reanalysed' => ['inc/a.latte', 'inc/b.latte', 'inc/c.latte'],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedTopology($scenario),
			$this->topologyErrors(true),
		);
	}

	// The negative half: an argument edit inside one component reaches its target through the
	// exported edge fingerprint and nothing in the other component, and a PHP file nothing reads
	// re-analyses only itself.
	public function testUnrelatedEditsStayInsideTheirOwnFile(): void
	{
		$this->assertScenario(
			'topo-unrelated',
			[
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'inc/d.latte',
						"{include 'e.latte', z => 4}\n<p>edited</p>\n",
					),
					'expect' => $this->topologyErrors(true),
					'reanalysed' => ['inc/d.latte', 'inc/e.latte'],
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->write(
						'Unrelated.php',
						"<?php declare(strict_types = 1);\n\nfinal class Unrelated\n{\n\n\tpublic const VALUE = 2;\n\n}\n",
					),
					'expect' => $this->topologyErrors(true),
					'reanalysed' => ['Unrelated.php'],
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedTopology($scenario),
			$this->topologyErrors(true),
		);
	}

	private function seedTopology(InvalidationScenario $scenario): void
	{
		$scenario->write('inc/a.latte', "{include 'b.latte', x => 1}\n");
		$scenario->write('inc/b.latte', "{\$x}\n");
		$scenario->write('inc/c.latte', "{\$y}\n");
		$scenario->write('inc/d.latte', "{include 'e.latte', z => 3}\n");
		$scenario->write('inc/e.latte', "{\$z}\n");
		$scenario->write(
			'Unrelated.php',
			"<?php declare(strict_types = 1);\n\nfinal class Unrelated\n{\n\n\tpublic const VALUE = 1;\n\n}\n",
		);
	}

	/**
	 * @return list<string>
	 */
	private function topologyErrors(bool $cUnreached): array
	{
		// No renderer links the inc/ templates, so each of them is also an orphan, and nothing reads
		// the constant: both are stable across every step and keep the corpus free of anything else.
		$errors = ['Unrelated.php:6 :: shipmonk.deadConstant :: Unused Unrelated::VALUE'];
		foreach (['a', 'b', 'c', 'd', 'e'] as $template) {
			$errors[] = 'inc/' . $template . '.latte:1 :: orisai.nette.latte.orphanTemplate :: '
				. 'No analysable render, include or layout path reaches this template file.';
		}

		if ($cUnreached) {
			$errors[] = 'inc/c.latte:1 :: variable.undefined :: Undefined variable: $y';
		}

		sort($errors);

		return $errors;
	}

}
