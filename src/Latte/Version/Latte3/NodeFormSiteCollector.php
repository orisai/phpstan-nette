<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version\Latte3;

use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Html\ElementNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Essential\Nodes\IfNode;
use Nette\Bridges\FormsLatte\Nodes\FieldNNameNode;
use Nette\Bridges\FormsLatte\Nodes\FormContainerNode;
use Nette\Bridges\FormsLatte\Nodes\FormNNameNode;
use Nette\Bridges\FormsLatte\Nodes\FormNode;
use Nette\Bridges\FormsLatte\Nodes\InputErrorNode;
use Nette\Bridges\FormsLatte\Nodes\InputNode;
use Nette\Bridges\FormsLatte\Nodes\LabelNode;
use OriPhpstan\Nette\Latte\Forms\ComponentNameSyntax;
use OriPhpstan\Nette\Latte\Forms\ControlReference;
use OriPhpstan\Nette\Latte\Forms\ExistenceGuard;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use SplObjectStorage;
use function array_key_last;
use function array_pop;
use function array_reverse;
use function array_values;
use function count;

// FormSiteScanner (Latte 2 tokens) over the node tree, fed by the same traversal as the event
// stream: every {form}/{formContext}/<form n:name> scope with the control references lexically
// inside it and their {formContainer} path. Scopes are frames bound to the node that opened them, so
// nesting is the tree's own; an existence check ({ifset $form['x']}, n:ifset) is a frame that marks
// what it encloses. Nothing is resolved - an argument that is not a component-name literal is a null name.
final class NodeFormSiteCollector
{

	private const FRAME_FORM = 'form';

	private const FRAME_CONTAINER = 'container';

	private const FRAME_GUARD = 'guard';

	private TagRecorder $recorder;

	/** @var list<array{name: string|null, line: int}> */
	private array $sites = [];

	/** @var array<int, list<ControlReference>> */
	private array $references = [];

	/** @var list<array{kind: string, node: Node, siteIndex: int, container: string|null, guard: list<string>|null}> */
	private array $frames = [];

	/** @var SplObjectStorage<ElementNode, list<array{int, int}>> */
	private SplObjectStorage $elementReferences;

	public function __construct(TagRecorder $recorder)
	{
		$this->recorder = $recorder;
		$this->elementReferences = new SplObjectStorage();
	}

	public function enter(Node $node): void
	{
		$record = $this->recorder->recordFor($node);
		if ($record === null) {
			return;
		}

		$line = $record->getLine();

		if ($node instanceof FormNode || $node instanceof FormNNameNode) {
			$this->sites[] = ['name' => $this->literalName($node->name), 'line' => $line];
			$this->frames[] = $this->frame(self::FRAME_FORM, $node, count($this->sites) - 1, null, null);
		} elseif ($node instanceof FormContainerNode) {
			// A dynamic {formContainer $x} makes every name below it unresolvable - the container
			// path could not express it - so the whole scope is dropped. Siblings outside it stay.
			$container = $this->literalName($node->name);
			$this->addReference(ControlReference::KIND_CONTAINER, $container, $line, $record);
			$this->frames[] = $this->frame(self::FRAME_CONTAINER, $node, 0, $container, null);
		} elseif ($node instanceof InputNode) {
			$this->addReference(ControlReference::KIND_INPUT, $this->literalName($node->name), $line, $record);
		} elseif ($node instanceof LabelNode) {
			$this->addReference(ControlReference::KIND_LABEL, $this->literalName($node->name), $line, $record);
		} elseif ($node instanceof FieldNNameNode) {
			$this->addReference(ControlReference::KIND_NAME_ATTR, $this->literalName($node->name), $line, $record);
		} elseif ($node instanceof InputErrorNode) {
			$this->addReference(ControlReference::KIND_INPUT_ERROR, $this->literalName($node->name), $line, $record);
		} elseif ($node instanceof IfNode && $node->ifset) {
			$this->openGuard($node, $record);
		}
	}

	public function leave(Node $node): void
	{
		while ($this->frames !== [] && $this->frames[array_key_last($this->frames)]['node'] === $node) {
			array_pop($this->frames);
		}
	}

	/**
	 * @return list<FormSite>
	 */
	public function sites(): array
	{
		$result = [];
		foreach ($this->sites as $index => $site) {
			$result[] = new FormSite($site['name'], $site['line'], $this->references[$index] ?? []);
		}

		return $result;
	}

	private function openGuard(IfNode $node, TagRecord $record): void
	{
		$args = $record->getArgumentsText();
		if (!ExistenceGuard::isComponentCheck($args)) {
			return;
		}

		$guard = ExistenceGuard::pathOf($args);
		$this->frames[] = $this->frame(self::FRAME_GUARD, $node, 0, null, $guard);

		// n:ifset guards its whole element, including an n:name Latte nested the other way round.
		$element = $record->getTag()->htmlElement;
		if (!$record->isAttribute() || $element === null || !$this->elementReferences->contains($element)) {
			return;
		}

		foreach ($this->elementReferences[$element] as [$siteIndex, $index]) {
			$siteReferences = $this->references[$siteIndex];
			$reference = $siteReferences[$index];
			if (!ExistenceGuard::covers([$guard], $reference->getContainerPath(), $reference->getName())) {
				continue;
			}

			$siteReferences[$index] = $reference->asGuarded();
			$this->references[$siteIndex] = array_values($siteReferences);
		}
	}

	/**
	 * @param ControlReference::KIND_* $kind
	 */
	private function addReference(string $kind, ?string $name, int $line, TagRecord $record): void
	{
		$path = [];

		/** @var list<list<string>|null> $guards */
		$guards = [];

		for ($i = count($this->frames) - 1; $i >= 0; $i--) {
			$frame = $this->frames[$i];

			if ($frame['kind'] === self::FRAME_GUARD) {
				$guards[] = $frame['guard'];

				continue;
			}

			if ($frame['kind'] === self::FRAME_FORM) {
				$containerPath = array_reverse($path);
				$reference = new ControlReference($kind, $name, $containerPath, $line);
				$siteIndex = $frame['siteIndex'];
				$siteReferences = $this->references[$siteIndex] ?? [];
				$siteReferences[] = ExistenceGuard::covers($guards, $containerPath, $name)
					? $reference->asGuarded()
					: $reference;
				$this->references[$siteIndex] = $siteReferences;

				$element = $record->getTag()->htmlElement;
				if ($record->isAttribute() && $element !== null) {
					$locations = $this->elementReferences->contains($element) ? $this->elementReferences[$element] : [];
					$locations[] = [$siteIndex, count($siteReferences) - 1];
					$this->elementReferences[$element] = $locations;
				}

				return;
			}

			if ($frame['container'] === null) {
				return;
			}

			$path[] = $frame['container'];
		}
	}

	/**
	 * @param list<string>|null $guard
	 * @return array{kind: string, node: Node, siteIndex: int, container: string|null, guard: list<string>|null}
	 */
	private function frame(string $kind, Node $node, int $siteIndex, ?string $container, ?array $guard): array
	{
		return ['kind' => $kind, 'node' => $node, 'siteIndex' => $siteIndex, 'container' => $container, 'guard' => $guard];
	}

	private function literalName(Node $name): ?string
	{
		return ComponentNameSyntax::literalName(
			$name instanceof ExpressionNode ? NodeReader::literalName($name) : null,
		);
	}

}
