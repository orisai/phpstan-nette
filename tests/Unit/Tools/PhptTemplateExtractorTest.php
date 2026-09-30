<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Tools;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Tools\Corpus\ExtractedTemplate;
use OriPhpstan\Nette\Tools\Corpus\PhptTemplateExtractor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_map;

final class PhptTemplateExtractorTest extends BaseTestCase
{

	public function testCallsVariablesAndLiteralLoaders(): void
	{
		self::assertSame(
			[
				$this->template('<p>{=1}</p>', 8, 8, 'compile'),
				$this->template("<ul n:if=\"true\">\n\t<li>A</li>\n</ul>", 10, 10, 'renderToString'),
				$this->template('{foreach [1, 2] as $item}{$item}{/foreach}', 16, 20, 'render', null, '$template'),
				$this->template('{if}', 24, 24, 'compile', null, null, true),
				$this->template("{block content}\n{\$title}\n{/block}", 28, 28, 'createTemplate'),
				$this->template('{include "b.latte"}', 35, 34, 'StringLoader', 'a.latte'),
				$this->template('{$b}', 36, 34, 'StringLoader', 'b.latte'),
			],
			$this->extract('calls.phpt'),
		);
	}

	public function testArraysForADynamicLoader(): void
	{
		self::assertSame(
			[
				$this->template('{embed "embed"}{/embed}', 13, 13, 'StringLoader', 'main'),
			],
			$this->extract('loops.phpt'),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function extract(string $fixture): array
	{
		$code = FileSystem::read(__DIR__ . '/Fixtures/checkout/tests/' . $fixture);

		return array_map(
			static fn (ExtractedTemplate $template): array => (array) $template,
			(new PhptTemplateExtractor())->extract($code),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function template(
		string $content,
		int $line,
		int $callLine,
		string $call,
		?string $loaderKey = null,
		?string $variable = null,
		bool $expectsException = false
	): array
	{
		return [
			'content' => $content,
			'line' => $line,
			'callLine' => $callLine,
			'call' => $call,
			'loaderKey' => $loaderKey,
			'variable' => $variable,
			'expectsException' => $expectsException,
		];
	}

}
