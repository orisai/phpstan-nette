<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\LatteForms;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormsResultCacheMeta;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\LatteForms\LatteFormsRule;
use Tests\OriPhpstan\Nette\Integration\Latte\Invalidation\LatteInvalidationMatrixCase;
use Tests\OriPhpstan\Nette\Toolkit\InvalidationScenario;
use Tests\OriPhpstan\Nette\Toolkit\LattePhpstanConfig;
use function dirname;

// Matrix group E - the CROSS-EXTENSION rows. Every other group moves a fact inside one extension;
// here the fact starts in a form builder analysed by the Forms extension and has to reach a .latte
// file the Latte extension owns, through the discovery store's link. The bridge adds no invalidation
// machinery of its own, which is exactly why these rows exist: "the aggregate stage inherits it" is
// an assumption until a cold-vs-warm scenario says otherwise, and the body-level edit below is the
// one that was silently stale in all three extensions before the 2026-07-27 invalidation work.
//
// The corpus is deliberately minimal and deliberately CLOSED: ScratchBridgeForm declares no
// constructor of its own (an inherited one raises no constructor_build marker), the builder returns
// through a local variable, and nothing anywhere reaches into the component from outside its
// builder - the three conditions the bridge's certainty gate needs before it will call any name
// absent. A corpus failing any of them would answer "unresolved" to every question and could never
// tell a stale verdict from a fresh one.
final class LatteFormsBridgeInvalidationTest extends LatteInvalidationMatrixCase
{

	private const TEMPLATE = 'bridge-form.latte';

	private const SECOND_TEMPLATE = 'other.latte';

	private const CONTROL_CLASS = 'ScratchBridgeControl';

	private const LINK_CLASS = 'ScratchBridgeLinkControl';

	private const BRIDGE_CONFIG_PATH = __DIR__ . '/Fixtures/integration.neon';

	private const SALT_OFF_CONFIG_PATH = __DIR__ . '/Fixtures/invalidation-forms-salt-off.neon';

	private const FORMS_WIRING_PATH = __DIR__ . '/../../../config/forms.neon';

	// The service and argument the salt-off fixture switches off, by the names a NEON file can reach
	// them by. Scenario 31 says something about the bridge's PLACEMENT only while this really is what
	// FormsResultCacheMeta's enabled flag is built from; rename or rewire it and the fixture would go
	// on parsing, the salt would stay live, and the row would quietly become scenario 25 again.
	private const SALT_SERVICE = 'formsResultCacheMeta';

	private const SALT_ARGUMENT = 'enabled';

	private const BOTH_FIELDS = "\t\t\$form->addText('name');\n\t\t\$form->addText('email');\n";

	private const NAME_FIELD_ONLY = "\t\t\$form->addText('name');\n";

	private string $configPath = self::BRIDGE_CONFIG_PATH;

	// Scenario 25 - THE ROW. A control is removed from a form builder's METHOD BODY: the signature
	// line, the return type and every exported node of ScratchBridgeControl stay byte-identical, so
	// PHPStan's own result cache propagates nothing to dependents (ResultCacheManager needs
	// exportedNodesChanged() !== null), and the template has no dependency edge on the builder at all.
	// The finding still has to appear on a WARM run, on a file whose own bytes never moved - and then
	// disappear again when the control comes back.
	public function testControlRemovedFromAFormBuilderBodyReachesTheTemplateWarm(): void
	{
		$this->assertScenario(
			'lf-body-control',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::NAME_FIELD_ONLY),
					),
					'expect' => [$this->unknownControl(3, 'email')],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::BOTH_FIELDS),
					),
					'expect' => [],
				],
			],
		);
	}

	// Scenario 26 - the same edit two files away and one class up: the builder lives on a base class
	// the renderer only inherits, so the template's verdict depends on a body PhpRenderWalk and the
	// Forms index reach only after resolving through a file the edit never touches.
	public function testControlRemovedFromAnInheritedBuilderBodyReachesTheTemplateWarm(): void
	{
		$this->assertScenario(
			'lf-body-inherited',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						'ScratchBridgeBaseControl.php',
						$this->baseControlSource(self::NAME_FIELD_ONLY),
					),
					'expect' => [$this->unknownControl(3, 'email')],
				],
			],
			function (InvalidationScenario $scenario): void {
				$scenario->write('ScratchBridgeBaseControl.php', $this->baseControlSource(self::BOTH_FIELDS));
				$scenario->write(self::CONTROL_CLASS . '.php', $this->inheritingControlSource());
			},
		);
	}

	// Scenario 27 - the FORM-scope identifier's own row. Renaming the component's builder is what
	// takes `{form simpleGrid}` from "resolved, provably not a form" to "not resolvable at all", and
	// the bridge answers those two states differently on purpose: the first reports, the second is
	// silent (an unresolvable component is a question the analyser could not answer, never a missing
	// component). Both transitions have to happen warm, in both directions.
	public function testRenamingAFormComponentMovesTheUnknownFormFindingWarm(): void
	{
		$this->assertScenario(
			'lf-form-rename',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::BOTH_FIELDS, 'createComponentRenamedGrid'),
					),
					'expect' => [],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::BOTH_FIELDS),
					),
					'expect' => [$this->unknownForm(5, 'simpleGrid')],
				],
			],
			fn (InvalidationScenario $scenario) => $scenario->write(self::TEMPLATE, $this->templateWithGridSource()),
			[$this->unknownForm(5, 'simpleGrid')],
		);
	}

	// Scenario 28 - the other side of the join: the TEMPLATE's own macro is edited while every PHP
	// file stays byte-identical. The reference set is per-template and content-addressed, so this row
	// pins that the cache key really is the template's content and not its path.
	public function testTemplateMacroEditUpdatesItsOwnFindingsWarm(): void
	{
		$this->assertScenario(
			'lf-template-macro',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::TEMPLATE,
						$this->templateSourceFor('nope'),
					),
					'expect' => [$this->unknownControl(3, 'nope')],
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::TEMPLATE,
						$this->templateSourceFor('email'),
					),
					'expect' => [],
				],
			],
		);
	}

	// Scenario 29 - the INVERSE CONTROL. A comment-only edit to the form's own file must not
	// re-analyse the template: the edited file itself always reanalyses (PHPStan gates that on the
	// content hash), so 1 is the floor and anything above it is the bridge having bought its
	// cross-extension freshness by invalidating coarsely. Without this row every scenario above
	// would still pass under a whole-cache salt, which is the cheapest wrong way to make them pass.
	public function testCommentOnlyEditToTheFormFileReanalysesNothingButThatFile(): void
	{
		$this->assertScenario(
			'lf-ctl-comment',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(
							self::BOTH_FIELDS,
							'createComponentSimpleGrid',
							"\n// A comment carrying no semantic content whatsoever.\n",
						),
					),
					'expect' => [],
					'maxReanalysed' => 1,
				],
			],
		);
	}

	// Scenario 30 - the DISCOVERY-LINK row. Rows 25-27 reach their settled state through a FULL
	// recompute: their edits move a form fact, so the Forms extension's FormFactSalt discards the
	// whole result cache before the join is asked anything. This edit re-points a renderer's
	// setFile() in a class carrying no add*(), no createComponent* and no @form doc tag - outside
	// that salt's fact-bearing subset by construction - so the cache stays RESTORED and only the
	// edited file is reanalysed, while the template's own bytes never move and its finding still has
	// to follow the changed template->renderer link. What carries it is the store record's own
	// exported constant (measured: a per-file placement passes this row too, because the compiled
	// template FETCHES that constant and PHPStan re-queues a fetcher when the value moves); the row
	// pins that edge and the join's freshness over it, and scenario 31 pins the placement.
	public function testRepointingARendererLinkReachesTheTemplateWarmWithTheCacheRestored(): void
	{
		$this->assertScenario(
			'lf-link-repoint',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::LINK_CLASS . '.php',
						$this->plainRendererSource(self::LINK_CLASS, self::SECOND_TEMPLATE),
					),
					'expect' => [$this->unknownControl(3, 'email')],
					'maxReanalysed' => 1,
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::LINK_CLASS . '.php',
						$this->plainRendererSource(self::LINK_CLASS, self::TEMPLATE),
					),
					'expect' => [],
				],
			],
			function (InvalidationScenario $scenario): void {
				// The template's own renderer stops building `email`, so what holds the finding back in
				// the seeded state is the SECOND link alone: ScratchBridgeLinkControl declares no
				// simpleForm, and a linked renderer whose component does not resolve leaves the form set
				// a subset of what may render this template.
				$scenario->write(self::CONTROL_CLASS . '.php', $this->rendererSource(self::NAME_FIELD_ONLY));
				$scenario->write(self::SECOND_TEMPLATE, "<p>other</p>\n");
				// Keeps other.latte linked in every step, so the only thing any step changes is
				// bridge-form.latte's own renderer set.
				$scenario->write(
					'ScratchBridgeOtherRenderer.php',
					$this->plainRendererSource('ScratchBridgeOtherRenderer', self::SECOND_TEMPLATE),
				);
				$scenario->write(
					self::LINK_CLASS . '.php',
					$this->plainRendererSource(self::LINK_CLASS, self::TEMPLATE),
				);
			},
		);
	}

	// Scenario 31 - THE PLACEMENT ROW: scenario 25's edit with the OTHER extension's whole-cache salt
	// switched off. That is the one configuration in which a form-body edit says anything about where
	// this rule runs - with the salt live the cache is discarded wholesale and every row would pass
	// from a per-file placement just as well (measured). Here the cache is restored, exactly one file
	// (the edited builder) is reanalysed, the template is not, and the finding still appears warm:
	// the only thing that can produce it is the join re-running at the aggregate stage over
	// cached-plus-fresh collected data. This is also the row that survives a future narrowing of
	// FormFactSalt, which would otherwise take the guarantee away silently.
	public function testControlRemovedFromABuilderBodyReachesTheTemplateWithNoFormsWholeCacheSalt(): void
	{
		$this->assertSaltOffFixtureStillReachesTheSalt();
		$this->configPath = self::SALT_OFF_CONFIG_PATH;

		$this->assertScenario(
			'lf-body-nosalt',
			[
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::NAME_FIELD_ONLY),
					),
					'expect' => [$this->unknownControl(3, 'email')],
					'maxReanalysed' => 1,
				],
				[
					'mutate' => fn (InvalidationScenario $scenario) => $scenario->write(
						self::CONTROL_CLASS . '.php',
						$this->rendererSource(self::BOTH_FIELDS),
					),
					'expect' => [],
				],
			],
		);
	}

	/**
	 * Scenario 31's premise, asserted instead of assumed: the fixture switches the salt off BY NAME,
	 * and nothing else in the repo would notice if the Forms extension renamed the service or built
	 * FormsResultCacheMeta's enabled flag from something else. The row would still pass - as a second
	 * copy of scenario 25, with the whole-cache salt live - and the only test in the repo that fails
	 * if the bridge's rule leaves the aggregate stage would be pinning nothing.
	 */
	private function assertSaltOffFixtureStillReachesTheSalt(): void
	{
		$consequence = ' - scenario 31 would keep passing as a duplicate of scenario 25, with the Forms '
			. 'whole-cache salt still live, and would stop pinning the bridge rule\'s aggregate-stage placement.';

		$wiring = Neon::decode(FileSystem::read(self::FORMS_WIRING_PATH));
		self::assertIsArray($wiring);
		self::assertIsArray($wiring['services']);
		$service = $wiring['services'][self::SALT_SERVICE] ?? null;
		self::assertIsArray($service, self::SALT_SERVICE . ' is no longer a named service' . $consequence);
		self::assertSame(
			FormsResultCacheMeta::class,
			$service['class'] ?? null,
			self::SALT_SERVICE . ' no longer builds ' . FormsResultCacheMeta::class . $consequence,
		);
		self::assertIsArray($service['arguments'] ?? null);
		self::assertSame(
			'%orisai.nette.forms.enabled%',
			$service['arguments'][self::SALT_ARGUMENT] ?? null,
			'the ' . self::SALT_ARGUMENT . ' argument of ' . FormsResultCacheMeta::class . ' is no longer '
				. 'the Forms switch' . $consequence,
		);

		$fixture = Neon::decode(FileSystem::read(self::SALT_OFF_CONFIG_PATH));
		self::assertIsArray($fixture);
		self::assertArrayNotHasKey(
			'parameters',
			$fixture,
			'the salt-off fixture must leave every parameter alone: switching Forms off switches the bridge off too',
		);
		self::assertSame(
			[self::SALT_SERVICE => ['arguments' => [self::SALT_ARGUMENT => false]]],
			$fixture['services'] ?? null,
			'the salt-off fixture no longer switches exactly the salt off' . $consequence,
		);
	}

	protected function newScenario(string $name): InvalidationScenario
	{
		$projectRoot = dirname(__DIR__, 3);

		return InvalidationScenario::create(
			$projectRoot,
			'latte-forms-inval-' . $name,
			fn (array $paths, string $tmpDir): string => LattePhpstanConfig::create(
				$this->configPath,
				$paths,
				$tmpDir,
				[
					'orisai.nette.latte.discovery.storePath' => $paths[0] . '/discovery',
					'orisai.nette.latte.firstPartyPaths' => [$paths[0]],
				],
			)->getConfigPath(),
		);
	}

	protected function seed(InvalidationScenario $scenario): void
	{
		$scenario->write('ScratchBridgeForm.php', $this->formClassSource());
		$scenario->write('ScratchBridgeGrid.php', $this->gridClassSource());
		$scenario->write(self::CONTROL_CLASS . '.php', $this->rendererSource(self::BOTH_FIELDS));
		$scenario->write(self::TEMPLATE, $this->templateSourceFor('email'));

		// The template->renderer edge must exist before the first parse, exactly as the
		// pre-analysis index build bakes it in.
		DiscoveryStore::bootstrap(
			$scenario->getSourceDir() . '/discovery',
			[$scenario->projectRelative(self::TEMPLATE)],
		);
	}

	private function unknownControl(int $line, string $control): string
	{
		return self::TEMPLATE . ':' . $line . ' :: ' . LatteFormsRule::UNKNOWN_CONTROL_IDENTIFIER
			. " :: Control '" . $control . "' does not exist on form 'simpleForm' ("
			. self::CONTROL_CLASS . ').';
	}

	private function unknownForm(int $line, string $component): string
	{
		return self::TEMPLATE . ':' . $line . ' :: ' . LatteFormsRule::UNKNOWN_FORM_IDENTIFIER
			. " :: Component '" . $component . "' is not a form (" . self::CONTROL_CLASS . ').';
	}

	private function templateSourceFor(string $secondControl): string
	{
		return "{form simpleForm}\n"
			. "\t<input n:name=\"name\">\n"
			. "\t<input n:name=\"$secondControl\">\n"
			. "{/form}\n";
	}

	private function templateWithGridSource(): string
	{
		return $this->templateSourceFor('email') . "{form simpleGrid}\n{/form}\n";
	}

	// A Form subclass declaring no constructor of its own, exactly as this repo's own forms are:
	// ConstructorFormShapeResolver skips an inherited constructor, while a bare
	// `new Nette\Application\UI\Form()` raises constructor_build and would leave every shape open.
	private function formClassSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchBridgeForm extends \Nette\Application\UI\Form
{

}

PHP;
	}

	private function gridClassSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchBridgeGrid extends \Nette\ComponentModel\Container
{

}

PHP;
	}

	private function rendererSource(
		string $formFields,
		string $gridFactory = 'createComponentSimpleGrid',
		string $comment = ''
	): string
	{
		return <<<PHP
<?php declare(strict_types = 1);
$comment
final class ScratchBridgeControl extends \Nette\Application\UI\Control
{

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/bridge-form.latte');
	}

	public function createComponentSimpleForm(): ScratchBridgeForm
	{
		\$form = new ScratchBridgeForm();
$formFields
		return \$form;
	}

	public function $gridFactory(): ScratchBridgeGrid
	{
		\$grid = new ScratchBridgeGrid();

		return \$grid;
	}

}

PHP;
	}

	// A renderer that carries a template link and nothing else: no add* call, no createComponent*
	// prefix and no @form doc tag, which is what keeps it out of FormFactSalt's fact-bearing subset
	// and makes scenario 30's edit one the Forms extension's whole-cache salt cannot cover.
	private function plainRendererSource(string $className, string $template): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

final class $className extends \Nette\Application\UI\Control
{

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/$template');
	}

}

PHP;
	}

	private function inheritingControlSource(): string
	{
		return <<<'PHP'
<?php declare(strict_types = 1);

final class ScratchBridgeControl extends ScratchBridgeBaseControl
{

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/bridge-form.latte');
	}

}

PHP;
	}

	private function baseControlSource(string $formFields): string
	{
		return <<<PHP
<?php declare(strict_types = 1);

abstract class ScratchBridgeBaseControl extends \Nette\Application\UI\Control
{

	public function createComponentSimpleForm(): ScratchBridgeForm
	{
		\$form = new ScratchBridgeForm();
$formFields
		return \$form;
	}

}

PHP;
	}

}
