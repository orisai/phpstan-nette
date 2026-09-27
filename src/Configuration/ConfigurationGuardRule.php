<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Configuration;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<FileNode>
 */
final class ConfigurationGuardRule implements Rule
{

	public function __construct(ConfigurationGuard $guard)
	{
		$guard->validate();
	}

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		return [];
	}

}
