<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Latte3\Fixtures;

use Generator;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Extension;
use RuntimeException;
use stdClass;

final class ThrowingTagsExtension extends Extension
{

	/**
	 * @return array<string, (callable(Tag, TemplateParser): (Generator<int, list<string>|null, array{AreaNode, Tag|null}, Node|null>|Node|void))|stdClass>
	 */
	public function getTags(): array
	{
		throw new RuntimeException('fixture enumeration-stage failure');
	}

}
