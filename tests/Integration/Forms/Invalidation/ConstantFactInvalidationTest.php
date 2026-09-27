<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use function array_merge;

/**
 * Group F - the fact reached through a class CONSTANT. A constant's own exported node does move
 * when its value is edited, so PHPStan re-queues the file that fetches it; what it does not do is
 * carry that on to the fetching file's dependents, because the fetching file's exported nodes are
 * byte-identical. The consumer of the resulting shape lives one hop past that stop, so this is the
 * same silent stale-cache defect group C pins, entered through a declaration-level edit instead of
 * a body-level one.
 *
 * Both rows matter for a different reason: LiteralNameResolver reads a component NAME out of a
 * constant, ChoiceItemKeyResolver reads a choice control's item KEYS out of one, and only the
 * second form occurs in this repo's own app/ today (addSelect(..., Phone::PREFIXES) and 18 more).
 */
final class ConstantFactInvalidationTest extends FormsInvalidationMatrixCase
{

	private const CONST_DUMP_LINE = 10;

	private const CONST_SET_REQUIRED_LINE = 11;

	/**
	 * The reproduction: ScratchFieldNames.php carries no marker construct of its own, and the file
	 * that fetches its constant is not the file that reads the resulting shape.
	 */
	public function testFieldNameConstantValueChanged(): void
	{
		$this->assertScenario(
			'f1-name-constant',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchFieldNames.php',
						$this->fieldNamesSource("'renamedConstField'"),
					),
					'expect' => array_merge(
						$this->seedErrors(),
						[
							$this->constDump('renamedConstField: string, choice: \'a\'|\'b\'|null'),
							$this->constSetRequired(),
						],
					),
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedConstantCorpus($scenario),
			$this->constantSeedErrors(),
		);
	}

	/**
	 * The production-shaped half: the constant is an item-key ARRAY, which moves the field's value
	 * TYPE rather than its name.
	 */
	public function testChoiceItemsConstantValueChanged(): void
	{
		$this->assertScenario(
			'f2-items-constant',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchFieldNames.php',
						$this->fieldNamesSource("'constField'", "['c' => 'C']"),
					),
					'expect' => array_merge(
						$this->seedErrors(),
						[$this->constDump('constField: string, choice: \'c\'|null')],
					),
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedConstantCorpus($scenario),
			$this->constantSeedErrors(),
		);
	}

	private function seedConstantCorpus(InvalidationScenario $scenario): void
	{
		$scenario->write('ScratchFieldNames.php', $this->fieldNamesSource());
		$scenario->write('ScratchConstForm.php', $this->constFormSource());
		$scenario->write('ScratchConstParentControl.php', $this->constParentControlSource());
		$scenario->write('ScratchConstControl.php', $this->constControlSource());
	}

	/**
	 * @return list<string>
	 */
	private function constantSeedErrors(): array
	{
		return array_merge(
			$this->seedErrors(),
			[$this->constDump('constField: string, choice: \'a\'|\'b\'|null')],
		);
	}

	private function constDump(string $shape): string
	{
		return $this->valuesDump(self::CONST_DUMP_LINE, $shape, 'ScratchConstControl.php');
	}

	private function constSetRequired(): string
	{
		return 'ScratchConstControl.php:' . self::CONST_SET_REQUIRED_LINE
			. ' :: method.notFound :: Call to an undefined method '
			. 'Nette\ComponentModel\IComponent::setRequired().';
	}

	// No marker construct anywhere in the file - a class constant IS the whole shape input.
	private function fieldNamesSource(string $name = "'constField'", string $items = "['a' => 'A', 'b' => 'B']"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class ScratchFieldNames
{

	public const CONST_FIELD = $name;

	public const CHOICE_ITEMS = $items;

}

PHP;
	}

	// The NAME hop: a form subclass constructor whose field name is a constant in a third file.
	private function constFormSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class ScratchConstForm extends \Nette\Application\UI\Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addText(ScratchFieldNames::CONST_FIELD);
	}

}

PHP;
	}

	/**
	 * The ITEMS hop, in an inherited factory rather than the consumer's own: a factory declared in
	 * the file that reads the shape would be re-queued by PHPStan's own dependency edge on the
	 * constant, and the row would pass without the salt.
	 */
	private function constParentControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

abstract class ScratchConstParentControl extends \Nette\Application\UI\Control
{

	protected function createComponentConst(): ScratchConstForm
	{
		$form = new ScratchConstForm();
		$form->addSelect('choice', null, ScratchFieldNames::CHOICE_ITEMS);

		return $form;
	}

}

PHP;
	}

	private function constControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class ScratchConstControl extends ScratchConstParentControl
{

	public function probe(): void
	{
		dumpFormValues($this['const']);
		$this['const']['constField']->setRequired();
	}

}

PHP;
	}

}
