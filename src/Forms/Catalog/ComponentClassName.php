<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog;

use Nette\ComponentModel\IComponent;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use function count;

/**
 * The single bar a class has to clear to enter the model as a registered component, and the single
 * reading of a type that names one.
 *
 * The bar is Nette\ComponentModel\IComponent, because that is what Container::addComponent() itself
 * accepts: below it nothing can ever become a child, so it is the runtime boundary and not merely a
 * modelling convenience. Everything the model does with a control class afterwards — the container
 * branch, the replicator branch, the value ladder, a custom class's own @form-read-type — sits inside
 * it, so a narrower bar would reject annotations the model then resolves perfectly well.
 *
 * A type naming two classes, or one class that is not a component, resolves to nothing: a class is
 * only read where it is unambiguous.
 */
final class ComponentClassName
{

	private function __construct()
	{
	}

	public static function of(Type $type): ?string
	{
		$classes = $type->getObjectClassNames();

		return count($classes) === 1 && self::isComponent($classes[0]) ? $classes[0] : null;
	}

	public static function isComponent(string $className): bool
	{
		return (new ObjectType(IComponent::class))->isSuperTypeOf(new ObjectType($className))->yes();
	}

}
