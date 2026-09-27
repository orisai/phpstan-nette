<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Component\Attachment;

use Nette\Application\UI\Component as UiComponent;
use Nette\Application\UI\Form as UiForm;
use Nette\ComponentModel\Component;
use Nette\Forms\Container as FormsContainer;
use Nette\Forms\Controls\BaseControl;
use function array_fill_keys;
use function array_key_exists;
use function array_keys;

/**
 * The methods that reach `Component::lookup()` and let it THROW, and the argument at each call site
 * that decides whether it does.
 *
 * `lookup()` walks upward from `$this->parent`. On a component with no parent the walk never starts,
 * and with `$throw` left at its default it raises `Nette\InvalidStateException` instead of answering
 * null. Everything in the family below is that one method wearing a different name, so the whole
 * table is a statement about which spelling routes there and where its `$throw` sits - not about
 * what any of them returns.
 *
 * The table is keyed by DECLARING class, never by receiver class, because Nette overrides several of
 * these to stop throwing: `Nette\Forms\Form::getForm()` returns `$this`, and `Presenter` does the
 * same for `getPresenter()`, `getPresenterIfExists()` and `getUniqueId()`. Keying on the receiver
 * would claim a form throws when asked for its own form. Resolving the declaring class also makes a
 * project override drop out of the table by construction, which is the safe direction: an override
 * this cannot read claims nothing.
 *
 * `Nette\Application\UI\Component::getPresenter()` is the entry no signature read would produce. As
 * installed it declares NO parameters and takes one anyway:
 *
 *     public function getPresenter(): ?Presenter
 *     {
 *         if (func_num_args()) { trigger_error(…); $throw = func_get_arg(0); }
 *         return $this->lookup(Presenter::class, $throw ?? true);
 *     }
 *
 * so `getPresenter(false)` is legal (with a deprecation) while looking like an arity error, and
 * older Nette declared the parameter outright. Argument 0 is therefore recorded as its `$throw`
 * although reflection reports the method as taking none. That single fact is the reason this table
 * is a vendor fact WITH A VERSION rather than a constant, and the reason
 * ComponentModelApiFreshnessTest runs the installed code rather than comparing a copied signature:
 * every entry here is behaviourally re-derived on each run of `make phpstan-forms-vendor-fresh`,
 * and a lookup-backed accessor Nette adds later fails that gate rather than silently going
 * unreported.
 *
 * `Nette\Application\UI\Component::getUniqueId()` has no `$throw` at all - it hands `lookupPath()`
 * a hardcoded `true` - so it is recorded with a null argument index, meaning it throws whenever the
 * receiver is detached.
 */
final class ParentAccessors
{

	public const LOOKUP = 'lookup';

	public const LOOKUP_PATH = 'lookupPath';

	public const GET_FORM = 'getForm';

	public const GET_PRESENTER = 'getPresenter';

	public const GET_UNIQUE_ID = 'getUniqueId';

	/**
	 * method => declaring class => the argument index carrying $throw, or null when the method takes
	 * no $throw and always throws on a detached receiver.
	 */
	private const THROW_ARGUMENT = [
		self::LOOKUP => [Component::class => 1],
		self::LOOKUP_PATH => [Component::class => 1],
		self::GET_FORM => [
			BaseControl::class => 0,
			FormsContainer::class => 0,
		],
		self::GET_PRESENTER => [
			UiComponent::class => 0,
			UiForm::class => 0,
		],
		self::GET_UNIQUE_ID => [UiComponent::class => null],
	];

	/**
	 * The spelling of the same question that answers null instead of throwing. `getUniqueId()` has
	 * none: an id is a path through ancestors and there is no path to hand back.
	 */
	private const NO_THROW_SPELLING = [
		self::LOOKUP => 'lookup($type, false)',
		self::LOOKUP_PATH => 'lookupPath($type, false)',
		self::GET_FORM => 'getForm(false)',
		self::GET_PRESENTER => 'getPresenterIfExists()',
		self::GET_UNIQUE_ID => null,
	];

	private function __construct()
	{
	}

	/**
	 * The names worth looking at, as a lookup set. It is the cheap gate the walk runs before it
	 * builds any state at all: a body that never spells one of these can produce no report.
	 *
	 * @return array<string, true>
	 */
	public static function methodNames(): array
	{
		return array_fill_keys(array_keys(self::THROW_ARGUMENT), true);
	}

	public static function isThrowingAccessor(string $declaringClass, string $method): bool
	{
		return isset(self::THROW_ARGUMENT[$method])
			&& array_key_exists($declaringClass, self::THROW_ARGUMENT[$method]);
	}

	/**
	 * Null means the method takes no $throw and throws unconditionally on a detached receiver, so
	 * callers must have asked isThrowingAccessor() first - a pair this does not know answers null
	 * too, and reading that as "always throws" would report on any method sharing a name.
	 */
	public static function throwArgument(string $declaringClass, string $method): ?int
	{
		return self::THROW_ARGUMENT[$method][$declaringClass] ?? null;
	}

	public static function noThrowSpelling(string $method): ?string
	{
		return self::NO_THROW_SPELLING[$method] ?? null;
	}

	/**
	 * The whole table, flattened, for the freshness gate to re-derive entry by entry.
	 *
	 * @return list<array{string, string, int|null}> declaring class, method, $throw argument index
	 */
	public static function all(): array
	{
		$entries = [];
		foreach (self::THROW_ARGUMENT as $method => $classes) {
			foreach ($classes as $class => $throwArgument) {
				$entries[] = [$class, $method, $throwArgument];
			}
		}

		return $entries;
	}

}
