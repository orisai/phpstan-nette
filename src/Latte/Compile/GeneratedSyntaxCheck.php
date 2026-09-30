<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use OriPhpstan\Nette\Latte\Postprocess\LineMapper;
use PhpParser\Error;
use PhpParser\Parser;
use PhpParser\ParserFactory;

// Latte 2 prints the template's PHP expressions verbatim, and so does Latte 3's {php} (RawPhpExtension):
// a malformed one only fails once PHP loads the compiled class - Latte 3.1's Engine reports that
// ParseError as "Error in template". Left to PHPStan, the unparsable file would suppress every other
// finding of the run.
final class GeneratedSyntaxCheck
{

	private ?Parser $parser = null;

	public function check(string $phpSource, string $lineMarkerPattern): ?Diagnostic
	{
		$this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();

		try {
			$this->parser->parse($phpSource);
		} catch (Error $e) {
			$map = (new LineMapper($lineMarkerPattern))->buildMap($phpSource);

			return new Diagnostic(
				'orisaiNette.latte.parseError',
				'Error in template: ' . $e->getRawMessage(),
				$map[$e->getStartLine()] ?? 1,
			);
		}

		return null;
	}

}
