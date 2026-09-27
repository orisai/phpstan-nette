<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Component\Attachment\AttachmentState;
use OriPhpstan\Nette\Forms\Analyzer\CompositionState;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummary;
use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Forms\Shape\ReplicatorShape;
use OriPhpstan\Nette\Forms\Shape\UnknownReason;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_diff;
use function array_keys;
use function array_values;
use function sort;

/**
 * The attachment rides in CompositionState and every derivation of that state has to carry it
 * across. Nothing reads it yet, so a derivation that quietly rebuilt the state without it would
 * cost nothing today and would silently halve the mechanism the moment a consumer arrives - the one
 * failure mode a slice that changes no answer can hide. Hence both halves here: each derivation is
 * checked to preserve a seeded attachment, and the set of derivations is checked to be exactly the
 * set this file knows about, so a new one cannot join the class unnoticed.
 */
final class CompositionStateAttachmentTest extends BaseTestCase
{

	/**
	 * The derivations that must leave the attachment exactly as they found it. withAttachment() is
	 * absent on purpose: replacing the attachment is what it is for.
	 */
	private const PreservingDerivations = [
		'withUnknownReason',
		'withContainerShape',
		'absorbCalleeContribution',
		'openContainerShape',
		'withReplicatorShape',
		'withAllSlotsOmitted',
		'markSlotUnresolvedOrigin',
		'applySequential',
		'asLoopBody',
	];

	public function testTheInitialStateClaimsNothing(): void
	{
		self::assertSame(
			Certainty::UNKNOWN,
			CompositionState::initial()->getAttachment()->attachmentOf('form'),
		);
	}

	public function testWithAttachmentReplacesTheCarriedState(): void
	{
		$state = CompositionState::initial()->withAttachment($this->seed());

		self::assertSame(Certainty::NEVER, $state->getAttachment()->attachmentOf('control'));
		self::assertSame(Certainty::HAPPENS, $state->getAttachment()->attachmentOf('form'));
	}

	/**
	 * @param callable(CompositionState): CompositionState $derive
	 *
	 * @dataProvider providePreservingDerivations
	 */
	public function testADerivationCarriesTheAttachmentAcross(string $name, callable $derive): void
	{
		$derived = $derive(CompositionState::initial()->withAttachment($this->seed()));

		self::assertSame(
			Certainty::NEVER,
			$derived->getAttachment()->attachmentOf('control'),
			$name . '() dropped the carried attachment',
		);
		self::assertSame(Certainty::HAPPENS, $derived->getAttachment()->attachmentOf('form'));
	}

	/**
	 * @return iterable<string, array{string, callable(CompositionState): CompositionState}>
	 */
	public function providePreservingDerivations(): iterable
	{
		foreach ($this->derivations() as $name => $derive) {
			yield $name => [$name, $derive];
		}
	}

	/**
	 * A branch join meets the attachment over the reaching paths, so two arms that agree keep the
	 * answer and the fold across return points does the same.
	 */
	public function testTheJoinsCarryAnAnswerEveryPathAgreesOn(): void
	{
		$seeded = CompositionState::initial()->withAttachment($this->seed());

		$joined = CompositionState::joinBranches(
			$seeded,
			[['state' => $seeded, 'terminated' => false]],
			true,
		);
		self::assertSame(Certainty::NEVER, $joined->getAttachment()->attachmentOf('control'));

		$folded = CompositionState::joinReturnPoints([$seeded, $seeded]);
		self::assertSame(Certainty::NEVER, $folded->getAttachment()->attachmentOf('control'));
	}

	/**
	 * And the direction that matters: an arm that attaches what the pre-state had detached leaves
	 * the join with neither answer. This is the shape of the unsoundness 7c2fced9c fixed for the
	 * carried presences, met at the same join.
	 */
	public function testABranchJoinMeetsDisagreeingArmsRatherThanOverwriting(): void
	{
		$pre = CompositionState::initial()->withAttachment($this->seed());
		$arm = $pre->withAttachment(
			$this->seed()->withAttachment('control', Certainty::HAPPENS),
		);

		$joined = CompositionState::joinBranches($pre, [['state' => $arm, 'terminated' => false]], true);

		self::assertSame(Certainty::MAYBE, $joined->getAttachment()->attachmentOf('control'));
	}

	/**
	 * The guard that keeps the list above honest. A derivation added to CompositionState later gets
	 * the same treatment or fails here, which is the only thing standing between the carried state
	 * and a silent hole while no consumer reads it.
	 */
	public function testEveryDerivationOfTheStateIsCovered(): void
	{
		$declared = self::PreservingDerivations;
		$declared[] = 'withAttachment';
		sort($declared);

		$found = [];
		foreach ((new ReflectionClass(CompositionState::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$returnType = $method->getReturnType();
			if (
				$method->isStatic()
				|| !$returnType instanceof ReflectionNamedType
				|| ($returnType->getName() !== 'self' && $returnType->getName() !== CompositionState::class)
			) {
				continue;
			}

			$found[] = $method->getName();
		}

		sort($found);

		self::assertSame(
			[],
			array_values(array_diff($found, $declared)),
			'these CompositionState derivations are not checked to carry the attachment across',
		);
		self::assertSame(
			[],
			array_values(array_diff($declared, $found)),
			'these are checked as derivations but CompositionState no longer declares them',
		);
	}

	public function testTheDerivationTableCoversWhatItDeclares(): void
	{
		self::assertSame(self::PreservingDerivations, array_keys($this->derivations()));
	}

	private function seed(): AttachmentState
	{
		return AttachmentState::initial()
			->withAttachment('control', Certainty::NEVER)
			->withAttachment('form', Certainty::HAPPENS);
	}

	/**
	 * @return array<string, callable(CompositionState): CompositionState>
	 */
	private function derivations(): array
	{
		return [
			'withUnknownReason' => static fn (CompositionState $state): CompositionState => $state->withUnknownReason(
				UnknownReason::CONTAINER_REFERENCE,
			),
			'withContainerShape' => static fn (CompositionState $state): CompositionState => $state->withContainerShape(
				'child',
				FormShape::empty(),
			),
			'absorbCalleeContribution' => static fn (CompositionState $state): CompositionState => $state->absorbCalleeContribution(
				FormShape::empty(),
			),
			'openContainerShape' => static fn (CompositionState $state): CompositionState => $state
				->withContainerShape('child', FormShape::empty())
				->openContainerShape('child'),
			'withReplicatorShape' => static fn (CompositionState $state): CompositionState => $state->withReplicatorShape(
				'rows',
				new ReplicatorShape(FormShape::empty(), FormShape::empty()),
			),
			'withAllSlotsOmitted' => static fn (CompositionState $state): CompositionState => $state->withAllSlotsOmitted(),
			'markSlotUnresolvedOrigin' => static fn (CompositionState $state): CompositionState => $state->markSlotUnresolvedOrigin(
				'child',
			),
			'applySequential' => static fn (CompositionState $state): CompositionState => $state->applySequential(
				new NodeContributionSummary('node', NodeContributionSummary::OP_ADD, 'child', false, null, []),
			),
			'asLoopBody' => static fn (CompositionState $state): CompositionState => $state->asLoopBody(
				CompositionState::initial()->withAttachment(
					AttachmentState::initial()
						->withAttachment('control', Certainty::NEVER)
						->withAttachment('form', Certainty::HAPPENS),
				),
			),
		];
	}

}
