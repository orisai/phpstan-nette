<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Base\BaseOnly;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported\ImportedOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\NameScopeTemplate;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\SiblingOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Traits\TraitOnly;
use function sort;

// A {templateType} class writes its docblock types against its own namespace and imports; the
// compiled template is a global-namespace class, so every such type must reach it qualified.
final class TemplateTypeNameScopeSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('templatetype-name-scope');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testDocblockTypesOfTheTemplateClassAreQualified(): void
	{
		$this->project->write('src/scope.latte', '{templateType ' . NameScopeTemplate::class . "}\n" . <<<'LATTE'
{foreach $siblings as $sibling}{$sibling->missingSibling}{/foreach}
{foreach $nested as $options}{foreach $options as $option}{$option->missingNested}{/foreach}{/foreach}
{foreach $fromTrait as $traitOption}{$traitOption->missingTrait}{/foreach}
{foreach $inherited as $parentOption}{$parentOption->missingInherited}{/foreach}
{if $inheritedSibling !== null}{$inheritedSibling->missingBase}{/if}
{$shape['first']->missingShape}
{$mode}{$selfMode}{$range}{$classString}

LATTE);

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => ['nette' => ['latte' => ['enabled' => true]]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		sort($findings);

		$expected = [
			'2 property.notFound Access to an undefined property ' . SiblingOption::class . '::$missingSibling.',
			'3 property.notFound Access to an undefined property ' . ImportedOption::class . '::$missingNested.',
			'4 property.notFound Access to an undefined property ' . TraitOnly::class . '::$missingTrait.',
			'5 property.notFound Access to an undefined property ' . ImportedOption::class . '::$missingInherited.',
			'6 property.notFound Access to an undefined property ' . BaseOnly::class . '::$missingBase.',
			'7 property.notFound Access to an undefined property ' . SiblingOption::class . '::$missingShape.',
		];
		sort($expected);

		self::assertSame($expected, $findings);
	}

}
