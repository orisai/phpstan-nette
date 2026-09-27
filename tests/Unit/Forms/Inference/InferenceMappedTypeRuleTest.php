<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Component\ContainerModel;
use OriPhpstan\Nette\Forms\Rule\FormValuesMappedTypeRule;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function array_merge;

/**
 * @extends InferenceRuleTest<FormValuesMappedTypeRule>
 */
final class InferenceMappedTypeRuleTest extends InferenceRuleTest
{

	/** @return FormValuesMappedTypeRule */
	protected function getRule(): Rule
	{
		return new FormValuesMappedTypeRule(
			TestGuard::of(self::getContainer()),
			true,
			self::createReflectionProvider(),
			self::getContainer()->getByType(ContainerModel::class),
		);
	}

	/**
	 * A field with no matching property is written as a dynamic property — allowed (so not
	 * reported) below PHP 8.2, deprecated/forbidden from 8.2 on. These expectations therefore
	 * hold only at the analysed phpVersion >= 8.2.
	 *
	 * @param list<array{string, int}> $errors
	 * @return list<array{string, int}>
	 */
	private function whenDynamicPropertiesForbidden(array $errors): array
	{
		return self::getContainer()->getByType(PhpVersion::class)->getVersionId() >= 80200 ? $errors : [];
	}

	public function testMappedTypeWrite(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/MappedType.php'],
			array_merge(
				$this->whenDynamicPropertiesForbidden([
					[
						'Form field age has no matching property on mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoMissingProperty.',
						27,
					],
				]),
				[
					[
						'Form field age maps to non-public property Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoNonPublic::$age, which Nette cannot write to.',
						32,
					],
					[
						'Form field age has value type int|null, which is not accepted by property age of mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoBadType (string).',
						37,
					],
					[
						'Form values cannot be mapped to Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoAbstract because it is not instantiable.',
						42,
					],
					[
						'Form field age has no matching constructor parameter on mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoConstructorMissingParam (it would be passed as an unknown named argument).',
						52,
					],
					[
						'Mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoConstructorMissingParam requires constructor parameter $missing, but the form has no matching field.',
						52,
					],
					[
						'Mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoUninitializedRequired has typed property $missing with no default, but the form has no matching field, so it is left uninitialized.',
						58,
					],
				],
			),
		);
	}

	public function testNestedMappedTypeWrite(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/MappedTypeNested.php'],
			array_merge(
				$this->whenDynamicPropertiesForbidden([
					[
						'Form field city has no matching property on mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\NestedAddressBadField.',
						29,
					],
				]),
				[
					[
						'Form container address is a composite value, which cannot be written into scalar property address of mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\NestedOuterScalar (string).',
						34,
					],
				],
			),
		);
	}

	public function testReplicatorMappedTypeWrite(): void
	{
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/MappedTypeReplicator.php'],
			[
				[
					'Form replicator items is a composite value, which cannot be written into scalar property items of mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\NestedReplicatorScalar (string).',
					29,
				],
			],
		);
	}

	public function testWriteCheckUsesPhpdocType(): void
	{
		// The DTO property is `string` natively but `/** @var non-empty-string */`.
		// A plain `string` field (possibly empty) does not fit that contract, so the
		// rule reports it against the phpdoc-narrowed type, not just the native one.
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/MappedTypePhpdoc.php'],
			[
				[
					'Form field name has value type string, which is not accepted by property name of mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MappedDtoPhpdocNarrow (non-empty-string).',
					22,
				],
			],
		);
	}

	public function testReplicatorItemMappedType(): void
	{
		// Each replica self-maps via $row->setMappedType(): the rule recurses into
		// the item DTO. rowsGood items fit RowItemGood; rowsBad items do not fit
		// RowItemBad (no `label` property, and a required `count` left unmapped).
		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/MappedTypeReplicatorItem.php'],
			array_merge(
				$this->whenDynamicPropertiesForbidden([
					[
						'Form field label has no matching property on mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\RowItemBad.',
						29,
					],
				]),
				[
					[
						'Mapped type Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\RowItemBad has typed property $count with no default, but the form has no matching field, so it is left uninitialized.',
						29,
					],
				],
			),
		);
	}

}
