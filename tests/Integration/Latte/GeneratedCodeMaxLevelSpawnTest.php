<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use OriPhpstan\Nette\Latte\Runtime\Helpers;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function basename;
use function sort;

// At level max every `mixed` is reported, so a generated statement or a lost type surfaces as a finding the template's
// author did not write. Only the template's own looseness may be reported, once and on its own line.
final class GeneratedCodeMaxLevelSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('generated-code-max-level');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testOnlyTheTemplatesOwnLoosenessIsReported(): void
	{
		$this->project->write('src/@layout.latte', <<<'LATTE'
{include content}

LATTE);
		$this->project->write('src/page.latte', <<<'LATTE'
{templateType Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel\PageTemplate}
{layout '@layout.latte'}
{block content}
{$control->heading()}
{form $form}
{label $name}Name{/label}
{label $name, class => a /}
{input $name}
{input $name, class => a}
<select n:name="$country"></select>
<input n:name="$gender:male">
{input $gender:female}
{inputError $name}
{input $loose}
{/form}
{foreach $loose as $item}{$iterator->counter}{/foreach}
{foreach $items as $item}{$iterator->counter}{$item}{/foreach}
<div n:class="a, $loose"></div>
{/block}

LATTE);
		$this->project->write('src/list.latte', <<<'LATTE'
{varType list<Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel\Row> $rows}
{include '_rows.latte'}

LATTE);
		$this->project->write('src/map.latte', <<<'LATTE'
{varType array<int, Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel\Row> $rows}
{include '_rows.latte'}

LATTE);
		$this->project->write('src/_rows.latte', <<<'LATTE'
{block rows}{foreach $rows as $row}{$row->name}{/foreach}{/block}

LATTE);

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'level' => 'max',
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => ['latte' => ['enabled' => true]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = basename($message['file']) . ':' . $message['line'] . ' ' . $message['identifier'] . ' '
				. $message['message'];
		}

		sort($findings);
		self::assertSame([
			'page.latte:14 argument.type Parameter #1 $name of static method ' . Helpers::class
				. '::formField() expects int|object|string, mixed given.',
			'page.latte:16 foreach.nonIterable Argument of an invalid type mixed supplied for foreach, only iterables are '
				. 'supported.',
			'page.latte:18 argument.type Parameter #1 $classes of static method ' . Helpers::class
				. '::classes() expects array<int|string, bool|string|null>, array<int, mixed> given.',
		], $findings);
	}

}
