<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Application\UI\Control;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function sort;
use function str_replace;

// A name nobody provably defines on every path is reported, with an identifier of its own when some
// path may define it: a variable an include needs that only some contexts pass, a block of a template
// whose extenders are not all known, a provider one of the template's renderers never gives it.
final class PossiblyUndefinedNamesSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('possibly-undefined');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testPossiblyUndefinedNamesAreReported(): void
	{
		$createTemplateParameters = (new ReflectionMethod(
			Control::class,
			'createTemplate',
		))->getNumberOfParameters() > 0
			? '?string $class = null'
			: '';

		$this->project->write('src/a.latte', "{include 'mid.latte', title: 'A'}\n");
		$this->project->write('src/b.latte', "{include 'mid.latte'}\n");
		$this->project->write('src/mid.latte', "{include 'part.latte'}\n");
		$this->project->write('src/part.latte', "{varType string \$title}\n{\$title}\n");
		$this->project->write('src/orphan.latte', "{include #nowhere}\n");
		$this->project->write('src/StandaloneControl.php', <<<PHP
<?php declare(strict_types = 1);

final class StandaloneControl extends Nette\\Application\\UI\\Control
{

	public Nette\\Bridges\\ApplicationLatte\\TemplateFactory \$templateFactory;

	protected function createTemplate($createTemplateParameters): Nette\\Application\\UI\\Template
	{
		return \$this->templateFactory->createTemplate();
	}

	public function render(): void
	{
		\$this->getTemplate()->setFile(__DIR__ . '/widget.latte');
	}

}

PHP);
		$this->project->write('src/OwnControl.php', <<<'PHP'
<?php declare(strict_types = 1);

final class OwnControl extends Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->getTemplate()->setFile(__DIR__ . '/widget.latte');
	}

}

PHP);
		$this->project->write('src/widget.latte', "{control child}\n");

		self::assertSame(
			[
				"mid.latte:1 orisai.nette.latte.includePossiblyMissingVariable Include target 'src/part.latte' requires"
					. ' variable $title (string) that only 1 of the 2 contexts reaching this include provide, and has no default.',
				"orphan.latte:1 orisai.nette.latte.possiblyUnknownBlock Block 'nowhere' might not exist - it is not reachable"
					. " from 'src/orphan.latte', and no include, layout or renderer reaches the template, so the template"
					. ' rendering it is unknown.',
				'widget.latte:1 orisai.nette.latte.providerPossiblyUnavailable {control} needs the uiControl provider, only'
					. ' available when createTemplate() receives a control, but renderer StandaloneControl creates this template'
					. ' standalone (no control argument) - uiControl may be undefined here, so this macro may fatal at runtime.',
			],
			$this->analyse(),
		);
	}

	/**
	 * @return list<string>
	 */
	private function analyse(): array
	{
		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => [
					'nette' => [
						'latte' => [
							'enabled' => true,
							'discovery' => [
								'enabled' => true,
								'storePath' => $this->project->path('discovery'),
							],
							'firstPartyPaths' => [$this->project->path('src')],
						],
					],
				],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if ($message['identifier'] === 'orisai.nette.latte.orphanTemplate') {
				continue;
			}

			$findings[] = str_replace($this->project->path('src') . '/', '', $message['file']) . ':' . $message['line']
				. ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		sort($findings);

		return $findings;
	}

}
