<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\CompileException;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Expression\AssignNode;
use Latte\Compiler\Nodes\Php\Expression\VariableNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\ParameterNode;
use Latte\Compiler\Nodes\Php\Scalar\NullNode;
use Latte\Compiler\Position;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Latte\Compiler\Token;
use Latte\Essential\Nodes\ParametersNode;
use Latte\Essential\Nodes\TemplateTypeNode;
use Latte\Essential\Nodes\VarTypeNode;
use function is_string;
use function ltrim;
use function strlen;
use function substr;
use function trim;

// Re-implements the CoreExtension declaration tags so their arguments are recorded: Latte's own
// VarTypeNode/TemplateTypeNode discard them at parse time, VarNode drops the assignment types and
// ParametersNode keeps only the whitespace-free printed form. The returned nodes are Latte's own
// (or print-identical subclasses), so the generated code is unchanged.
final class TypeCapturingParsers
{

	/** @var list<CapturedDeclaration> */
	private array $captured = [];

	/**
	 * @return array<string, callable(Tag): Node>
	 */
	public function getTags(): array
	{
		return [
			'varType' => fn (Tag $tag): Node => $this->varType($tag),
			'templateType' => fn (Tag $tag): Node => $this->templateType($tag),
			'parameters' => fn (Tag $tag): Node => $this->parameters($tag),
			'var' => fn (Tag $tag): Node => $this->var($tag),
			'default' => fn (Tag $tag): Node => $this->var($tag),
		];
	}

	/**
	 * @return list<CapturedDeclaration>
	 */
	public function getCaptured(): array
	{
		return $this->captured;
	}

	private function varType(Tag $tag): VarTypeNode
	{
		$tag->expectArguments();
		$stream = $tag->parser->stream;
		$base = $this->textBase($tag);
		$start = $stream->peek();
		$tag->parser->parseType();
		$variable = $stream->consume(Token::Php_Variable);

		$type = $this->slice($tag, $base, $start, $variable);
		if ($type === '') {
			return new VarTypeNode();
		}

		$declaration = new CapturedDeclaration(
			CapturedDeclaration::VAR_TYPE,
			$type,
			ltrim($variable->text, '$'),
			null,
			$tag->position->line,
		);
		$this->captured[] = $declaration;

		return new VarTypeDeclarationNode($declaration);
	}

	// Mirrors VarNode::create() (Latte 3.0.26 and 3.1.6 parse identically), keeping the type each
	// assignment was declared with.
	private function var(Tag $tag): VarDeclarationNode
	{
		$tag->expectArguments();
		$stream = $tag->parser->stream;
		$node = new VarDeclarationNode();
		$node->default = $tag->name === 'default';

		do {
			$type = $tag->parser->parseType();
			$save = $stream->getIndex();
			$expr = $stream->is(Token::Php_Variable) ? $tag->parser->parseExpression() : null;
			if ($expr instanceof VariableNode) {
				$node->assignments[] = new AssignNode($expr, new NullNode());
			} elseif ($expr instanceof AssignNode && (!$node->default || $expr->var instanceof VariableNode)) {
				$node->assignments[] = $expr;
			} else {
				$stream->seek($save);
				$stream->throwUnexpectedException([], ' in ' . $tag->getNotation());
			}

			$node->types[] = $type === null ? null : $type->type;
		} while ($stream->tryConsume(',') !== null && !$stream->is(Token::End));

		return $node;
	}

	private function templateType(Tag $tag): TemplateTypeNode
	{
		$class = ltrim(trim($tag->parser->text), '\\');
		$node = TemplateTypeNode::create($tag);

		$this->captured[] = new CapturedDeclaration(
			CapturedDeclaration::TEMPLATE_TYPE,
			$class,
			null,
			null,
			$tag->position->line,
		);

		return $node;
	}

	// Mirrors ParametersNode::create() (Latte 3.0.26 and 3.1.6 are identical here), adding the
	// source slices of each type and default.
	private function parameters(Tag $tag): ParametersNode
	{
		if (!$tag->isInHead()) {
			throw new CompileException('{parameters} is allowed only in template header.', $tag->position);
		}

		$tag->expectArguments();
		$stream = $tag->parser->stream;
		$base = $this->textBase($tag);
		$node = new ParametersNode();

		do {
			$start = $stream->peek();
			$type = $tag->parser->parseType();
			$variable = $stream->peek();
			$typeText = $type === null ? null : $this->slice($tag, $base, $start, $variable);

			$save = $stream->getIndex();
			$expr = $stream->is(Token::Php_Variable) ? $tag->parser->parseExpression() : null;
			if ($expr instanceof VariableNode && is_string($expr->name)) {
				$node->parameters[] = new ParameterNode($expr, new NullNode(), $type);
				$this->captureParameter($tag, $typeText, $expr->name, null);
			} elseif (
				$expr instanceof AssignNode
				&& $expr->var instanceof VariableNode
				&& is_string($expr->var->name)
			) {
				$node->parameters[] = new ParameterNode($expr->var, $expr->expr, $type);
				$this->captureParameter(
					$tag,
					$typeText,
					$expr->var->name,
					$this->defaultText($tag, $base, $expr->expr),
				);
			} else {
				$stream->seek($save);
				$stream->throwUnexpectedException([], ' in ' . $tag->getNotation());
			}
		} while ($stream->tryConsume(',') !== null && !$stream->is(Token::End));

		return $node;
	}

	private function captureParameter(Tag $tag, ?string $type, string $variable, ?string $default): void
	{
		$this->captured[] = new CapturedDeclaration(
			CapturedDeclaration::PARAMETER,
			$type,
			$variable,
			$default,
			$tag->position->line,
		);
	}

	private function defaultText(Tag $tag, int $base, ExpressionNode $default): string
	{
		$next = self::positionOf($tag->parser->stream->peek());
		if ($default->position === null || $next === null) {
			return $default->print(new PrintContext());
		}

		return trim(
			substr(
				$tag->parser->text,
				$default->position->offset - $base,
				$next->offset - $default->position->offset,
			),
		);
	}

	// TagParser::$text is the raw argument text, leading whitespace included, and its tokens keep
	// template offsets: the text's first offset is the first token's offset minus that whitespace.
	private function textBase(Tag $tag): int
	{
		$text = $tag->parser->text;
		$first = self::positionOf($tag->parser->stream->peek());
		if ($first === null) {
			return 0;
		}

		return $first->offset - (strlen($text) - strlen(ltrim($text)));
	}

	private function slice(Tag $tag, int $base, ?Token $from, ?Token $to): string
	{
		$fromPosition = self::positionOf($from);
		$toPosition = self::positionOf($to);
		if ($fromPosition === null || $toPosition === null) {
			return '';
		}

		return trim(
			substr(
				$tag->parser->text,
				$fromPosition->offset - $base,
				$toPosition->offset - $fromPosition->offset,
			),
		);
	}

	// Latte 3.0's TokenStream::peek() is nullable past the end, 3.1's is not.
	private static function positionOf(?Token $token): ?Position
	{
		return $token === null ? null : $token->position;
	}

}
