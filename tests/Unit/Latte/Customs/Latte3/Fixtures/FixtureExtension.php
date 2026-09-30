<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3\Fixtures;

use Closure;
use Generator;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\AuxiliaryNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Extension;
use Latte\Runtime\Template;
use stdClass;

final class FixtureExtension extends Extension
{

	/**
	 * @return array<string, (callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void))|stdClass>
	 */
	public function getTags(): array
	{
		return ['hello' => $this->parseHello(...)];
	}

	/**
	 * @return Generator<int, list<string>|null, array{AreaNode, Tag|null}, AuxiliaryNode>
	 */
	public function parseHello(Tag $tag): Generator
	{
		[$content] = yield;

		return new AuxiliaryNode(
			static fn (PrintContext $context, AreaNode $content): string => $context->format("echo 'hello '; %node", $content),
			[$content],
		);
	}

	/**
	 * @return array{shout: Closure(string): string, trimmed: Closure(string, string=): string}
	 */
	public function getFilters(): array
	{
		return ['shout' => $this->shout(...), 'trimmed' => trim(...)];
	}

	/**
	 * @return array{twice: Closure(int): int, greet: Closure(Template, string): string, greetMaybe: Closure(Template|null, string): string}
	 */
	public function getFunctions(): array
	{
		return [
			'twice' => $this->twice(...),
			'greet' => $this->greet(...),
			'greetMaybe' => $this->greetMaybe(...),
		];
	}

	public function getProviders(): array
	{
		return ['foo' => new stdClass()];
	}

	public function shout(string $s): string
	{
		return $s . '!';
	}

	public function twice(int $n): int
	{
		return 2 * $n;
	}

	public function greet(Template $template, string $name): string
	{
		return $template->getName() . $name;
	}

	public function greetMaybe(?Template $template, string $name): string
	{
		return ($template !== null ? $template->getName() : '') . $name;
	}

}
