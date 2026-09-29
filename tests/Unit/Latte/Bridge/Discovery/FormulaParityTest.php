<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Discovery;

use LogicException;
use Nette\Application\BadRequestException;
use Nette\Application\Helpers;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;
use Nette\ComponentModel\Component;
use OriPhpstan\Nette\Latte\Bridge\Discovery\FormulaVocabulary;
use OriPhpstan\Nette\Latte\Bridge\Discovery\TemplateDirectoryListing;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PresenterFactory;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackDerivedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackMissingControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFallbackSharedControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryFileViewPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryLegacyPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryLocatorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryPropertyNameControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryPropertyNameNullControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryTemplatesPathControl;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\DiscoveryVendorPresenter;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\SubModule\DiscoveryDeepPresenter;
use function array_map;
use function array_merge;
use function is_dir;
use function is_file;
use function preg_match;
use function var_export;

// Committed tripwire: the mirrored formula output is byte-compared against the REAL vendor (and
// replica) locator methods invoked on reflection-set fixture instances - a vendor upgrade that
// changes the path algebra fails here, not in production discovery.
final class FormulaParityTest extends BaseTestCase
{

	// One fixture per view formula, keyed by the formula constant: a formula added to VIEW_FORMULAS
	// without an entry here fails testEveryViewFormulaOffersAWorkingInversion rather than silently
	// losing its inversion.
	private const INVERSION_PROBES = [
		FormulaVocabulary::VENDOR_TWO_CANDIDATE => [DiscoveryFileViewPresenter::class, 'Fixture:DiscoveryFileView'],
		FormulaVocabulary::SAMEDIR_SINGLE => [DiscoveryLocatorPresenter::class, 'Fixture:DiscoveryLocator'],
	];

	public function testVendorTwoCandidateMatchesFormatTemplateFilesOnTheTemplatesDirBranch(): void
	{
		$presenter = self::presenter(DiscoveryVendorPresenter::class, 'Fixture:DiscoveryVendor', 'default');

		self::assertSame(
			$presenter->formatTemplateFiles(),
			self::mirroredViewCandidates(FormulaVocabulary::VENDOR_TWO_CANDIDATE, $presenter, 'default'),
		);
	}

	public function testVendorTwoCandidateMatchesFormatTemplateFilesOnTheDirnameAscentBranch(): void
	{
		$presenter = self::presenter(DiscoveryDeepPresenter::class, 'Fixture:Sub:Deep', 'detail');

		self::assertSame(
			$presenter->formatTemplateFiles(),
			self::mirroredViewCandidates(FormulaVocabulary::VENDOR_TWO_CANDIDATE, $presenter, 'detail'),
		);
	}

	public function testVendorLayoutWalkMatchesFormatLayoutTemplateFilesForASingleModuleName(): void
	{
		$presenter = self::presenter(DiscoveryVendorPresenter::class, 'Fixture:DiscoveryVendor', 'default');

		self::assertSame(
			$presenter->formatLayoutTemplateFiles(),
			self::mirroredLayoutCandidates($presenter),
		);
	}

	public function testVendorLayoutWalkMatchesFormatLayoutTemplateFilesForADeepModuleName(): void
	{
		$presenter = self::presenter(DiscoveryDeepPresenter::class, 'Fixture:Sub:Deep', 'detail');

		self::assertSame(
			$presenter->formatLayoutTemplateFiles(),
			self::mirroredLayoutCandidates($presenter),
		);
	}

	public function testSamedirSingleMatchesTheLocatorTraitReplica(): void
	{
		$presenter = self::presenter(DiscoveryLocatorPresenter::class, 'Fixture:DiscoveryLocator', 'default');

		self::assertSame(
			$presenter->formatTemplateFiles(),
			self::mirroredViewCandidates(FormulaVocabulary::SAMEDIR_SINGLE, $presenter, 'default'),
		);
	}

	// The inverse is held to the forward formula, not to a hand-written expectation: every view the
	// reverse enumeration offers must format BACK - through the very method the vendor calls - to a
	// path that exists on disk.
	public function testEveryReverseEnumeratedViewRoundTripsThroughTheVendorFormula(): void
	{
		$presenter = self::presenter(DiscoveryFileViewPresenter::class, 'Fixture:DiscoveryFileView', 'default');

		$offered = self::mirroredOfferedViews(FormulaVocabulary::VENDOR_TWO_CANDIDATE, $presenter);
		self::assertNotNull($offered);
		self::assertSame(['404', 'default', 'extra'], $offered);
		self::assertRoundTripsThroughTheFormula($presenter, $offered);
	}

	// Exhaustiveness driven off the constant, not off a literal list: every formula that declares a
	// view axis must have a working inverse, or its file-derived views AND its listing probes both
	// go missing without a symptom.
	public function testEveryViewFormulaOffersAWorkingInversion(): void
	{
		foreach (FormulaVocabulary::VIEW_FORMULAS as $formula) {
			self::assertArrayHasKey(
				$formula,
				self::INVERSION_PROBES,
				"view formula '$formula' has no inversion probe fixture",
			);

			[$className, $presenterName] = self::INVERSION_PROBES[$formula];
			$presenter = self::presenter($className, $presenterName, 'default');

			$offered = self::mirroredOfferedViews($formula, $presenter);
			self::assertNotNull($offered, "view formula '$formula' declares itself uninvertible");
			self::assertNotSame([], $offered, "view formula '$formula' inverts to nothing");
			self::assertRoundTripsThroughTheFormula($presenter, $offered);
		}
	}

	public function testAnUnrecognisedFormulaIsRejectedInsteadOfAnsweringNull(): void
	{
		$presenter = self::presenter(DiscoveryVendorPresenter::class, 'Fixture:DiscoveryVendor', 'default');

		$this->expectException(LogicException::class);
		self::mirroredOfferedViews('no-such-formula', $presenter);
	}

	public function testSamedirSingleIsInvertedByItsOwnPathAlgebra(): void
	{
		$presenter = self::presenter(DiscoveryLocatorPresenter::class, 'Fixture:DiscoveryLocator', 'default');

		self::assertSame(
			['default', 'extra'],
			self::mirroredOfferedViews(FormulaVocabulary::SAMEDIR_SINGLE, $presenter),
		);
	}

	// A formula with no view axis says so instead of answering an empty offer, which would read as
	// "this directory offers no views" and silently narrow the view set.
	public function testFormulasWithNoViewAxisDeclareThemselvesUninvertible(): void
	{
		$presenter = self::presenter(DiscoveryVendorPresenter::class, 'Fixture:DiscoveryVendor', 'default');

		$formulas = array_merge(FormulaVocabulary::CONVENTION_FORMULAS, [FormulaVocabulary::VENDOR_LAYOUT_WALK]);
		foreach ($formulas as $formula) {
			self::assertNull(self::mirroredOfferedViews($formula, $presenter), $formula);
		}
	}

	// VIEW_NAME_PATTERN is a mirrored vendor value, so it is pinned against the vendor guard ITSELF
	// (driven, never re-implemented) over a table that straddles the grammar boundary in both
	// directions: a vendor upgrade that loosens or tightens the action grammar fails here.
	public function testViewNamePatternAcceptsExactlyWhatTheVendorActionGuardAccepts(): void
	{
		$names = [
			'default',
			'detail',
			'404',
			'0',
			'my_view',
			'ViewName',
			'a1_2',
			// First byte ASCII, tail in the \x7f-\xff range the grammar allows.
			"a\u{011B}",
			'',
			'_private',
			'my-view',
			'my.view',
			'my view',
			'@layout',
			'view-',
			// Leading multibyte, and a trailing newline the #D modifier must reject.
			"\u{011B}sc",
			"default\n",
		];

		foreach ($names as $name) {
			self::assertSame(
				self::vendorAcceptsActionName($name),
				preg_match(FormulaVocabulary::VIEW_NAME_PATTERN, $name) === 1,
				'VIEW_NAME_PATTERN disagrees with the vendor action guard on ' . var_export($name, true),
			);
		}
	}

	public function testDirnameLcfirstMatchesTheLegacyControlReplica(): void
	{
		$control = new DiscoveryLegacyPathControl();

		self::assertSame(
			self::invokeLocator($control, 'getTemplateFilePath'),
			FormulaVocabulary::conventionCandidate(
				FormulaVocabulary::DIRNAME_LCFIRST,
				self::classFile($control),
				'DiscoveryLegacyPathControl',
			),
		);
	}

	public function testDirnameTemplatesLcfirstMatchesTheTemplatesControlReplica(): void
	{
		$control = new DiscoveryTemplatesPathControl();

		self::assertSame(
			self::invokeLocator($control, 'getTemplateFilePath'),
			FormulaVocabulary::conventionCandidate(
				FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST,
				self::classFile($control),
				'DiscoveryTemplatesPathControl',
			),
		);
	}

	// The property value is restated as a literal here, exactly as the class-named rows restate the
	// short class name: reading it back off the fixture would make the row agree with any default.
	public function testDirnamePropertyLcfirstTakesTheNameFromThePropertyDefault(): void
	{
		$control = new DiscoveryPropertyNameControl();

		self::assertSame(
			self::invokeLocator($control, 'createFileName'),
			FormulaVocabulary::conventionPropertyCandidate(self::classFile($control), 'default'),
		);
	}

	// The null-property row is what separates this formula from dirname-lcfirst: the derived name is
	// the DIRECTORY basename, so a fixture whose directory is not named after its class disagrees
	// with the class-named formula - asserted, not left to inspection.
	public function testDirnamePropertyLcfirstDerivesTheDirectoryBasenameWhenThePropertyIsNull(): void
	{
		$control = new DiscoveryPropertyNameNullControl();
		$classFile = self::classFile($control);
		$candidate = FormulaVocabulary::conventionPropertyCandidate($classFile, null);

		self::assertSame(self::invokeLocator($control, 'createFileName'), $candidate);
		self::assertNotSame(
			FormulaVocabulary::conventionCandidate(
				FormulaVocabulary::DIRNAME_LCFIRST,
				$classFile,
				'DiscoveryPropertyNameNullControl',
			),
			$candidate,
		);
	}

	public function testFallbackReplicaSelectsTheSharedFallbackWhenTheDerivedPathIsMissing(): void
	{
		$control = new DiscoveryFallbackSharedControl();
		[$derived, $fallback] = self::mirroredFallbackPair(
			$control,
			'DiscoveryFallbackSharedControl',
			'@fixtureShared.latte',
		);

		self::assertFalse(is_file($derived));
		self::assertTrue(is_file($fallback));
		self::assertSame(
			self::mirroredFallbackSelection($derived, $fallback),
			self::invokeLocator($control, 'getTemplateFilePath'),
		);
	}

	public function testFallbackReplicaSelectsTheDerivedPathWhenItExists(): void
	{
		$control = new DiscoveryFallbackDerivedControl();
		[$derived, $fallback] = self::mirroredFallbackPair(
			$control,
			'DiscoveryFallbackDerivedControl',
			'@fixtureShared.latte',
		);

		// Both exist - pins the gate order (derived wins over the also-existing shared fallback).
		self::assertTrue(is_file($derived));
		self::assertTrue(is_file($fallback));
		self::assertSame(
			self::mirroredFallbackSelection($derived, $fallback),
			self::invokeLocator($control, 'getTemplateFilePath'),
		);
	}

	public function testFallbackReplicaDefaultsToTheDerivedPathWhenNeitherExists(): void
	{
		$control = new DiscoveryFallbackMissingControl();
		[$derived, $fallback] = self::mirroredFallbackPair(
			$control,
			'DiscoveryFallbackMissingControl',
			'@fixtureMissing.latte',
		);

		self::assertFalse(is_file($derived));
		self::assertFalse(is_file($fallback));
		self::assertSame(
			self::mirroredFallbackSelection($derived, $fallback),
			self::invokeLocator($control, 'getTemplateFilePath'),
		);
	}

	/**
	 * @param list<string> $offered
	 */
	private static function assertRoundTripsThroughTheFormula(Presenter $presenter, array $offered): void
	{
		$viewProperty = new ReflectionProperty(Presenter::class, 'view');
		$viewProperty->setAccessible(true);

		foreach ($offered as $view) {
			$viewProperty->setValue($presenter, $view);

			self::assertContains(
				true,
				array_map(static fn (string $path): bool => is_file($path), $presenter->formatTemplateFiles()),
				"reverse-enumerated view '$view' formats back to no existing file",
			);
		}
	}

	// The guard is DRIVEN, not re-implemented: initGlobalParameters() is invoked on a real presenter
	// carrying the name as its request's action parameter, so acceptance is whatever
	// vendor/nette/application/src/Application/UI/Presenter.php decides today. It error()s - i.e.
	// throws BadRequestException - on rejection, and reaches changeAction() otherwise, which is why
	// the dispatched action name is the acceptance proof.
	private static function vendorAcceptsActionName(string $name): bool
	{
		$presenter = PresenterFactory::inject(new DiscoveryVendorPresenter());

		$requestProperty = new ReflectionProperty(Presenter::class, 'request');
		$requestProperty->setAccessible(true);
		$requestProperty->setValue(
			$presenter,
			new Request('Fixture:DiscoveryVendor', 'GET', ['action' => $name]),
		);

		$initGlobalParameters = new ReflectionMethod(Presenter::class, 'initGlobalParameters');
		$initGlobalParameters->setAccessible(true);
		try {
			$initGlobalParameters->invoke($presenter);
		} catch (BadRequestException $e) {
			return false;
		}

		self::assertSame($name, $presenter->getAction());

		return true;
	}

	/**
	 * @param object $control
	 * @return array{string, string}
	 */
	private static function mirroredFallbackPair($control, string $shortClassName, string $sharedFallback): array
	{
		$declaringFile = (new ReflectionMethod($control, 'getTemplateFilePath'))->getFileName();
		self::assertNotFalse($declaringFile);

		return [
			FormulaVocabulary::conventionCandidate(
				FormulaVocabulary::DIRNAME_TEMPLATES_LCFIRST,
				self::classFile($control),
				$shortClassName,
			),
			FormulaVocabulary::conventionFallbackCandidate($declaringFile, $sharedFallback),
		];
	}

	// The replica's own gate, spelled with the mirror's pair - the runtime returns the derived path
	// when it is a file, else the existing shared fallback, else the derived path anyway.
	private static function mirroredFallbackSelection(string $derived, string $fallback): string
	{
		return !is_file($derived) && is_file($fallback) ? $fallback : $derived;
	}

	/**
	 * @param FormulaVocabulary::VENDOR_TWO_CANDIDATE|FormulaVocabulary::SAMEDIR_SINGLE $formula
	 * @return list<string>
	 */
	private static function mirroredViewCandidates(string $formula, Presenter $presenter, string $view): array
	{
		[, $presenterSegment] = Helpers::splitName(self::nameOf($presenter));

		return FormulaVocabulary::viewCandidates(
			$formula,
			self::classFile($presenter),
			$presenterSegment,
			$view,
			static fn (string $path): bool => is_dir($path),
		);
	}

	/**
	 * @return list<string>|null
	 */
	private static function mirroredOfferedViews(string $formula, Presenter $presenter): ?array
	{
		[, $presenterSegment] = Helpers::splitName(self::nameOf($presenter));

		return FormulaVocabulary::offeredViews(
			$formula,
			self::classFile($presenter),
			$presenterSegment,
			static fn (string $path): bool => is_dir($path),
			static fn (string $path): array => TemplateDirectoryListing::names($path),
		);
	}

	/**
	 * @return list<string>
	 */
	private static function mirroredLayoutCandidates(Presenter $presenter): array
	{
		[$module, $presenterSegment] = Helpers::splitName(self::nameOf($presenter));

		return FormulaVocabulary::layoutCandidates(
			self::classFile($presenter),
			$module,
			$presenterSegment,
			static fn (string $path): bool => is_dir($path),
		);
	}

	/**
	 * @template T of Presenter
	 * @param class-string<T> $className
	 * @return T
	 */
	private static function presenter(string $className, string $name, string $view): Presenter
	{
		$presenter = new $className();

		$nameProperty = new ReflectionProperty(Component::class, 'name');
		$nameProperty->setAccessible(true);
		$nameProperty->setValue($presenter, $name);

		$viewProperty = new ReflectionProperty(Presenter::class, 'view');
		$viewProperty->setAccessible(true);
		$viewProperty->setValue($presenter, $view);

		return $presenter;
	}

	private static function nameOf(Presenter $presenter): string
	{
		$name = $presenter->getName();
		self::assertNotNull($name);

		return $name;
	}

	/**
	 * @param object $instance
	 */
	private static function classFile($instance): string
	{
		$file = (new ReflectionClass($instance))->getFileName();
		self::assertNotFalse($file);

		return $file;
	}

	/**
	 * @param object $control
	 */
	private static function invokeLocator($control, string $methodName): string
	{
		$method = new ReflectionMethod($control, $methodName);
		$method->setAccessible(true);
		$path = $method->invoke($control);
		self::assertIsString($path);

		return $path;
	}

}
