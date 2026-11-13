<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use function file_put_contents;
use function getenv;
use const FILE_APPEND;
use const LOCK_EX;

/**
 * @implements Rule<FileNode>
 */
final class AnalysedFileLogRule implements Rule
{

	public const ENV = 'ORISAI_NETTE_TEST_ANALYSED_FILE_LOG';

	public function getNodeType(): string
	{
		return FileNode::class;
	}

	/**
	 * @param FileNode $node
	 * @return list<never>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$log = getenv(self::ENV);
		if ($log !== false && $log !== '') {
			file_put_contents($log, $scope->getFile() . "\n", FILE_APPEND | LOCK_EX);
		}

		return [];
	}

}
