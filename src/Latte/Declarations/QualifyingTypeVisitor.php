<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Declarations;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use function in_array;
use function is_int;
use function spl_object_id;
use function strtolower;

final class QualifyingTypeVisitor extends AbstractNodeVisitor
{

	private ClassNameScope $scope;

	/** @var array<int, true> */
	private array $skipped = [];

	/** @var array<int, string> */
	private array $replacements = [];

	public function __construct(ClassNameScope $scope)
	{
		$this->scope = $scope;
	}

	/**
	 * @return array<int, string>
	 */
	public function getReplacements(): array
	{
		return $this->replacements;
	}

	public function enterNode(Node $node)
	{
		if ($node instanceof ArrayShapeItemNode || $node instanceof ObjectShapeItemNode) {
			if ($node->keyName instanceof IdentifierTypeNode) {
				$this->skipped[spl_object_id($node->keyName)] = true;
			}
		} elseif ($node instanceof GenericTypeNode && strtolower($node->type->name) === 'int') {
			foreach ($node->genericTypes as $argument) {
				if (
					$argument instanceof IdentifierTypeNode
					&& in_array(strtolower($argument->name), ['min', 'max'], true)
				) {
					$this->skipped[spl_object_id($argument)] = true;
				}
			}
		} elseif ($node instanceof IdentifierTypeNode && !isset($this->skipped[spl_object_id($node)])) {
			$this->replace($node, $this->scope->resolveTypeName($node->name), $node->name);
		} elseif ($node instanceof ConstFetchNode && $node->className !== '') {
			$this->replace($node, $this->scope->resolveClassName($node->className), $node->className);
		}

		return null;
	}

	private function replace(Node $node, string $resolved, string $original): void
	{
		$index = $node->getAttribute(Attribute::START_INDEX);
		if ($resolved !== $original && is_int($index)) {
			$this->replacements[$index] = $resolved;
		}
	}

}
