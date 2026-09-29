<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\EliminatorVisitor;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use function assert;

// Runs eliminators over generated PHP the way AnalysisPipeline does (names resolved, one traversal),
// so a committed Latte 2/3.0/3.1 shape is exercised on any installed Latte.
final class EliminatorRun
{

	public static function family(string $latteLine): ShapeFamily
	{
		return new ShapeFamily(
			$latteLine,
			$latteLine === ShapeFamily::LATTE_2 ? ShapeFamily::FORMS_MACROS : ShapeFamily::FORMS_ITEM,
		);
	}

	// A template class whose main() holds $body, as the generators indent it (tabs, LR alias in scope).
	public static function template(string $body): string
	{
		return "<?php\n\nuse Latte\\Runtime as LR;\n\nfinal class T extends Latte\\Runtime\\Template\n{\n"
			. "\tpublic function main(): void\n\t{\n" . $body . "\n\t}\n}\n";
	}

	// The same class as the pretty printer emits it after the traversal.
	public static function printed(string $body): string
	{
		return "<?php\n\nuse Latte\\Runtime as LR;\nfinal class T extends \\Latte\\Runtime\\Template\n{\n"
			. "    public function main(): void\n    {\n" . $body . "\n    }\n}";
	}

	public static function apply(string $php, EliminatorVisitor ...$eliminators): string
	{
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');
		assert($parser instanceof Parser);

		$traverser = new NodeTraverser();
		foreach ($eliminators as $eliminator) {
			$traverser->addVisitor($eliminator);
		}

		/** @var array<Stmt> $stmts */
		$stmts = $traverser->traverse($parser->parseString($php));

		return (new Standard())->prettyPrintFile($stmts);
	}

}
