<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Customs;

use OriPhpstan\Nette\Latte\Customs\TemplateTypeCustoms;
use PHPStan\Parser\Parser;
use PHPStan\Php\PhpVersion;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestAdapter;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\ProcessParamsQualificationFixture;
use Tests\OriPhpstan\Nette\Unit\Latte\Customs\Fixtures\TemplateTypeCustomsAttributeFixture;
use function array_keys;

final class TemplateTypeCustomsTest extends BaseTestCase
{

	public function testDocblockFilterResolvesAtPhp70400(): void
	{
		$entries = $this->customs(70400)->filtersFor(ProcessParamsQualificationFixture::class);

		self::assertSame(
			[ProcessParamsQualificationFixture::class, 'docFilter', false, false],
			$entries['docfilter'] ?? null,
		);
	}

	public function testPublicStaticFilterIsFlaggedAsStatic(): void
	{
		$entries = $this->customs(70400)->filtersFor(ProcessParamsQualificationFixture::class);

		self::assertSame(
			[ProcessParamsQualificationFixture::class, 'docStaticFilter', false, true],
			$entries['docstaticfilter'] ?? null,
		);
	}

	public function testDocblockFilterAlsoResolvesAtPhp80000(): void
	{
		// Docblock forms are version-independent - only the attribute channel is gated.
		$entries = $this->customs(80000)->filtersFor(ProcessParamsQualificationFixture::class);

		self::assertArrayHasKey('docfilter', $entries);
	}

	public function testDocblockFunctionResolvesAtBothConfigs(): void
	{
		foreach ([70400, 80000] as $versionId) {
			$entries = $this->customs($versionId)->functionsFor(ProcessParamsQualificationFixture::class);
			self::assertSame(
				[ProcessParamsQualificationFixture::class, 'docFunction', false, false],
				$entries['docfunction'] ?? null,
				"function must resolve at PhpVersion $versionId",
			);
		}
	}

	public function testAttributeOnlyFilterIsInvisibleAtPhp70400(): void
	{
		$entries = $this->customs(70400)->filtersFor(TemplateTypeCustomsAttributeFixture::class);

		self::assertArrayNotHasKey('attrfilter', $entries);
	}

	public function testAttributeOnlyFilterIsHonouredAtPhp80000(): void
	{
		$entries = $this->customs(80000)->filtersFor(TemplateTypeCustomsAttributeFixture::class);

		self::assertSame(
			[TemplateTypeCustomsAttributeFixture::class, 'attrFilter', false, false],
			$entries['attrfilter'] ?? null,
		);
	}

	public function testAttributeOnlyFunctionIsInvisibleAtPhp70400ButHonouredAtPhp80000(): void
	{
		self::assertArrayNotHasKey(
			'attrfunction',
			$this->customs(70400)->functionsFor(TemplateTypeCustomsAttributeFixture::class),
		);
		self::assertArrayHasKey(
			'attrfunction',
			$this->customs(80000)->functionsFor(TemplateTypeCustomsAttributeFixture::class),
		);
	}

	public function testFilterInfoFirstParamIsDetectedAsContentAwareJustLikeAHarvestedFilter(): void
	{
		$entries = $this->customs(70400)->filtersFor(TemplateTypeCustomsAttributeFixture::class);

		self::assertSame(
			[TemplateTypeCustomsAttributeFixture::class, 'contentAwareFilter', true, false],
			$entries['contentawarefilter'] ?? null,
		);
	}

	public function testUnknownTemplateTypeClassDegradesToEmptyRatherThanCrashing(): void
	{
		$customs = $this->customs(70400);

		self::assertSame([], $customs->filtersFor('Definitely\Not\A\Real\Class'));
		self::assertSame([], $customs->functionsFor('Definitely\Not\A\Real\Class'));
	}

	public function testNullTemplateTypeClassDegradesToEmpty(): void
	{
		$customs = $this->customs(70400);

		self::assertSame([], $customs->filtersFor(null));
		self::assertSame([], $customs->functionsFor(null));
	}

	// Latte 3 requires PHP 8, so its attribute channel is never gated on the configured PhpVersion;
	// 3.0 still reads the deprecated docblock tags, 3.1 does not.
	public function testLatte30ReadsAttributesAndDocblockTags(): void
	{
		$customs = $this->customs(70400, '3.0.26.0');

		self::assertArrayHasKey('attrfilter', $customs->filtersFor(TemplateTypeCustomsAttributeFixture::class));
		self::assertArrayHasKey('attrfunction', $customs->functionsFor(TemplateTypeCustomsAttributeFixture::class));
		self::assertArrayHasKey('docfilter', $customs->filtersFor(ProcessParamsQualificationFixture::class));
		self::assertArrayHasKey('docfunction', $customs->functionsFor(ProcessParamsQualificationFixture::class));
	}

	public function testLatte31ReadsAttributesOnly(): void
	{
		$customs = $this->customs(70400, '3.1.6.0');

		self::assertSame(
			[TemplateTypeCustomsAttributeFixture::class, 'attrFilter', false, false],
			$customs->filtersFor(TemplateTypeCustomsAttributeFixture::class)['attrfilter'] ?? null,
		);
		self::assertArrayHasKey('attrfunction', $customs->functionsFor(TemplateTypeCustomsAttributeFixture::class));
		self::assertArrayNotHasKey(
			'contentawarefilter',
			$customs->filtersFor(TemplateTypeCustomsAttributeFixture::class),
		);
		self::assertSame(
			['attronlyfilter'],
			array_keys($customs->filtersFor(ProcessParamsQualificationFixture::class)),
		);
		self::assertSame([], $customs->functionsFor(ProcessParamsQualificationFixture::class));
	}

	private function customs(int $versionId, string $latteVersion = '2.11.7.0'): TemplateTypeCustoms
	{
		/** @var Parser $parser */
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');

		return new TemplateTypeCustoms(new PhpVersion($versionId), $parser, TestAdapter::factoryFor($latteVersion));
	}

}
