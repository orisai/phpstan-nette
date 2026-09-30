<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * @implements Rule<FileNode>
 */
final class ParameterProbeRule implements Rule
{

	public const IDENTIFIER = 'probe.parameter';

	private string $label;

	/** @var mixed */
	private $value;

	/**
	 * @param mixed $value
	 */
	public function __construct(string $label, $value)
	{
		$this->label = $label;
		$this->value = $value;
	}

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		return [
			RuleErrorBuilder::message(
				$this->label . '=' . json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			)
				->identifier(self::IDENTIFIER)
				->build(),
		];
	}

}
