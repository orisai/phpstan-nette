<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Nodes\Php\Expression\VariableNode;
use Latte\Essential\Nodes\BlockNode;
use Latte\Essential\Nodes\DefineNode;
use OriPhpstan\Nette\Latte\Includes\BlockBodyTracker;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use function in_array;
use function is_string;
use function strpos;

// TemplateFactExtractor (Latte 2 tokens) over the event stream: include edges, block/define names,
// top-level {var}/{default}, block-body {varType} contracts and the per-line macro map.
final class EventFactExtractor
{

	private const LAYOUT_TAGS = ['extends', 'layout'];

	private const IMPLICIT_PRINT_TAG = '=';

	/**
	 * @param list<TemplateEvent> $events
	 */
	public function extract(array $events, string $source, string $projectRelativePath): TemplateFacts
	{
		$includeSites = [];
		$blockNames = [];
		$defineNames = [];
		$topLevelVars = [];
		$topLevelDefaults = [];
		$blockDeclaredVars = [];
		$blockDeclaredVarLines = [];
		$lineMacros = [];
		$layoutMode = null;
		$blocks = new BlockBodyTracker();

		foreach ($events as $event) {
			if ($event->kind === TemplateEvent::CLOSE) {
				$blocks->closing($event->closesBody);

				continue;
			}

			if ($event->kind !== TemplateEvent::TAG) {
				continue;
			}

			if ($event->name !== '' && $event->name !== self::IMPLICIT_PRINT_TAG) {
				$lineMacros[$event->line][] = ['name' => $event->name, 'column' => $event->column];
			}

			$node = $event->node;
			$record = $event->record;
			if ($node !== null && $record !== null) {
				if (IncludeSiteReader::isIncludeFamily($node)) {
					if ($layoutMode === null && in_array($event->name, self::LAYOUT_TAGS, true)) {
						$layoutMode = IncludeSiteReader::layoutMode($record);
					}

					$site = IncludeSiteReader::read($node, $record, $source, $projectRelativePath);
					if ($site !== null) {
						$includeSites[] = $site;
					}
				} elseif ($node instanceof BlockNode || $node instanceof DefineNode) {
					$name = $node->block === null ? '' : SourceText::blockName($node->block, $record, $source);
					if ($name !== '' && strpos($name, '$') === false) {
						if ($event->name === 'define') {
							$defineNames[] = $name;
						} else {
							$blockNames[] = $name;
						}
					}

					if ($event->opensBody) {
						$blocks->enterBlockOrDefine($name);
					}
				} elseif ($node instanceof VarDeclarationNode && $blocks->depth() === 0) {
					foreach ($node->assignments as $i => $assignment) {
						$var = $assignment->var;
						if (!$var instanceof VariableNode || !is_string($var->name)) {
							continue;
						}

						if ($node->default) {
							$topLevelDefaults[] = $var->name;

							continue;
						}

						if (($node->types[$i] ?? null) !== null) {
							continue;
						}

						$literal = NodeReader::literalType($assignment->expr);
						if ($literal !== null) {
							$topLevelVars[$var->name] = $literal;
						}
					}
				} elseif ($node instanceof VarTypeDeclarationNode && $blocks->isAtOwnTopLevel()) {
					$frame = $blocks->currentFrame();
					$varName = $node->declaration->getVariable();
					$type = $node->declaration->getType();
					if ($frame !== null && $varName !== null && $type !== null) {
						$blockDeclaredVars[$frame['name']][$varName] = $type;
						$blockDeclaredVarLines[$frame['name']][$varName] = $event->line;
					}
				}
			}

			$blocks->advance($event->opensBody);
		}

		return new TemplateFacts(
			$includeSites,
			$blockNames,
			$defineNames,
			$topLevelVars,
			$topLevelDefaults,
			$blockDeclaredVars,
			$blockDeclaredVarLines,
			$lineMacros,
			$layoutMode,
		);
	}

}
