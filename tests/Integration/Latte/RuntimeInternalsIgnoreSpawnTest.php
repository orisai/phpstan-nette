<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte;

use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use function in_array;

// config/latte.neon ignores the runtime internals every generated template class carries, per Latte
// line: with reportUnmatchedIgnoredErrors on (PHPStan's default) the installed line's entries match
// and the other line's entries stay silent, so a project never sees an unmatched-ignore error.
final class RuntimeInternalsIgnoreSpawnTest extends BaseTestCase
{

	private const RUNTIME_INTERNAL_IDENTIFIERS = [
		'ignore.unmatched',
		'shipmonk.deadConstant',
		'shipmonk.deadMethod',
		'property.private',
		'property.notFound',
		'method.internal',
		'staticMethod.internalClass',
	];

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('runtime-internals-ignore');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testNoRuntimeInternalAndNoUnmatchedIgnoreOnTheInstalledLine(): void
	{
		$this->project->write(
			'src/page.latte',
			"{varType string \$x}\n{block content}{\$x}{/block}\n{snippet s}{\$x}{/snippet}\n{include #content}\n",
		);
		$this->project->write('src/xml.latte', "{contentType application/xml}\n{varType string \$x}\n<a>{\$x}</a>\n");
		if (TestAdapter::factory()->family()->latteLine === ShapeFamily::LATTE_31) {
			$this->project->write(
				'src/child.latte',
				"{extends 'page.latte', x => 'a'}\n{block content}child{/block}\n",
			);
		}

		$result = $this->project->analyse(
			ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'reportUnmatchedIgnoredErrors' => true,
				'orisaiNette' => ['latte' => ['enabled' => true]],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if (in_array($message['identifier'], self::RUNTIME_INTERNAL_IDENTIFIERS, true)) {
				$findings[] = $message['line'] . ' ' . $message['identifier'] . ' ' . $message['message'];
			}
		}

		self::assertSame([], $findings);
	}

}
