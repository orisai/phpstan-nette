<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Rule;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingConflict;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingJudge;
use OriPhpstan\Nette\Latte\Bridge\Pairing\PairingOpaque;
use OriPhpstan\Nette\Latte\Bridge\PhpFactsCache;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderWalk;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use function array_values;
use function implode;
use function in_array;
use function sprintf;
use function substr_compare;

/**
 * @implements Rule<InClassNode>
 */
final class LattePairingRule implements Rule
{

	public const CONFLICT_IDENTIFIER = 'orisaiNette.latte.pairingConflict';

	public const OPAQUE_IDENTIFIER = 'orisaiNette.latte.pairingOpaque';

	private PhpRenderWalk $renderWalk;

	private PhpFactsCache $renderFactsCache;

	private PairingJudge $judge;

	private bool $enabled;

	public function __construct(
		ConfigurationGuard $guard,
		PhpRenderWalk $renderWalk,
		PhpFactsCache $renderFactsCache,
		PairingJudge $judge
	)
	{
		$guard->validate();
		$this->renderWalk = $renderWalk;
		$this->renderFactsCache = $renderFactsCache;
		$this->judge = $judge;
		$this->enabled = $guard->isLatteEnabled();
	}

	public function getNodeType(): string
	{
		return InClassNode::class;
	}

	/**
	 * @param InClassNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		// Before any other work: a flag-off consumer must pay no walk or cache cost at all.
		if (!$this->enabled) {
			return [];
		}

		// Compiled LatteTpl_* classes report the .latte file itself as their source - raw Latte
		// source is unparseable for the walk, and pairing describes hand-written render-side
		// classes, never compiled template output.
		if (substr_compare($scope->getFile(), '.latte', -6) === 0) {
			return [];
		}

		$classReflection = $node->getClassReflection();
		if (!$classReflection->isClass() || $classReflection->isAnonymous()) {
			return [];
		}

		$walk = $this->renderWalk;
		$className = $classReflection->getName();
		$facts = $this->renderFactsCache->remember(
			$className,
			static fn (): PhpRenderFacts => $walk->factsFor($className),
		);

		// Shared qualification gate: judge() throws on non-qualifying facts by ratified contract.
		if (!Qualification::qualifies($facts)) {
			return [];
		}

		$verdict = $this->judge->judge($facts);

		$errors = [];
		foreach (self::mergeConflicts($verdict->getConflicts()) as $conflict) {
			$errors[] = RuleErrorBuilder::message(sprintf(
				'Template class pairing conflict: %s (%s) vs %s (%s).',
				$conflict['declaredClass'],
				implode(', ', $conflict['declaredChannels']),
				$conflict['runtimeClass'],
				implode(', ', $conflict['runtimeChannels']),
			))
				->identifier(self::CONFLICT_IDENTIFIER)
				->line($conflict['line'] ?? $node->getStartLine())
				->build();
		}

		foreach ($verdict->getOpaques() as $opaque) {
			$errors[] = RuleErrorBuilder::message(
				sprintf('Template class pairing is opaque in channel %s.', $opaque->getChannel()),
			)
				->identifier(self::OPAQUE_IDENTIFIER)
				->line($opaque->getLine() === PairingOpaque::NO_SITE_LINE ? $node->getStartLine() : $opaque->getLine())
				->build();
		}

		return $errors;
	}

	// The judge deliberately emits one conflict per declaration candidate (phpdoc and
	// genericBinding carrying the same declared class are two conflicts); reporting merges them by
	// (kind, declaredClass, runtimeClass) while keeping every channel named. Public static: the
	// dumpLattePairing renderer (LatteDebugDumpRule) shares this exact dedup.

	/**
	 * @param list<PairingConflict> $conflicts
	 * @return list<array{declaredClass: string, runtimeClass: string, declaredChannels: list<string>, runtimeChannels: list<string>, line: int|null}>
	 */
	public static function mergeConflicts(array $conflicts): array
	{
		$merged = [];
		foreach ($conflicts as $conflict) {
			$key = $conflict->getKind() . "\x00" . $conflict->getDeclaredClass() . "\x00" . $conflict->getRuntimeClass();
			if (!isset($merged[$key])) {
				$lines = $conflict->getLines();
				$merged[$key] = [
					'declaredClass' => $conflict->getDeclaredClass(),
					'runtimeClass' => $conflict->getRuntimeClass(),
					'declaredChannels' => [],
					'runtimeChannels' => [],
					'line' => $lines[0] ?? null,
				];
			}

			[$declaredChannel, $runtimeChannel] = $conflict->getChannels();
			if (!in_array($declaredChannel, $merged[$key]['declaredChannels'], true)) {
				$merged[$key]['declaredChannels'][] = $declaredChannel;
			}

			if (!in_array($runtimeChannel, $merged[$key]['runtimeChannels'], true)) {
				$merged[$key]['runtimeChannels'][] = $runtimeChannel;
			}
		}

		return array_values($merged);
	}

}
