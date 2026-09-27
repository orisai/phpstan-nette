<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Analyzer\CalleeShapeResolver;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\ControlValueResolution;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use OriPhpstan\Nette\Forms\Graph\TaggedNode;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use PhpParser\Node;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;
use function array_keys;
use function assert;
use function dirname;
use function sys_get_temp_dir;
use function uniqid;

final class SummaryFactoryTest extends TypeInferenceTestCase
{

	/** @return list<string> */
	public static function getAdditionalConfigFiles(): array
	{
		return [dirname(__DIR__, 2) . '/Fixtures/Forms/phpstan-test.neon'];
	}

	private function catalog(): NetteEffectiveControlValueTypeResolver
	{
		$parser = self::getContainer()->getService('currentPhpVersionRichParser');
		assert($parser instanceof Parser);

		return new NetteEffectiveControlValueTypeResolver(
			new ControlAnnotationValueTypeReader(
				self::createReflectionProvider(),
				self::getContainer()->getByType(TypeStringResolver::class),
			),
			new CalleeShapeResolver(
				$parser,
				self::createReflectionProvider(),
				new FormShapeCache(sys_get_temp_dir() . '/forms-summary-factory-test-' . uniqid('', true)),
			),
		);
	}

	/** @return array<string, NodeContributionSummary> */
	private function summarize(): array
	{
		$factory = new NodeContributionSummaryFactory($this->catalog());
		$summaries = [];

		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/Summary.php',
			static function (Node $node, Scope $scope) use ($factory, &$summaries): void {
				if (!$node instanceof Expression && !$node instanceof Unset_) {
					return;
				}

				$tagged = null;
				foreach ((new NodeFinder())->find(
					$node,
					static fn (Node $n): bool => $n->getAttribute(TaggedNode::ATTRIBUTE) instanceof TaggedNode,
				) as $candidate) {
					$tagged = $candidate;

					break;
				}

				if ($tagged === null) {
					return;
				}

				$meta = $tagged->getAttribute(TaggedNode::ATTRIBUTE);
				$summary = $factory->fromTaggedNode($node, $tagged, $meta, $scope);

				$key = $meta->getOperationKind();
				if ($key === '$offsetSet') {
					$key = 'offsetset';
				} elseif ($key === '$offsetUnset') {
					$key = 'unset';
				} elseif ($key === 'removeComponent') {
					$key = 'remove';
				} elseif ($key === 'addComponent') {
					$key = 'addcomponent';
				} elseif ($key === 'addSomethingUnknown') {
					$key = 'unknown';
				} elseif ($key === 'addDate') {
					$key = $summary->getName() === 'dts' ? 'setfmt_ts' : 'setfmt_obj';
				} else {
					if ($summary->hasDynamicName()) {
						$key = 'dyn';
					} elseif ($summary->getName() === 'lit') {
						$key = 'lit';
					} elseif ($summary->getName() === 'nm') {
						$key = 'self_name';
					} elseif ($summary->getName() === 'pre_x') {
						$key = 'concat';
					} else {
						$key = 'nullable';
					}
				}

				$summaries[$key] = $summary;
			},
		);

		return $summaries;
	}

	private function describe(NodeContributionSummary $s): string
	{
		$valueType = $this->resolution($s)->getValueType();
		self::assertNotNull($valueType);

		return $valueType->describe(VerbosityLevel::precise());
	}

	private function resolution(NodeContributionSummary $s): ControlValueResolution
	{
		$resolution = $s->getResolution();
		self::assertNotNull($resolution);

		return $resolution;
	}

	public function testSummaries(): void
	{
		$s = $this->summarize();

		self::assertSame(
			['lit', 'self_name', 'concat', 'dyn', 'nullable', 'setfmt_ts', 'setfmt_obj', 'unknown', 'remove', 'unset', 'offsetset', 'addcomponent'],
			array_keys($s),
		);

		self::assertSame(NodeContributionSummary::OP_ADD, $s['lit']->getOp());
		self::assertSame('lit', $s['lit']->getName());
		self::assertFalse($s['lit']->hasDynamicName());
		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['lit'])->getKind());
		self::assertSame('string', $this->describe($s['lit']));
		self::assertSame([], $s['lit']->getNodeUnknownReasons());

		self::assertSame('nm', $s['self_name']->getName());
		self::assertFalse($s['self_name']->hasDynamicName());

		self::assertSame('pre_x', $s['concat']->getName());
		self::assertFalse($s['concat']->hasDynamicName());

		self::assertNull($s['dyn']->getName());
		self::assertTrue($s['dyn']->hasDynamicName());
		self::assertSame([UnknownReason::DYNAMIC_NAME], $s['dyn']->getNodeUnknownReasons());
		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['dyn'])->getKind());
		self::assertSame('string', $this->describe($s['dyn']));

		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['nullable'])->getKind());
		self::assertSame('non-empty-string|null', $this->describe($s['nullable']));

		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['setfmt_ts'])->getKind());
		self::assertSame('int|null', $this->describe($s['setfmt_ts']));

		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['setfmt_obj'])->getKind());
		self::assertSame('DateTimeImmutable|null', $this->describe($s['setfmt_obj']));

		self::assertSame(NodeContributionSummary::OP_ADD, $s['unknown']->getOp());
		self::assertSame('uk', $s['unknown']->getName());
		self::assertSame(ControlValueResolution::KIND_UNKNOWN_TYPE, $this->resolution($s['unknown'])->getKind());
		self::assertSame([UnknownReason::EXTENSION_METHOD], $this->resolution($s['unknown'])->getUnknownReasons());
		self::assertSame([], $s['unknown']->getNodeUnknownReasons());

		self::assertSame(NodeContributionSummary::OP_REMOVE, $s['remove']->getOp());
		self::assertSame('lit', $s['remove']->getName());
		self::assertNull($s['remove']->getResolution());

		self::assertSame(NodeContributionSummary::OP_REMOVE, $s['unset']->getOp());
		self::assertSame('nb', $s['unset']->getName());
		self::assertNull($s['unset']->getResolution());

		self::assertSame(NodeContributionSummary::OP_ADD, $s['offsetset']->getOp());
		self::assertSame('os', $s['offsetset']->getName());
		self::assertFalse($s['offsetset']->hasDynamicName());
		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['offsetset'])->getKind());
		self::assertSame('string', $this->describe($s['offsetset']));

		self::assertSame(NodeContributionSummary::OP_ADD, $s['addcomponent']->getOp());
		self::assertSame('ac', $s['addcomponent']->getName());
		self::assertSame(ControlValueResolution::KIND_VALUE, $this->resolution($s['addcomponent'])->getKind());
		self::assertSame('bool', $this->describe($s['addcomponent']));
	}

	public function testResolveControlTypeMappings(): void
	{
		$resolver = $this->catalog();
		$asserted = false;

		self::processFile(
			dirname(__DIR__, 2) . '/Doubles/Forms/Summary.php',
			static function (Node $node, Scope $scope) use ($resolver, &$asserted): void {
				if ($asserted || !$node instanceof Expression) {
					return;
				}

				$asserted = true;

				$containerResolution = $resolver->resolveControlType(
					new ObjectType('Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm'),
				);
				self::assertSame(ControlValueResolution::KIND_CONTAINER, $containerResolution->getKind());
				self::assertNull($containerResolution->getValueType());

				$textInputResolution = $resolver->resolveControlType(
					new ObjectType('Nette\\Forms\\Controls\\TextInput'),
				);
				self::assertSame(ControlValueResolution::KIND_VALUE, $textInputResolution->getKind());
				self::assertNotNull($textInputResolution->getValueType());
				self::assertSame('string', $textInputResolution->getValueType()->describe(VerbosityLevel::precise()));

				$selectResolution = $resolver->resolveControlType(
					new ObjectType('Nette\\Forms\\Controls\\SelectBox'),
				);
				self::assertSame(ControlValueResolution::KIND_VALUE, $selectResolution->getKind());
				self::assertNotNull($selectResolution->getValueType());
				self::assertSame(
					'int|string|null',
					$selectResolution->getValueType()->describe(VerbosityLevel::precise()),
				);

				$buttonResolution = $resolver->resolveControlType(
					new ObjectType('Nette\\Forms\\Controls\\SubmitButton'),
				);
				self::assertSame(ControlValueResolution::KIND_OMITTED, $buttonResolution->getKind());

				$noneResolution = $resolver->resolveControlType(new ObjectType('stdClass'));
				self::assertSame(ControlValueResolution::KIND_UNKNOWN_TYPE, $noneResolution->getKind());
				self::assertSame([], $noneResolution->getUnknownReasons());
			},
		);

		self::assertTrue($asserted);
	}

}
