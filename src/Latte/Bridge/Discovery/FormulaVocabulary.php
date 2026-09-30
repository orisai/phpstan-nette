<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use LogicException;
use Nette\Application\Helpers;
use function basename;
use function dirname;
use function in_array;
use function lcfirst;
use function preg_match;
use function sort;
use function strlen;
use function strncmp;
use function substr;
use function substr_compare;
use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

// Static path algebra of every known template-file discovery formula, mirrored byte-for-byte from
// its origin (FormulaParityTest is the committed tripwire): the vendor formulas from
// Nette\Application\UI\Presenter::formatTemplateFiles()/formatLayoutTemplateFiles(), the app
// formulas from the locator overrides tests/phpstan.neon assigns via orisai.nette.latte.discovery.formulas.
// Runtime-only inputs are projected to their static defaults: $this->view is the resolved view
// name, $this->layout is unset ('layout'), a control's $this->file override is unset (short class
// name) - the file_exists gate on that override is invisible per-class, so the derived
// convention path is the candidate, and the same holds for the property-named formula's is_file
// gate on its property value. The shared-fallback formula's fixed second candidate is
// recorded in normalized form - the app spells it __DIR__ . '/../BaseControl/templates/...', a
// self-cancelling hop equal to the declaring dir's own templates/ subdir.
final class FormulaVocabulary
{

	public const VENDOR_TWO_CANDIDATE = 'vendor-two-candidate';

	public const SAMEDIR_SINGLE = 'samedir-single';

	public const DIRNAME_LCFIRST = 'dirname-lcfirst';

	public const DIRNAME_TEMPLATES_LCFIRST = 'dirname-templates-lcfirst';

	public const DIRNAME_TEMPLATES_LCFIRST_FALLBACK = 'dirname-templates-lcfirst-fallback';

	public const DIRNAME_PROPERTY_LCFIRST = 'dirname-property-lcfirst';

	public const VENDOR_LAYOUT_WALK = 'vendor-layout-walk';

	public const VIEW_FORMULAS = [
		self::VENDOR_TWO_CANDIDATE,
		self::SAMEDIR_SINGLE,
	];

	public const CONVENTION_FORMULAS = [
		self::DIRNAME_LCFIRST,
		self::DIRNAME_TEMPLATES_LCFIRST,
		self::DIRNAME_TEMPLATES_LCFIRST_FALLBACK,
		self::DIRNAME_PROPERTY_LCFIRST,
	];

	// The action-name guard of Nette\Application\UI\Presenter::initGlobalParameters(), mirrored: it
	// error()s on the request's action parameter immediately before changeAction() (which validates
	// nothing itself), so no request can dispatch to a name outside it.
	public const VIEW_NAME_PATTERN = '#^[a-zA-Z0-9][a-zA-Z0-9_\x7f-\xff]*$#D';

	public static function isKnown(string $formula): bool
	{
		return in_array($formula, self::VIEW_FORMULAS, true)
			|| in_array($formula, self::CONVENTION_FORMULAS, true)
			|| $formula === self::VENDOR_LAYOUT_WALK;
	}

	/**
	 * @param self::VENDOR_TWO_CANDIDATE|self::SAMEDIR_SINGLE $formula
	 * @param callable(string): bool $isDir
	 * @return list<string>
	 */
	public static function viewCandidates(
		string $formula,
		string $classFile,
		string $presenter,
		string $view,
		callable $isDir
	): array
	{
		if ($formula === self::VENDOR_TWO_CANDIDATE) {
			$dir = dirname($classFile);
			$dir = $isDir("$dir/templates") ? $dir : dirname($dir);

			return [
				"$dir/templates/$presenter/$view.latte",
				"$dir/templates/$presenter.$view.latte",
			];
		}

		$dir = dirname($classFile);

		return [
			"$dir/$presenter.$view.latte",
		];
	}

	// The REVERSE of viewCandidates(): every view formula is a path function whose only variable
	// segment is the view name, so it inverts - list the directory the fixed part names and recover
	// the view from what is left. Nette makes both dispatch methods optional, so this is the only
	// channel that sees a view whose template file is all there is of it. A formula with no view
	// axis (the layout walk, the convention formulas) answers null rather than an empty list:
	// "cannot be inverted" is not "offers no views". null is reserved for that explicit answer -
	// anything on the view axis without a branch here, and anything unrecognised, throws, because
	// answering null by omission would silently drop both the views and the listing probes. The
	// recovered name is held to the vendor's own action-name grammar (the initGlobalParameters()
	// guard), which is what keeps @layout.latte, block partials and dotted leftovers out.

	/**
	 * @param callable(string): bool $isDir
	 * @param callable(string): list<string> $listDirectory
	 * @return list<string>|null
	 * @throws LogicException
	 */
	public static function offeredViews(
		string $formula,
		string $classFile,
		string $presenter,
		callable $isDir,
		callable $listDirectory
	): ?array
	{
		if ($formula === self::VENDOR_TWO_CANDIDATE) {
			$dir = dirname($classFile);
			$dir = $isDir("$dir/templates") ? $dir : dirname($dir);
			$scans = [
				["$dir/templates/$presenter", ''],
				["$dir/templates", "$presenter."],
			];
		} elseif ($formula === self::SAMEDIR_SINGLE) {
			$scans = [[dirname($classFile), "$presenter."]];
		} elseif (in_array($formula, self::VIEW_FORMULAS, true)) {
			throw new LogicException("View formula $formula is on the view axis but has no inverse here.");
		} elseif (self::isKnown($formula)) {
			return null;
		} else {
			throw new LogicException("Unknown discovery formula $formula cannot be inverted.");
		}

		// Deduplicated as a LIST, never through array keys: a numeric view name (the 404/405 error
		// templates) would come back out of an array key as an int.
		$views = [];
		foreach ($scans as [$directory, $prefix]) {
			foreach ($listDirectory($directory) as $name) {
				$view = self::viewNameOf($name, $prefix);
				if ($view !== null && !in_array($view, $views, true)) {
					$views[] = $view;
				}
			}
		}

		sort($views, SORT_STRING);

		return $views;
	}

	private static function viewNameOf(string $name, string $prefix): ?string
	{
		$prefixLength = strlen($prefix);
		$suffixLength = strlen(TemplateDirectoryListing::SUFFIX);
		if (
			strlen($name) <= $prefixLength + $suffixLength
			|| strncmp($name, $prefix, $prefixLength) !== 0
			|| substr_compare($name, TemplateDirectoryListing::SUFFIX, -$suffixLength) !== 0
		) {
			return null;
		}

		$view = (string) substr($name, $prefixLength, -$suffixLength);

		return preg_match(self::VIEW_NAME_PATTERN, $view) === 1 ? $view : null;
	}

	/**
	 * @param callable(string): bool $isDir
	 * @return list<string>
	 */
	public static function layoutCandidates(
		string $classFile,
		string $module,
		string $presenter,
		callable $isDir
	): array
	{
		$layout = 'layout';
		$dir = dirname($classFile);
		$dir = $isDir("$dir/templates") ? $dir : dirname($dir);
		$list = [
			"$dir/templates/$presenter/@$layout.latte",
			"$dir/templates/$presenter.@$layout.latte",
		];
		while (true) {
			$list[] = "$dir/templates/@$layout.latte";
			$dir = dirname($dir);

			// The vendor do/while condition with its string truthiness spelled out ('' and '0'
			// are the only falsy strings; the splitName() reassignment operand is always truthy).
			if ($dir === '' || $dir === '0' || $module === '' || $module === '0') {
				break;
			}

			[$module] = Helpers::splitName($module);
		}

		return $list;
	}

	/**
	 * @param self::DIRNAME_LCFIRST|self::DIRNAME_TEMPLATES_LCFIRST $formula
	 */
	public static function conventionCandidate(string $formula, string $classFile, string $shortClassName): string
	{
		if ($formula === self::DIRNAME_LCFIRST) {
			return dirname($classFile) . DIRECTORY_SEPARATOR . lcfirst($shortClassName) . '.latte';
		}

		return dirname($classFile) . DIRECTORY_SEPARATOR . 'templates'
			. DIRECTORY_SEPARATOR
			. lcfirst($shortClassName) . '.latte';
	}

	// The fixed second candidate of the shared-fallback formula, anchored at the file DECLARING the
	// locator override (the entry class only shapes the derived first candidate).
	public static function conventionFallbackCandidate(string $declaringFile, string $sharedFallback): string
	{
		return dirname($declaringFile) . DIRECTORY_SEPARATOR . 'templates'
			. DIRECTORY_SEPARATOR
			. $sharedFallback;
	}

	// The name is the property's resolved default; only its null case derives one, from the lcfirst
	// DIRECTORY basename and never the short class name - a class whose directory is named
	// differently gets the directory's name. The origin applies basename()'s '.php' strip to that
	// directory, where it is inert; mirrored as spelled rather than tidied away.
	public static function conventionPropertyCandidate(string $classFile, ?string $propertyValue): string
	{
		$path = dirname($classFile);
		$name = $propertyValue ?? lcfirst(basename($path, '.php'));

		return $path . DIRECTORY_SEPARATOR . $name . '.latte';
	}

}
