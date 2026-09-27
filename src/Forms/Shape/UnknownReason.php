<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

final class UnknownReason
{

	public const DYNAMIC_NAME = 'dynamic_name';

	public const VENDOR_MAGIC_CALL = 'vendor_magic_call';

	public const NON_ENUMERABLE_CLOSURE = 'non_enumerable_closure';

	public const UNRESOLVED_ORIGIN = 'unresolved_origin';

	public const EXTENSION_METHOD = 'extension_method';

	public const UNFOLLOWED_CALL = 'unfollowed_call';

	public const CONTAINER_REFERENCE = 'container_reference';

	public const CONSTRUCTOR_BUILD = 'constructor_build';

	public const FORM_ALIASED = 'form_aliased';

	public const FIRST_CLASS_CALLABLE = 'first_class_callable';

	public const DYNAMIC_METHOD = 'dynamic_method';

	public const UNKNOWN_REMOVAL = 'unknown_removal';

	public const REBIND_UNPROVEN = 'rebind_unproven';

	// A registering method declared OUTSIDE the analysed paths whose body refutes the walk's own
	// naming convention — one component, named by the call's first argument — and which declares no
	// @form-adds to say what it really does. In our own code the walk reads the body and needs no
	// declaration; in vendor code the tag is the only channel, so its absence is where the name is
	// genuinely lost. Reading the body to DISPROVE the convention is not the same as reading it to
	// resolve the registration: a body this cannot read leaves the convention standing.
	public const UNANNOTATED_REGISTRAR = 'unannotated_registrar';

	// A method DECLARING what it registers (@form-adds) was called on the tracked form under a name
	// the component-affecting pass does not mark. That pass is deliberately scope-free — its node ids
	// are the walk's cache key — so it can only gate on the name, and there is no tagged node for the
	// declaration to be resolved against. The declaration is nonetheless proof that a component was
	// added, so the shape opens: closing it would prove absent exactly the component the author took
	// the trouble to declare.
	public const DECLARED_ADD_UNREAD = 'declared_add_unread';

	// A form-mutating callable was handed to a call whose parameter PHPStan's own trinary
	// (isImmediatelyInvokedCallable) answers MAYBE for. The walk can then neither claim the
	// mutations have happened by the time the form is returned nor that they have not, so the
	// shape opens. A proven-immediate callable is absorbed instead, and a proven-later one is
	// excluded from the render-time shape without opening it.
	public const CALLBACK_TIMING = 'callback_timing';

	// A form held in an object PROPERTY has a write the per-class fold cannot enumerate: the property
	// is reachable from outside the class (public/protected, or handed out by a method that returns
	// it), or a body other than the ones binding it touches it in a way the walk could not attribute.
	// v1 models no write it has not read, so the shape must say so rather than list a member set it
	// cannot vouch for — a shape that closes without seeing every write is how a false "this field
	// does not exist" is born.
	public const PROPERTY_WRITE_UNMODELLED = 'property_write_unmodelled';

	// Produced only by the Latte+Forms bridge, for a form whose name set it cannot prove complete
	// although the shape itself records no unknown.
	public const UNPROVEN_COMPLETE = 'unproven_complete';

	public const LOST_FIELD_UNKNOWN_REASONS = [
		self::CONTAINER_REFERENCE,
		self::CONSTRUCTOR_BUILD,
		self::FORM_ALIASED,
		self::FIRST_CLASS_CALLABLE,
		self::DYNAMIC_METHOD,
		self::UNKNOWN_REMOVAL,
		self::REBIND_UNPROVEN,
		self::CALLBACK_TIMING,
		// An unread write can be a removeComponent() or an offsetSet as easily as an add, so a name
		// this shape DOES list may already mean something else — which is precisely what this set
		// names, as against the reasons that only promise further unlisted names.
		self::PROPERTY_WRITE_UNMODELLED,
		self::UNPROVEN_COMPLETE,
	];

	private function __construct()
	{
	}

}
