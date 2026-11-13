<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Forms\Invalidation;

use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;

// Group G - a fact the walk asks the scope to type. A name written as `Names::DIRECT`, a static
// call's declared return or a flag argument is read off declarations in a file no shape source
// names, and a constructed form class may not exist yet. The consumer reads the shape one hop past
// the builder, so only a dependency recorded by the walk re-analyses it - and the shape store
// entry serving it must go stale too, or a warm run keeps the old answer even without a result
// cache.
final class ScopeTypedFactInvalidationTest extends FormsInvalidationMatrixCase
{

	public function testNameConstantTypedThroughTheScope(): void
	{
		$this->assertScenario(
			'g1-scope-constant',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'Names.php',
						$this->namesSource("'renamed'"),
					),
					'expect' => [$this->dump(
						'renamed: string, docName: string, viaOther: string, own: non-empty-string|null, choice: \'keyA\'|null',
					)],
				],
			],
			null,
			[$this->seedDump()],
		);
	}

	public function testStaticCallReturnTypedThroughTheScope(): void
	{
		$this->assertScenario(
			'g1-scope-static-call',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'Names.php',
						$this->namesSource("'direct'", "'otherDoc'"),
					),
					'expect' => [$this->dump(
						'direct: string, otherDoc: string, viaOther: string, own: non-empty-string|null, choice: \'keyA\'|null',
					)],
				],
			],
			null,
			[$this->seedDump()],
		);
	}

	public function testFlagConstantTypedThroughTheScope(): void
	{
		$this->assertScenario(
			'g1-scope-flag',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'Flags.php',
						$this->flagsSource('false'),
					),
					'expect' => [$this->dump(
						'direct: string, docName: string, viaOther: string, own: string, choice: \'keyA\'|null',
					)],
				],
			],
			null,
			[$this->seedDump()],
		);
	}

	// The constant's initialiser reads a second file: recording only the declaring file would miss it.
	public function testConstantInitialiserReadingAnotherFile(): void
	{
		$this->assertScenario(
			'g2-indirect-constant',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'Other.php',
						$this->otherSource("'viaOtherRenamed'"),
					),
					'expect' => [$this->dump(
						'direct: string, docName: string, viaOtherRenamed: string, own: non-empty-string|null, choice: \'keyA\'|null',
					)],
				],
			],
			null,
			[$this->seedDump()],
		);
	}

	public function testChoiceItemsConstantInitialiserReadingAnotherFile(): void
	{
		$this->assertScenario(
			'g2-indirect-items',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'Other.php',
						$this->otherSource("'viaOther'", "'keyB'"),
					),
					'expect' => [$this->dump(
						'direct: string, docName: string, viaOther: string, own: non-empty-string|null, choice: \'keyB\'|null',
					)],
				],
			],
			null,
			[$this->seedDump()],
		);
	}

	public function testConstructedFormClassAppearsAndGoes(): void
	{
		$this->assertScenario(
			'g3-late-class',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'LateForm.php',
						$this->lateFormSource(),
					),
					'expect' => [
						$this->seedDump(),
						$this->lateDump('lateField: string'),
					],
				],
				[
					'mutate' => static fn (InvalidationScenario $scenario) => $scenario->delete('LateForm.php'),
					'expect' => $this->lateSeedErrors(),
				],
			],
			fn (InvalidationScenario $scenario) => $this->seedLate($scenario),
			$this->lateSeedErrors(),
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('Names.php', $this->namesSource());
		$scenario->write('Other.php', $this->otherSource());
		$scenario->write('Flags.php', $this->flagsSource());
		$scenario->write('Items.php', $this->itemsSource());
		$scenario->write('Factory.php', $this->factorySource());
		$scenario->write('Consumer.php', $this->consumerSource('Factory'));
	}

	private function seedLate(InvalidationScenario $scenario): void
	{
		$scenario->write('LateFactory.php', $this->lateFactorySource());
		$scenario->write('LateConsumer.php', $this->consumerSource('LateFactory', 'LateConsumer'));
	}

	private function seedDump(): string
	{
		return $this->dump(
			'direct: string, docName: string, viaOther: string, own: non-empty-string|null, choice: \'keyA\'|null',
		);
	}

	private function dump(string $shape): string
	{
		return $this->valuesDump(18, $shape, 'Consumer.php');
	}

	private function lateDump(string $shape): string
	{
		return $this->valuesDump(18, $shape, 'LateConsumer.php');
	}

	/**
	 * @return list<string>
	 */
	private function lateSeedErrors(): array
	{
		return [
			$this->seedDump(),
			$this->lateDump(''),
			'LateFactory.php:10 :: class.notFound :: Instantiated class LateForm not found.',
			'LateFactory.php:12 :: return.type :: Method LateFactory::create() should return Nette\Application\UI\Form but returns LateForm.',
		];
	}

	private function namesSource(string $direct = "'direct'", string $docName = "'docName'"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class Names
{

	public const DIRECT = $direct;

	public const INDIRECT = Other::X;

	/** @return $docName */
	public static function name(): string
	{
		return $docName;
	}

}

PHP;
	}

	private function otherSource(string $value = "'viaOther'", string $key = "'keyA'"): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class Other
{

	public const X = $value;

	public const A = $key;

}

PHP;
	}

	private function itemsSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class Items
{

	public const ALL = [Other::A => 'label'];

}

PHP;
	}

	private function flagsSource(string $nullable = 'true'): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class Flags
{

	public const NULLABLE = $nullable;

}

PHP;
	}

	private function factorySource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use Nette\Application\UI\Form;

final class Factory
{

	public function create(): Form
	{
		$form = new Form();
		$form->addText(Names::DIRECT);
		$form->addText(Names::name());
		$form->addText(Names::INDIRECT);
		$form->addText('own')->setNullable(Flags::NULLABLE);
		$form->addSelect('choice', null, Items::ALL);

		return $form;
	}

}

PHP;
	}

	private function lateFactorySource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

use Nette\Application\UI\Form;

final class LateFactory
{

	public function create(): Form
	{
		$form = new LateForm();

		return $form;
	}

}

PHP;
	}

	private function lateFormSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

class LateForm extends \Nette\Application\UI\Form
{

	public function __construct()
	{
		parent::__construct();
		$this->addText('lateField');
	}

}

PHP;
	}

	private function consumerSource(string $factory, string $className = 'Consumer'): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

use Nette\Application\UI\Form;
use function OriPhpstan\Nette\Forms\Testing\dumpFormValues;

final class $className extends \Nette\Application\UI\Control
{

	private $factory \$factory;

	public function __construct($factory \$factory)
	{
		\$this->factory = \$factory;
	}

	public function probe(): void
	{
		dumpFormValues(\$this['form']);
	}

	protected function createComponentForm(): Form
	{
		return \$this->factory->create();
	}

}

PHP;
	}

}
