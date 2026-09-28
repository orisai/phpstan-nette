<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

// Committed {templateType} fixture for TemplateTypeCustomsIntegrationTest: a real-app-style
// params class exposing one filter and one function via Latte's native processParams() docblock
// tags, both typed narrowly (int) so a wrong-typed call site reports the underlying method's own
// argument.type error, not orisaiNette.latte.unknownFilter/"function not found".
final class FixtureTemplateTypeCustoms
{

	/**
	 * @filter
	 */
	public function myTplFilter(int $a): string
	{
		return (string) $a;
	}

	/**
	 * @function
	 */
	public function myTplFunction(int $a): string
	{
		return (string) $a;
	}

}
