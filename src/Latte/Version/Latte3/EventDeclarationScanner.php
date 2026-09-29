<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Block;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\Scalar\NullNode;
use Latte\Compiler\Nodes\Php\SuperiorTypeNode;
use Latte\Compiler\PrintContext;
use Latte\Essential\Nodes\BlockNode;
use Latte\Essential\Nodes\CaptureNode;
use Latte\Essential\Nodes\DefineNode;
use Latte\Essential\Nodes\DoNode;
use Latte\Essential\Nodes\ForeachNode;
use Latte\Essential\Nodes\VarNode;
use Nette\Bridges\ApplicationLatte\Nodes\SnippetAreaNode;
use Nette\Bridges\ApplicationLatte\Nodes\SnippetNode;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Declarations\VarTypePlacement;
use OriPhpstan\Nette\Latte\Includes\BlockBodyTracker;
use function array_keys;
use function array_pop;
use function count;
use function end;
use function in_array;
use function is_string;
use function strpos;

// DeclarationScanner (Latte 2 tokens) over the event stream: the same head rule, the same body-depth
// bookkeeping and the same mid-file {varType} placement resolution, reading node data where Latte 2
// re-tokenised macro arguments.
final class EventDeclarationScanner
{

	private const HEAD_ALLOWED_TAGS = [
		'import',
		'extends',
		'layout',
		'contentType',
		'parameters',
		'varType',
		'varPrint',
		'templateType',
		'templatePrint',
	];

	private const IMPLICIT_PRINT_TAG = '=';

	public static function empty(): Declarations
	{
		return new Declarations(null, null, [], [], [], [], [], null, [], [], []);
	}

	/**
	 * @param list<TemplateEvent> $events
	 * @param list<CapturedDeclaration> $captured
	 */
	public function scan(array $events, array $captured, string $source): Declarations
	{
		$templateTypeClass = null;
		$templateTypeLine = null;
		$parameters = null;
		foreach ($captured as $declaration) {
			$kind = $declaration->getKind();
			$type = $declaration->getType();
			$variable = $declaration->getVariable();
			if ($kind === CapturedDeclaration::TEMPLATE_TYPE && $templateTypeClass === null && $type !== null) {
				$templateTypeClass = $type;
				$templateTypeLine = $declaration->getLine();
			} elseif ($kind === CapturedDeclaration::PARAMETER && $variable !== null) {
				$parameters[] = [$type, $variable, $declaration->getDefault(), $declaration->getLine()];
			}
		}

		$inHead = true;
		$headerVarTypes = [];
		$headerVarTypeLines = [];
		$midFileVarTypes = [];
		$typedVars = [];
		$typedDefaults = [];
		$defineParams = [];
		$defineParamDefaults = [];
		/** @var list<array{int, string, string, int, bool}> $placementCandidates */
		$placementCandidates = [];
		$blocks = new BlockBodyTracker();
		/** @var list<int> $ownMethodBodyDepths */
		$ownMethodBodyDepths = [];

		foreach ($events as $index => $event) {
			if ($inHead && !$this->keepsHead($event)) {
				$inHead = false;
			}

			if ($event->kind === TemplateEvent::CLOSE) {
				$blocks->closing($event->closesBody);
				while ($ownMethodBodyDepths !== [] && end($ownMethodBodyDepths) > $blocks->depth()) {
					array_pop($ownMethodBodyDepths);
				}

				continue;
			}

			if ($event->kind !== TemplateEvent::TAG) {
				continue;
			}

			$node = $event->node;
			$record = $event->record;

			switch ($event->name) {
				case 'varType':
					if (!$node instanceof VarTypeDeclarationNode) {
						break;
					}

					$name = $node->declaration->getVariable();
					$type = $node->declaration->getType();
					if ($name === null || $type === null) {
						break;
					}

					if ($inHead) {
						$headerVarTypes[$name] = $type;
						$headerVarTypeLines[$name] = $event->line;
					} else {
						$midFileVarTypes[] = [$name, $type, $event->line];
						if (!$blocks->isAtOwnTopLevel()) {
							$placementCandidates[] = [$index, $name, $type, $event->line, $ownMethodBodyDepths !== []];
						}
					}

					break;
				case 'var':
				case 'default':
					if (!$node instanceof VarDeclarationNode) {
						break;
					}

					foreach ($node->assignments as $i => $assignment) {
						$type = $node->types[$i] ?? null;
						if ($type === null) {
							continue;
						}

						foreach (NodeReader::variableNames($assignment->var) as $name) {
							if ($node->default) {
								$typedDefaults[] = [$name, $type, $event->line];
							} else {
								$typedVars[] = [$name, $type, $event->line];
							}
						}
					}

					break;
				case 'block':
				case 'define':
					$block = $node instanceof BlockNode || $node instanceof DefineNode ? $node->block : null;
					$defineName = '';
					if ($block !== null && $record !== null) {
						$defineName = SourceText::blockName($block, $record, $source);
						if ($defineName !== '') {
							[$defineParams[$defineName], $defineParamDefaults[$defineName]] = $this->parametersOf(
								$block,
							);
						}
					}

					if ($event->opensBody) {
						$blocks->enterBlockOrDefine($defineName);
						// An ANONYMOUS {block} is compiled inline into main(); a named one - dynamic
						// included - always gets its own method.
						if ($defineName !== '') {
							$ownMethodBodyDepths[] = $blocks->depth() + 1;
						}
					}

					break;
				case 'snippet':
				case 'snippetArea':
					// Its body is its own compiled method exactly like a {block}'s, except a
					// DYNAMICALLY named {snippet}, which is emitted inline into main().
					if (!$event->opensBody || $record === null) {
						break;
					}

					$block = $node instanceof SnippetNode || $node instanceof SnippetAreaNode ? $node->block : null;
					$snippetName = $block === null ? '' : SourceText::blockName($block, $record, $source);
					$dynamic = $event->name === 'snippet'
						&& (strpos($snippetName, '$') !== false || strpos($snippetName, ' ') !== false);
					if ($snippetName !== '' && !$dynamic) {
						$ownMethodBodyDepths[] = $blocks->depth() + 1;
					}

					break;
			}

			$blocks->advance($event->opensBody);
		}

		// Every name a header {varType} or {parameters} entry declares is a real PHP parameter,
		// known in scope from the template's very first line onward.
		$headerNames = array_keys($headerVarTypes);
		foreach ($parameters ?? [] as [, $paramName]) {
			$headerNames[] = $paramName;
		}

		return new Declarations(
			$templateTypeClass,
			$templateTypeLine,
			$headerVarTypes,
			$headerVarTypeLines,
			$midFileVarTypes,
			$typedVars,
			$typedDefaults,
			$parameters,
			$defineParams,
			$defineParamDefaults,
			$this->resolvePlacements($events, $placementCandidates, $headerNames),
		);
	}

	private function keepsHead(TemplateEvent $event): bool
	{
		return ($event->kind === TemplateEvent::TEXT && $event->whitespace)
			|| ($event->kind === TemplateEvent::TAG && in_array($event->name, self::HEAD_ALLOWED_TAGS, true));
	}

	// {block} never carries params, so its entry is the empty list DeclarationInjector treats like a
	// param-less {define}. A default written as `= null` is an explicit default; the NullNode Latte
	// synthesises for a param without one has no source position.

	/**
	 * @return array{array<int, array{string|null, string}>, array<string, true>}
	 */
	private function parametersOf(Block $block): array
	{
		$params = [];
		$defaults = [];
		foreach ($block->parameters as $parameter) {
			if (!is_string($parameter->var->name)) {
				continue;
			}

			$name = $parameter->var->name;
			$params[] = [$this->typeOf($parameter->type), $name];
			$default = $parameter->default;
			if ($default !== null && !($default instanceof NullNode && $default->position === null)) {
				$defaults[$name] = true;
			}
		}

		return [$params, $defaults];
	}

	// parseType() yields a SuperiorTypeNode with the whitespace-free spelling Latte 2 concatenated.
	private function typeOf(?Node $type): ?string
	{
		if ($type === null) {
			return null;
		}

		return $type instanceof SuperiorTypeNode ? $type->type : $type->print(new PrintContext());
	}

	// A {varType} run's anchor is the first event after it that is not another {varType} and not
	// whitespace, mirroring keepsHead()'s own notion of what does not count as content.

	/**
	 * @param list<TemplateEvent> $events
	 * @param list<array{int, string, string, int, bool}> $candidates
	 * @param list<string> $headerNames
	 * @return list<VarTypePlacement>
	 */
	private function resolvePlacements(array $events, array $candidates, array $headerNames): array
	{
		$anchorIndexes = [];
		$runSizes = [];
		foreach ($candidates as $position => [$index]) {
			$next = $this->nextAnchorIndex($events, $index);
			$anchorIndexes[$position] = $next;
			$runSizes[$next ?? -1] = ($runSizes[$next ?? -1] ?? 0) + 1;
		}

		$assignedNames = false;

		$placements = [];
		foreach ($candidates as $position => [$index, $name, $type, $line, $insideBlockBody]) {
			$nextIndex = $anchorIndexes[$position];
			$described = $nextIndex === null
				? ['kind' => null, 'line' => null, 'bound' => null, 'label' => 'the end of the template']
				: $this->describeAnchor($events[$nextIndex]);

			if ($described['kind'] === null && $assignedNames === false) {
				$assignedNames = $this->scanAssignedNames($events);
			}

			$knownBeforeTag = $described['kind'] !== null
				&& $described['kind'] !== VarTypePlacement::ANCHOR_FOREACH
				&& $described['bound'] !== null
				&& !in_array($name, $described['bound'], true)
				&& $this->isNameBoundBefore($events, $index, $name, $headerNames);

			$placements[] = new VarTypePlacement(
				$name,
				$type,
				$line,
				$described['kind'],
				$described['line'],
				$described['label'],
				$described['bound'],
				$runSizes[$nextIndex ?? -1],
				$described['kind'] === null && $assignedNames !== false && !in_array($name, $assignedNames, true),
				$knownBeforeTag,
				$insideBlockBody,
			);
		}

		return $placements;
	}

	/**
	 * @param list<TemplateEvent> $events
	 * @param list<string> $headerNames
	 */
	private function isNameBoundBefore(array $events, int $beforeIndex, string $name, array $headerNames): bool
	{
		if (in_array($name, $headerNames, true)) {
			return true;
		}

		foreach ($events as $index => $event) {
			if ($index >= $beforeIndex) {
				break;
			}

			if ($event->kind === TemplateEvent::ELEMENT) {
				if ($event->foreach !== null && in_array($name, NodeReader::foreachBindings($event->foreach), true)) {
					return true;
				}

				continue;
			}

			if ($event->kind !== TemplateEvent::TAG) {
				continue;
			}

			$described = $this->describeAnchor($event);
			if ($described['kind'] === null) {
				continue;
			}

			if ($described['bound'] === null || in_array($name, $described['bound'], true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<TemplateEvent> $events
	 * @return list<string>|false
	 */
	private function scanAssignedNames(array $events)
	{
		$names = [];
		foreach ($events as $event) {
			if ($event->kind === TemplateEvent::ELEMENT) {
				if ($event->foreach !== null) {
					foreach (NodeReader::foreachBindings($event->foreach) as $name) {
						$names[$name] = true;
					}
				}

				continue;
			}

			if ($event->kind !== TemplateEvent::TAG) {
				continue;
			}

			$described = $this->describeAnchor($event);
			if ($described['kind'] === null) {
				continue;
			}

			if ($described['bound'] === null) {
				return false;
			}

			foreach ($described['bound'] as $name) {
				$names[$name] = true;
			}
		}

		return array_keys($names);
	}

	/**
	 * @param list<TemplateEvent> $events
	 */
	private function nextAnchorIndex(array $events, int $index): ?int
	{
		$count = count($events);
		for ($i = $index + 1; $i < $count; $i++) {
			$event = $events[$i];
			if ($event->kind === TemplateEvent::TEXT && $event->whitespace) {
				continue;
			}

			if ($event->kind === TemplateEvent::TAG && $event->name === 'varType') {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * @return array{kind: VarTypePlacement::ANCHOR_*|null, line: int|null, bound: list<string>|null, label: string}
	 */
	private function describeAnchor(TemplateEvent $event): array
	{
		if ($event->kind === TemplateEvent::ELEMENT) {
			if ($event->foreach !== null) {
				return [
					'kind' => VarTypePlacement::ANCHOR_FOREACH,
					'line' => $event->line,
					'bound' => NodeReader::foreachBindings($event->foreach),
					'label' => '<' . $event->name . '>',
				];
			}

			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => '<' . $event->name . '>'];
		}

		if ($event->kind === TemplateEvent::TEXT) {
			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => 'template output'];
		}

		if ($event->kind === TemplateEvent::CLOSE) {
			return ['kind' => null, 'line' => null, 'bound' => null, 'label' => '{/' . $event->name . '}'];
		}

		$label = $event->name === self::IMPLICIT_PRINT_TAG ? 'an output tag' : '{' . $event->name . '}';
		$node = $event->node;

		if ($node instanceof VarNode) {
			return [
				'kind' => VarTypePlacement::ANCHOR_ASSIGN,
				'line' => $event->line,
				'bound' => NodeReader::assignedNames($node),
				'label' => $label,
			];
		}

		if ($node instanceof CaptureNode) {
			$captured = NodeReader::capturedVariable($node);

			return [
				'kind' => VarTypePlacement::ANCHOR_CAPTURE,
				'line' => $event->line,
				'bound' => $captured === null ? null : [$captured],
				'label' => $label,
			];
		}

		if ($node instanceof ForeachNode) {
			return [
				'kind' => VarTypePlacement::ANCHOR_FOREACH,
				'line' => $event->line,
				'bound' => NodeReader::foreachBindings($node),
				'label' => $label,
			];
		}

		if ($node instanceof DoNode) {
			$assigned = NodeReader::simpleAssignTarget($node);

			return [
				'kind' => VarTypePlacement::ANCHOR_ASSIGN,
				'line' => $event->line,
				'bound' => $assigned === null ? null : [$assigned],
				'label' => $label,
			];
		}

		return ['kind' => null, 'line' => null, 'bound' => null, 'label' => $label];
	}

}
