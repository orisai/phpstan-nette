<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Attributes\TemplateFilter;
use Latte\Attributes\TemplateFunction;
use Latte\Runtime\FilterInfo;

// #[...] parses as a comment on this project's PHP 7.4 runtime (see
// ProcessParamsQualificationFixture's own note) - these attributes are inert bytes here, only
// ever meaningful to TemplateTypeCustomsTest's PhpVersion(80000) configuration, which detects
// them via AST re-parsing rather than native ReflectionMethod::getAttributes() (a PHP 8-only API
// this interpreter does not have, regardless of the configured PhpVersion).
final class TemplateTypeCustomsAttributeFixture
{

	#[TemplateFilter]
	public function attrFilter(string $s): string
	{
		return $s;
	}

	#[TemplateFunction]
	public function attrFunction(string $s): string
	{
		return $s;
	}

	/**
	 * @filter
	 */
	public function contentAwareFilter(FilterInfo $info, string $s): string
	{
		return $s;
	}

}
