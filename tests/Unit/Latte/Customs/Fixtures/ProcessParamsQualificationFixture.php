<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures;

use Latte\Attributes\TemplateFilter;

// Every qualification variant ProcessParamsQualificationParityTest probes against the REAL
// vendor Engine::processParams(): visibility, static-ness, tag-on-both-roles, and the substring
// (not exact-tag) match vendor's bare strpos() performs.
final class ProcessParamsQualificationFixture
{

	/**
	 * @filter
	 */
	public function docFilter(string $s): string
	{
		return $s;
	}

	/**
	 * @function
	 */
	public function docFunction(string $s): string
	{
		return $s;
	}

	/**
	 * @filter
	 */
	public static function docStaticFilter(string $s): string
	{
		return $s;
	}

	/**
	 * @filter
	 */
	private function docPrivateFilter(string $s): string
	{
		return $s;
	}

	public function untaggedPublic(string $s): string
	{
		return $s;
	}

	/**
	 * @filterish this is NOT the exact tag - vendor's bare strpos() still matches it as a substring
	 */
	public function substringBug(string $s): string
	{
		return $s;
	}

	/**
	 * @filter
	 * @function
	 */
	public function bothTags(string $s): string
	{
		return $s;
	}

	// #[...] parses as a single-line COMMENT on PHP < 8.0 (the '#' comment-start character predates
	// attribute syntax) - on THIS project's own PHP 7.4 analysis runtime the line below has zero
	// effect, same as if it were absent; no docblock tag means this method never qualifies here,
	// which is exactly what ProcessParamsQualificationParityTest pins against the real engine.

	#[TemplateFilter]
	public function attrOnlyFilter(string $s): string
	{
		return $s;
	}

}
