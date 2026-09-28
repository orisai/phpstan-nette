<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Parser\ParentStmtTypesVisitor;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PipelineFactory;

final class RichAttributeDecoratorTest extends BaseTestCase
{

	public function testDecorateSetsParentStmtTypesAttributeInsideLoop(): void
	{
		$decorated = PipelineFactory::createRichAttributeDecorator()->decorate(
			$this->parse("<?php\nforeach (\$items as \$item) {\n\tcontinue;\n}\n"),
		);

		$continue = (new NodeFinder())->findFirstInstanceOf($decorated, Continue_::class);
		self::assertInstanceOf(Continue_::class, $continue);
		self::assertContains(Foreach_::class, $continue->getAttribute(ParentStmtTypesVisitor::ATTRIBUTE_NAME, []));
	}

	public function testDecorateLeavesBareContinueWithoutLoopAncestor(): void
	{
		$decorated = PipelineFactory::createRichAttributeDecorator()->decorate($this->parse("<?php\ncontinue;\n"));

		$continue = (new NodeFinder())->findFirstInstanceOf($decorated, Continue_::class);
		self::assertInstanceOf(Continue_::class, $continue);
		self::assertSame([], $continue->getAttribute(ParentStmtTypesVisitor::ATTRIBUTE_NAME, []));
	}

	/**
	 * @return array<Stmt>
	 */
	private function parse(string $php): array
	{
		$parser = (new ParserFactory())->createForNewestSupportedVersion();

		return $parser->parse($php) ?? [];
	}

}
