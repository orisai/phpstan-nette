<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function basename;
use function in_array;
use function sort;

// PHPStan's unused-variable rule (bleedingEdge featureToggles.unusedVariable) sees only what the template's own
// source writes: the bindings Latte generates around {foreach}, {form}, {formContainer} and dynamic snippets
// are pruned when nothing reads them, a variable the template writes and never reads is still reported.
final class GeneratedBindingUnusedSpawnTest extends BaseTestCase
{

	private const UNUSED_IDENTIFIERS = [
		'assign.overwritten',
		'assign.unused',
		'assign.unusedFlow',
		'foreach.unusedKey',
		'foreach.unusedValue',
		'postInc.overwritten',
		'postInc.unusedFlow',
		'variable.unused',
	];

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('generated-binding-unused');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testOnlyTheTemplatesOwnUnusedWritesAreReported(): void
	{
		$this->project->write('src/page.latte', <<<'LATTE'
{varType array $items}
{varType array $groups}
{foreach $items as $item}{$item}{/foreach}
{foreach $items as $item}{if $iterator->isFirst()}first{/if}{$item}{/foreach}
{foreach $groups as $group}{foreach $group as $item}{$iterator->counter}{/foreach}{/foreach}
{foreach $items as $item}{$item}{else}empty{/foreach}
{foreach $items as $item}{include #row, item => $item}{/foreach}
{snippet list}{foreach $items as $item}<p n:snippet="item-{$item}">{$item}</p>{/foreach}{/snippet}
{var $unused = 1}
{foreach $items as $key => $item}{$item}{/foreach}
{var $i = 0}
{foreach $items as $item}{var $i = $i + 1}{/foreach}
{define row}{$item}{/define}

LATTE);

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'featureToggles' => ['unusedVariable' => true],
				'orisai' => ['nette' => ['latte' => ['enabled' => true]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if (in_array($message['identifier'], self::UNUSED_IDENTIFIERS, true)) {
				$findings[] = basename($message['file']) . ':' . $message['line'] . ' ' . $message['identifier'] . ' '
					. $message['message'];
			}
		}

		sort($findings);
		self::assertSame([
			'page.latte:10 foreach.unusedKey Foreach key variable $key is never read.',
			'page.latte:11 assign.unusedFlow Value assigned to variable $i only flows into values that are never used.',
			'page.latte:12 assign.unusedFlow Value assigned to variable $i only flows into values that are never used.',
			'page.latte:12 foreach.unusedValue Foreach value variable $item is never read.',
			'page.latte:9 variable.unused Variable $unused is never read.',
		], $findings);
	}

}
