<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Generator;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Tag;
use Latte\Compiler\TemplateParser;
use Latte\Extension;
use function array_keys;

// Registered last, so its parsers win Latte's last-registration-wins tag dispatch
// (TemplateParser::addTags()): the type tags are re-implemented to record their arguments and every
// claimed unknown name gets a passthrough parser.
final class AnalysisExtension extends Extension
{

	private TypeCapturingParsers $typeCapture;

	/** @var array<string, bool> */
	private array $passthroughTags;

	/** @var array<string, true> */
	private array $passthroughAttributes;

	/**
	 * @param array<string, bool> $passthroughTags name => paired
	 * @param array<string, true> $passthroughAttributes
	 */
	public function __construct(TypeCapturingParsers $typeCapture, array $passthroughTags, array $passthroughAttributes)
	{
		$this->typeCapture = $typeCapture;
		$this->passthroughTags = $passthroughTags;
		$this->passthroughAttributes = $passthroughAttributes;
	}

	/**
	 * @return array<string, callable(Tag, TemplateParser): (Node|Generator<int, null, array{AreaNode, Tag|null}, PassthroughNode>)>
	 */
	public function getTags(): array
	{
		$tags = $this->typeCapture->getTags();

		foreach ($this->passthroughTags as $name => $paired) {
			$tags[$name] = $paired ? PassthroughTagParser::paired() : PassthroughTagParser::unpaired();
		}

		// An n:-prefixed registration is attribute-only and gets no inner-/tag- variants of its own
		// (TemplateParser::addTags() derives those for generator TAG parsers only), while
		// TemplateParserHtml matches n:inner-x by the "inner-x" key and then parses it with the "x"
		// parser - so all three names are registered.
		foreach (array_keys($this->passthroughAttributes) as $name) {
			foreach ([$name, 'inner-' . $name, 'tag-' . $name] as $attribute) {
				$tags['n:' . $attribute] = PassthroughTagParser::paired();
			}
		}

		return $tags;
	}

}
