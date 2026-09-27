<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use Nette\Utils\Finder;
use OriPhpstan\Nette\Forms\Cache\FormFactSalt;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use function array_diff;
use function array_keys;
use function array_map;
use function array_values;
use function basename;
use function dirname;
use function in_array;
use function is_dir;
use function preg_match_all;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Pins the subset predicate FormFactSalt's whole value rests on.
 *
 * The salt buys `cold == warm` by discarding the result cache when a file that can move a form
 * shape changes, and buys back the granularity by NOT discarding it for the rest of the universe.
 * Every file wrongly left out of the subset is a silent stale-cache hole of exactly the kind the
 * salt exists to close - and nothing else in the tree fails when the predicate shrinks, because a
 * narrower subset makes the analysis faster and quieter, not louder. Hence two guards here:
 * a classification pinned file by file, and a drift check against the extension's own vocabulary.
 */
final class FormFactSaltTest extends BaseTestCase
{

	/**
	 * Method-name literals the extension spells that are deliberately NOT markers, each with the
	 * reason it cannot move a shape. A name that leaves this list and is not made a marker fails
	 * the drift guard; a name that stays here after the extension stops spelling it fails it too,
	 * so the justifications cannot rot unread.
	 */
	private const NOT_SHAPE_AFFECTING = [
		// FormWriteAccessRule diagnoses writes THROUGH a resolved shape; the shape is its input.
		'setValue' => true,
		'setValues' => true,
		'setDefaultValue' => true,
		'setDefaults' => true,
		// The read side: offsetGet/getComponent are how a consumer reaches a component.
		'offsetGet' => true,
		// The existence-check side, spelled by FormExistenceCheckConstantRule. The salt's markers are
		// the constructs the WALK reads to BUILD a shape, and offsetExists() is not one of them: the
		// walk has never recognised it, so a file spelling it contributes nothing a cached shape was
		// derived from and adding it would enlarge the subset without moving an answer. (It does
		// attach at runtime when a factory exists, through the same lazy block offsetGet() reaches -
		// which is a gap in what the walk MODELS, the same gap offsetGet has, and not a question
		// about which files invalidate.)
		'offsetExists' => true,
		// NodeContributionSummary::OP_REMOVE, an op-kind tag rather than a method name.
		'remove' => true,
	];

	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/form-fact-salt-test-' . uniqid('', true);
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		if (is_dir($this->dir)) {
			FileSystem::delete($this->dir);
		}
	}

	/**
	 * The corpus spans every grammar the predicate is supposed to admit and every near-miss it is
	 * supposed to reject, and the assertion is the WHOLE subset rather than a membership test per
	 * file, so an edit that widens the predicate has to state what it widened it to.
	 */
	public function testTheSubsetIsExactlyTheFilesThatCanMoveAShape(): void
	{
		$this->seedCorpus();

		self::assertSame(
			[
				'AddsAnnotation.php',
				'AliasConstant.php',
				'ChoiceItemsConstant.php',
				'ConstantConsumerForm.php',
				'FieldNameConstant.php',
				'FormAnnotation.php',
				'InnerConstant.php',
				'Mutator.php',
				'OffsetSetGrammar.php',
				'StaticAddCall.php',
				'SubclassConstructor.php',
				'UnsetGrammar.php',
				'WizardStep.php',
			],
			$this->subset(),
		);
	}

	/**
	 * The Critical this fix closes, at unit speed: FieldNameConstant.php carries no marker of its
	 * own, so the shipped-before predicate left it out and an edit to the literal that NAMES a
	 * field moved no digest at all. UnrelatedConstant.php is the control that the answer is not
	 * simply "any file with a const".
	 */
	public function testEditingAConstantThatNamesAFieldMovesTheSaltAndAnUnreadOneDoesNot(): void
	{
		$this->seedCorpus();
		$seeded = $this->salt()->get();

		FileSystem::write(
			$this->dir . '/UnrelatedConstant.php',
			$this->constantSource('UnrelatedConstant', 'UNRELATED_NAME', "'renamed'"),
		);
		self::assertSame($seeded, $this->salt()->get(), 'a constant no shape input reads must leave the salt alone');

		FileSystem::write(
			$this->dir . '/FieldNameConstant.php',
			$this->constantSource('FieldNameConstant', 'FIELD_NAME', "'renamedField'"),
		);
		self::assertNotSame($seeded, $this->salt()->get(), 'the literal that names a field is a shape input');
	}

	/**
	 * `@form-adds` names a control class in a docblock and nowhere else, so an edit to that operand
	 * moves no exported node and would leave a warm run answering with the previous class. The salt
	 * is the only channel that catches it, and it catches it because doc comments are digested.
	 */
	public function testEditingTheAddsTagMovesTheSalt(): void
	{
		$this->seedCorpus();
		$seeded = $this->salt()->get();

		FileSystem::write(
			$this->dir . '/AddsAnnotation.php',
			$this->addsAnnotationSource('Nette\Forms\Controls\TextArea'),
		);

		self::assertNotSame($seeded, $this->salt()->get(), 'the class a form-adds tag names is a shape input');
	}

	/**
	 * The other half of the trade: the salt must not discard the cache for edits that cannot move a
	 * shape, or the subset would be worth nothing.
	 */
	public function testCommentAndLayoutEditsInsideTheSubsetLeaveTheSaltAlone(): void
	{
		$this->seedCorpus();
		$seeded = $this->salt()->get();

		FileSystem::write(
			$this->dir . '/SubclassConstructor.php',
			$this->subclassConstructorSource("\t\t// a line comment that changes nothing\n\n\n"),
		);

		self::assertSame($seeded, $this->salt()->get());
	}

	/**
	 * The drift guard. MARKER_NAMES is a vocabulary hand-copied out of ConstructorFormShapeResolver,
	 * NodeContributionSummaryFactory, ComponentAffectingNodeVisitor and friends, and nothing else
	 * couples the two: the next contributor who teaches the extension a new shape-affecting method
	 * would not be told to teach the salt about it, and the hole would reopen in silence. So every
	 * mutator-shaped method name the extension SPELLS must either be a marker or be listed above
	 * with its reason.
	 */
	public function testEveryMutatorNameTheExtensionSpellsIsAMarkerOrIsJustifiedAsHarmless(): void
	{
		$spelled = $this->mutatorNamesSpelledByTheExtension();
		self::assertNotSame([], $spelled, 'the source scan must find the vocabulary it is guarding');

		foreach ($spelled as $name) {
			FileSystem::write(
				$this->dir . '/' . $name . '.php',
				"<?php declare(strict_types = 1);\n\n\$x->" . $name . "();\n",
			);
		}

		$covered = $this->subset();
		$uncovered = [];
		foreach ($spelled as $name) {
			if (!isset(self::NOT_SHAPE_AFFECTING[$name]) && !in_array($name . '.php', $covered, true)) {
				$uncovered[] = $name;
			}
		}

		self::assertSame(
			[],
			$uncovered,
			'the extension reads these method names but FormFactSalt does not treat them as '
			. 'shape-affecting: add them to MARKER_NAMES/MARKER_PREFIXES, or to this test\'s '
			. 'NOT_SHAPE_AFFECTING list with the reason they cannot move a shape',
		);

		self::assertSame(
			[],
			array_values(array_diff(array_keys(self::NOT_SHAPE_AFFECTING), $spelled)),
			'these names are justified as harmless but the extension no longer spells them',
		);
	}

	/**
	 * @return list<string> the names of the corpus files in the subset, sorted
	 */
	private function subset(): array
	{
		$names = array_map(
			static fn (string $file): string => basename($file),
			$this->salt()->shapeAffectingFiles(),
		);
		sort($names);

		return $names;
	}

	private function salt(): FormFactSalt
	{
		$finder = TestFileFinder::create($this->dir);

		return new FormFactSalt([$this->dir], $finder);
	}

	/**
	 * The scan spans every tree the salt is answering for, which is no longer the forms tree alone:
	 * the component-model attachment machinery reads vendor mutator names of its own, and leaving it
	 * out would reopen the hole for exactly the names the guard exists to catch. It is the same set
	 * FormsCodeVersion::roots() digests, for the same reason.
	 *
	 * @return list<string> every string literal in the extension's own source that reads as a
	 * component-tree mutator - set, remove, offset, monitor, create or unset prefixed - sorted and
	 * deduplicated
	 */
	private function mutatorNamesSpelledByTheExtension(): array
	{
		$names = [];
		$extension = dirname(__DIR__, 3) . '/src';
		foreach ([$extension . '/Forms', $extension . '/Component/Attachment'] as $root) {
			foreach (Finder::findFiles('*.php')->from($root) as $file) {
				preg_match_all(
					"~'((?:set|remove|offset|monitor|create|unset)[A-Za-z0-9_]*)'~",
					FileSystem::read((string) $file),
					$matches,
				);
				foreach ($matches[1] as $name) {
					$names[$name] = true;
				}
			}
		}

		$names = array_keys($names);
		sort($names);

		return $names;
	}

	private function seedCorpus(): void
	{
		// IN - the marker grammars.
		FileSystem::write($this->dir . '/SubclassConstructor.php', $this->subclassConstructorSource());
		FileSystem::write(
			$this->dir . '/StaticAddCall.php',
			$this->source('StaticAddCall', "\t\tOther::addComponent(\$form, 'x');\n"),
		);
		FileSystem::write(
			$this->dir . '/WizardStep.php',
			"<?php declare(strict_types = 1);\n\nfinal class WizardStep\n{\n\n\tpublic function createStep1(): void\n\t{\n\t}\n\n}\n",
		);
		FileSystem::write(
			$this->dir . '/OffsetSetGrammar.php',
			$this->source('OffsetSetGrammar', "\t\t\$form['x'] = \$this->control;\n"),
		);
		FileSystem::write($this->dir . '/UnsetGrammar.php', $this->source('UnsetGrammar', "\t\tunset(\$form['x']);\n"));
		FileSystem::write(
			$this->dir . '/Mutator.php',
			$this->source('Mutator', "\t\t\$form->getComponent('x')->setOmitted();\n"),
		);
		FileSystem::write(
			$this->dir . '/FormAnnotation.php',
			"<?php declare(strict_types = 1);\n\nfinal class FormAnnotation\n{\n\n\t/**\n\t * @form-disabler\n\t */\n\tpublic function disable(\$control): void\n\t{\n\t}\n\n}\n",
		);
		FileSystem::write($this->dir . '/AddsAnnotation.php', $this->addsAnnotationSource());

		// IN through a constant a marker file reads - the shape input that carries no marker.
		FileSystem::write($this->dir . '/ConstantConsumerForm.php', $this->constantConsumerSource());
		FileSystem::write(
			$this->dir . '/FieldNameConstant.php',
			$this->constantSource('FieldNameConstant', 'FIELD_NAME', "'fieldName'"),
		);
		FileSystem::write(
			$this->dir . '/ChoiceItemsConstant.php',
			$this->constantSource('ChoiceItemsConstant', 'CHOICE_ITEMS', "['a' => 'A', 'b' => 'B']"),
		);
		// One hop further: the alias's own value is another file's constant.
		FileSystem::write(
			$this->dir . '/AliasConstant.php',
			$this->constantSource('AliasConstant', 'ALIAS_NAME', 'InnerConstant::INNER_NAME'),
		);
		FileSystem::write(
			$this->dir . '/InnerConstant.php',
			$this->constantSource('InnerConstant', 'INNER_NAME', "'inner'"),
		);

		// OUT - near misses.
		FileSystem::write($this->dir . '/PlainClass.php', $this->source('PlainClass', "\t\t\$total = 1 + 1;\n"));
		FileSystem::write($this->dir . '/AddPrefixIdentifiers.php', $this->addPrefixIdentifiersSource());
		FileSystem::write(
			$this->dir . '/PlainDocBlock.php',
			"<?php declare(strict_types = 1);\n\nfinal class PlainDocBlock\n{\n\n\t/**\n\t * @param int \$x\n\t */\n\tpublic function f(\$x): void\n\t{\n\t}\n\n}\n",
		);
		FileSystem::write(
			$this->dir . '/UnrelatedConstant.php',
			$this->constantSource('UnrelatedConstant', 'UNRELATED_NAME', "'nope'"),
		);
		// The direction matters: ReadsShape fetches READ_ONLY_NAME but is itself outside the
		// subset, so the file declaring that constant stays outside too.
		FileSystem::write(
			$this->dir . '/ReadsShape.php',
			$this->source('ReadsShape', "\t\t\$form['x']->setValue(OnlyReadConstant::READ_ONLY_NAME);\n"),
		);
		FileSystem::write(
			$this->dir . '/OnlyReadConstant.php',
			$this->constantSource('OnlyReadConstant', 'READ_ONLY_NAME', "'readOnly'"),
		);
	}

	/**
	 * A registration declared ONLY by `@form-adds`. The method body registers nothing and the bare
	 * `addThing` declaration is not a call, so the docblock is the file's only marker — which is the
	 * point: the tag is a shape input that leaves no other trace in the token stream.
	 *
	 * @param string $controlClass the tag's class operand, the thing an edit moves
	 */
	private function addsAnnotationSource(string $controlClass = 'Nette\\Forms\\Controls\\TextInput'): string
	{
		return "<?php declare(strict_types = 1);\n\nfinal class AddsAnnotation\n{\n\n\t/**\n\t * @form-adds \$name $controlClass\n\t */\n\tpublic function addThing(string \$name): void\n\t{\n\t}\n\n}\n";
	}

	private function source(string $class, string $body): string
	{
		return "<?php declare(strict_types = 1);\n\nfinal class $class\n{\n\n\tpublic function f(\$form): void\n\t{\n$body\t}\n\n}\n";
	}

	private function subclassConstructorSource(string $extra = ''): string
	{
		return $this->source('SubclassConstructor', $extra . "\t\t\$form->addText('ctorField');\n");
	}

	private function constantSource(string $class, string $name, string $value): string
	{
		return "<?php declare(strict_types = 1);\n\nfinal class $class\n{\n\n\tpublic const $name = $value;\n\n}\n";
	}

	private function constantConsumerSource(): string
	{
		return $this->source(
			'ConstantConsumerForm',
			"\t\t\$form->addText(FieldNameConstant::FIELD_NAME);\n"
			. "\t\t\$form->addSelect('choice', ChoiceItemsConstant::CHOICE_ITEMS);\n"
			. "\t\t\$form->addText(AliasConstant::ALIAS_NAME);\n",
		);
	}

	// An add* NAME on its own must not qualify a file, or half the tree would be in the subset.
	private function addPrefixIdentifiersSource(): string
	{
		return "<?php declare(strict_types = 1);\n\nuse Other\\Address;\n\nfinal class AddPrefixIdentifiers\n{\n\n\tpublic function f(): int\n\t{\n\t\t\$address = 1;\n\t\t\$addend = 2;\n\n\t\treturn \$address + \$addend;\n\t}\n\n}\n";
	}

}
