<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

final class CatalogInvalidationTest extends FormsInvalidationMatrixCase
{

	private const CATALOG_DUMP_LINE = 10;

	public function testEditingAUserCatalogTagMovesTheShape(): void
	{
		$this->setParameter('orisaiNette.forms.catalogs', ['ScratchRuleCatalog']);

		$this->assertScenario(
			'catalog-tag',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchRuleCatalog.php',
						$this->catalogSource('float float'),
					),
					'expect' => array_merge($this->seedErrors(), [$this->catalogDump("''|float")]),
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write('ScratchRuleCatalog.php', $this->catalogSource('int integer'));
				$scenario->write('ScratchCatalogControl.php', $this->catalogControlSource());
			},
			array_merge($this->seedErrors(), [$this->catalogDump("''|int")]),
		);
	}

	private function catalogDump(string $type): string
	{
		return $this->valuesDump(self::CATALOG_DUMP_LINE, 'ruled: ' . $type, 'ScratchCatalogControl.php');
	}

	private function catalogSource(string $cast): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

interface ScratchRuleCatalog
{

	/** @form-rule-cast scratchRule $cast */
	public function scratchRule(): void;

}

PHP;
	}

	private function catalogControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchCatalogControl extends \Nette\Application\UI\Control
{

	public function probe(): void
	{
		dumpFormValues($this['ruled']);
	}

	protected function createComponentRuled(): \Nette\Application\UI\Form
	{
		$form = new \Nette\Application\UI\Form();
		$form->addText('ruled')->addRule('scratchRule');

		return $form;
	}

}

PHP;
	}

}
