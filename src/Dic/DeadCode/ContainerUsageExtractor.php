<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\DeadCode;

use LogicException;
use Nette\IOException;
use Nette\Utils\FileSystem;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use function sprintf;

final class ContainerUsageExtractor
{

	/**
	 * @return array<string, array<string, true>>
	 */
	public function extractFromFile(string $containerFile): array
	{
		try {
			$code = FileSystem::read($containerFile);
		} catch (IOException $e) {
			throw new LogicException(
				sprintf('Cannot read compiled container file "%s": %s', $containerFile, $e->getMessage()),
				0,
				$e,
			);
		}

		$parser = (new ParserFactory())->createForHostVersion();

		try {
			$statements = $parser->parse($code);
		} catch (Error $e) {
			throw new LogicException(
				sprintf('Cannot parse compiled container file "%s": %s', $containerFile, $e->getMessage()),
				0,
				$e,
			);
		}

		if ($statements === null) {
			throw new LogicException(sprintf('Cannot parse compiled container file "%s".', $containerFile));
		}

		$visitor = new ContainerUsageVisitor();
		$traverser = new NodeTraverser();
		$traverser->addVisitor($visitor);
		$traverser->traverse($statements);

		return $visitor->getUsages();
	}

}
