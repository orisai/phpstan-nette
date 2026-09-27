<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use OriPhpstan\Nette\Forms\Shape\FormShape;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use function ltrim;

final class ShapeDependencyCollector
{

	private ReflectionProvider $reflectionProvider;

	private DependencyRecorder $recorder;

	public function __construct(ReflectionProvider $reflectionProvider, DependencyRecorder $recorder)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->recorder = $recorder;
	}

	public function record(FormShape $shape): void
	{
		// Over-records (e.g. value-type classes referenced only by name): a spurious
		// dependency costs a recompute, never a stale shape.
		$classes = [];
		$this->collect($shape, $classes);

		foreach ($classes as $class => $_) {
			$this->recordHierarchy($class);
		}
	}

	/**
	 * @param array<string, true> $classes
	 */
	private function collect(FormShape $shape, array &$classes): void
	{
		$this->add($shape->getClassName(), $classes);
		$this->add($shape->getMappedType(), $classes);

		foreach ($shape->getComponentTypes() as $controlClass) {
			$this->add($controlClass, $classes);
		}

		foreach ($shape->getSlots() as $slot) {
			$this->add($slot->getControlClass(), $classes);
			foreach ($slot->getControlClasses() ?? [] as $controlClass) {
				$this->add($controlClass, $classes);
			}

			$this->addTypeClasses($slot->getValueType(), $classes);
			$this->addTypeClasses($slot->getFilledValueType(), $classes);
			$controlType = $slot->getControlType();
			if ($controlType !== null) {
				$this->addTypeClasses($controlType, $classes);
			}
		}

		foreach ($shape->getContainers() as $nested) {
			$this->collect($nested, $classes);
		}

		foreach ($shape->getReplicators() as $replicator) {
			$this->collect($replicator->getInner(), $classes);
			$this->collect($replicator->getOwn(), $classes);
		}
	}

	/**
	 * @param array<string, true> $classes
	 */
	private function addTypeClasses(Type $type, array &$classes): void
	{
		foreach ($type->getReferencedClasses() as $class) {
			$this->add($class, $classes);
		}
	}

	/**
	 * @param array<string, true> $classes
	 */
	private function add(?string $class, array &$classes): void
	{
		if ($class === null) {
			return;
		}

		$classes[ltrim($class, '\\')] = true;
	}

	private function recordHierarchy(string $fqcn): void
	{
		if (!$this->reflectionProvider->hasClass($fqcn)) {
			return;
		}

		$class = $this->reflectionProvider->getClass($fqcn);

		$lineage = [$class];
		foreach ($class->getParents() as $parent) {
			$lineage[] = $parent;
		}

		foreach ($class->getInterfaces() as $interface) {
			$lineage[] = $interface;
		}

		foreach ($lineage as $reflection) {
			$this->recordFile($reflection);
			foreach ($reflection->getTraits(true) as $trait) {
				$this->recordFile($trait);
			}
		}
	}

	private function recordFile(ClassReflection $class): void
	{
		$file = $class->getFileName();
		if ($file !== null) {
			$this->recorder->record($file);
		}
	}

}
