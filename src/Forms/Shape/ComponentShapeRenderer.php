<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function ltrim;
use function sort;
use function str_repeat;

/**
 * Renders a component shape in PHPStan-shape style, multi-line and indented: a form/container
 * is `ClassName{ field: …, … }`; an input is `ControlClass<write-type, read-type>` (or bare
 * `ControlClass` when $formValues is off); a replicator is `ReplicatorClass<array<int, item>>`;
 * an open shape carries a trailing `...<IComponent>` member. $maxDepth truncates deep blocks.
 */
final class ComponentShapeRenderer
{

	private const Indent = '  ';

	private ControlAcceptedTypeResolver $acceptedTypeResolver;

	private ?int $maxDepth = null;

	private bool $formValues = true;

	private bool $filled = false;

	public function __construct(ControlAcceptedTypeResolver $acceptedTypeResolver)
	{
		$this->acceptedTypeResolver = $acceptedTypeResolver;
	}

	public function render(
		FormShape $shape,
		?int $maxDepth = null,
		bool $formValues = true,
		bool $filled = false
	): string
	{
		$this->maxDepth = $maxDepth;
		$this->formValues = $formValues;
		$this->filled = $filled;

		return $this->renderShape($shape, $this->className($shape->getClassName()), 0);
	}

	/**
	 * A @form-wizard component: a block of step members, each its step number mapped to the
	 * step form's shape (a step that could not be shaped is *mixed*).
	 *
	 * @param array<int, FormShape|null> $steps
	 */
	public function renderWizard(
		string $className,
		array $steps,
		?int $maxDepth = null,
		bool $formValues = true,
		bool $filled = false
	): string
	{
		$this->maxDepth = $maxDepth;
		$this->formValues = $formValues;
		$this->filled = $filled;

		$members = [];
		foreach ($steps as $n => $shape) {
			$members[] = 'step ' . $n . ': ' . (
				$shape === null
					? '*mixed*'
					: $this->renderShape($shape, $this->className($shape->getClassName()), 1)
			);
		}

		return $this->block(ltrim($className, '\\'), $members, 0);
	}

	/**
	 * A component whose own shape the engine could not resolve (a non-Container component, or
	 * one whose builder we cannot follow): only its class is known.
	 *
	 * @param list<string> $reasons
	 */
	public function renderOpaque(string $className, array $reasons): string
	{
		return $this->className($className);
	}

	private function renderShape(FormShape $shape, string $header, int $depth): string
	{
		$members = [];
		foreach ($this->orderedChildren($shape) as $name) {
			$members[] = $this->renderMember($shape, $name, $depth + 1);
		}

		if ($shape->getUnknown()->hasUnknown()) {
			$members[] = '...<IComponent>';
		}

		return $this->block($header, $members, $depth);
	}

	/**
	 * @param list<string> $members
	 */
	private function block(string $header, array $members, int $depth): string
	{
		if ($members === []) {
			return $header . '{}';
		}

		if ($this->maxDepth !== null && $depth >= $this->maxDepth) {
			return $header . '{ … }';
		}

		$inner = implode("\n", array_map(
			static fn (string $member): string => str_repeat(self::Indent, $depth + 1) . $member . ',',
			$members,
		));

		return $header . "{\n" . $inner . "\n" . str_repeat(self::Indent, $depth) . '}';
	}

	private function renderMember(FormShape $shape, string $name, int $depth): string
	{
		$containers = $shape->getContainers();
		$replicators = $shape->getReplicators();
		$slots = $shape->getSlots();
		$componentTypes = $shape->getComponentTypes();

		if (isset($containers[$name])) {
			$marker = $this->optionalMarker($shape->getContainerPresence()[$name] ?? Certainty::HAPPENS);
			$header = $this->className($containers[$name]->getClassName() ?? ($componentTypes[$name] ?? null));

			return $name . $marker . ': ' . $this->renderShape($containers[$name], $header, $depth);
		}

		if (isset($replicators[$name])) {
			$marker = $this->optionalMarker($shape->getReplicatorPresence()[$name] ?? Certainty::HAPPENS);

			return $name . $marker . ': '
				. $this->renderReplicator($replicators[$name], $componentTypes[$name] ?? null, $depth);
		}

		if (isset($slots[$name])) {
			return $name . $this->optionalMarker($slots[$name]->getPresence()) . ': '
				. $this->renderSlot($slots[$name], $componentTypes[$name] ?? null);
		}

		// A componentTypes-only member - a value-less control - so there is no shaped channel above to
		// read a marker off and its own axis is the one that answers. The three arms above each read
		// their channel's presence the same way; this member type simply had no presence to read until
		// componentTypes grew one, so it rendered unmarked whether the control was conditional or not.
		return $name . $this->optionalMarker($shape->componentTypePresence($name)) . ': '
			. $this->className($componentTypes[$name] ?? null);
	}

	private function renderReplicator(ReplicatorShape $replicator, ?string $replicatorClass, int $depth): string
	{
		$inner = $replicator->getInner();
		$mapped = $inner->getMappedType();
		$item = $mapped !== null
			? $this->className($mapped)
			: $this->renderShape($inner, $this->className($inner->getClassName()), $depth);

		$own = $replicator->getOwn();
		$ownSuffix = $this->isTrivialOwn($own)
			? ''
			: '+own' . $this->renderShape($own, $this->className($own->getClassName()), $depth);

		return $this->className($replicatorClass) . '<array<int, ' . $item . '>>' . $ownSuffix;
	}

	private function isTrivialOwn(FormShape $own): bool
	{
		return $own->getSlots() === []
			&& $own->getContainers() === []
			&& $own->getReplicators() === []
			&& $own->getComponentTypes() === []
			&& !$own->getUnknown()->hasUnknown();
	}

	private function renderSlot(ComponentSlot $slot, ?string $componentType): string
	{
		$control = $this->className($slot->getControlClass() ?? $componentType);

		if (!$this->formValues) {
			return $control;
		}

		$valueType = $this->filled ? $slot->getFilledValueType() : $slot->getValueType();
		$read = $slot->isTypeOpaque() ? '*unknown*' : $this->describe($valueType);

		return $control . '<' . $this->acceptedLabel($slot) . ', ' . $read . '>';
	}

	private function acceptedLabel(ComponentSlot $slot): string
	{
		$spec = $slot->getAcceptedSetSpec();
		if ($spec === null) {
			return '*any*';
		}

		if ($this->acceptedTypeResolver->isNoOp($spec)) {
			return '*write-only*';
		}

		return $this->describe($this->acceptedTypeResolver->accepted($spec, $slot->isNullable()));
	}

	private function className(?string $className): string
	{
		return $className === null || $className === '' ? '*mixed*' : ltrim($className, '\\');
	}

	private function optionalMarker(string $presence): string
	{
		return $presence === Certainty::MAYBE ? '?' : '';
	}

	private function describe(Type $type): string
	{
		return $type->describe(VerbosityLevel::precise());
	}

	/**
	 * Every child name across slots, containers, replicators and opaque component types, sorted
	 * so the dump is deterministic regardless of the order the store recorded them in.
	 *
	 * @return list<string>
	 */
	private function orderedChildren(FormShape $shape): array
	{
		$names = array_values(array_unique([
			...array_keys($shape->getSlots()),
			...array_keys($shape->getContainers()),
			...array_keys($shape->getReplicators()),
			...array_keys($shape->getComponentTypes()),
		]));
		sort($names);

		return $names;
	}

}
