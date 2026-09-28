<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge\Discovery;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use function strncmp;
use const DIRECTORY_SEPARATOR;

// Records derive from CHOSEN + EXISTING candidates only, so every record links an analysable
// template; non-existing or non-chosen candidates stay facts-only and are read from the facts
// envelope by the templateMissing consumer, never from the store.

/**
 * @phpstan-import-type DiscoveryRecord from DiscoveryStore
 */
final class DiscoveryRecords
{

	private function __construct()
	{
	}

	/**
	 * @return array<string, list<DiscoveryRecord>>
	 */
	public static function forClass(string $className, PhpRenderFacts $facts): array
	{
		$discovery = $facts->getDiscovery();
		if ($discovery === null) {
			return [];
		}

		$views = $facts->getViews();

		$recordsByTemplate = [];
		foreach ($discovery->getViewCandidates() as $view => $candidates) {
			// PHP coerced a numeric view name (the 404/405 error views) to an int array key on the
			// way in; the store's own record validator accepts a string or null view only.
			$view = (string) $view;
			$viewFact = $views[$view] ?? null;
			// The '' bucket (controls, class-level presenter writes) has no view axis and no
			// ViewFact; per-class facts prove nothing about its reachability - UNKNOWN, the
			// four-state default, never an overclaimed HAPPENS.
			$certainty = $viewFact !== null ? $viewFact->getCertainty() : Certainty::UNKNOWN;

			foreach ($candidates as $candidate) {
				self::append(
					$recordsByTemplate,
					$candidate,
					$className,
					$view === '' ? null : $view,
					$certainty,
				);
			}
		}

		if (self::mayReachALayout($discovery, $facts)) {
			foreach ($discovery->getLayoutCandidates() as $candidate) {
				// The layout channel is view-independent by vendor design; no per-class fact proves
				// when it renders - UNKNOWN, same as the '' bucket.
				self::append($recordsByTemplate, $candidate, $className, null, Certainty::UNKNOWN);
			}
		}

		foreach ($recordsByTemplate as $relPath => $records) {
			$recordsByTemplate[$relPath] = self::deduplicate($records);
		}

		return $recordsByTemplate;
	}

	// Presenter::findLayoutTemplateFile() is called from more than one live site in the vendor tree
	// (UIRuntime::initialize() and the {extends auto}/{layout auto} macro alike), but every one of
	// them sits inside a compiled VIEW TEMPLATE's own initialization. A class that renders no view
	// never executes any of them, and sendTemplate() errors on the missing view first, so a
	// convention layout linked to such a class is unreachable by construction rather than merely
	// unlikely.
	//
	// The two escapes are what keep this from false-closing: an unreadable discovery or an OPEN view
	// set means the view set is UNRESOLVABLE, not empty. Both are live on this corpus - PdfPresenter
	// and Error4xxPresenter each pick their template with a dynamic setFile() - and without them the
	// layout they really do render would be reported orphaned.
	private static function mayReachALayout(DiscoveryFact $discovery, PhpRenderFacts $facts): bool
	{
		return self::rendersAView($discovery)
			|| $discovery->getOpaques() !== []
			|| $facts->hasOpenViewSet();
	}

	// Deliberately read off the CANDIDATES rather than the records appended above: a view candidate
	// that escaped the project root carries no record yet still proves the class renders something.
	private static function rendersAView(DiscoveryFact $discovery): bool
	{
		foreach ($discovery->getViewCandidates() as $candidates) {
			foreach ($candidates as $candidate) {
				if ($candidate->isChosen() && $candidate->exists()) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, list<DiscoveryRecord>> $recordsByTemplate
	 */
	private static function append(
		array &$recordsByTemplate,
		CandidatePath $candidate,
		string $className,
		?string $view,
		string $certainty
	): void
	{
		if (!$candidate->isChosen() || !$candidate->exists()) {
			return;
		}

		// A candidate that escaped the project root stays absolute (CandidatePath's own
		// relativization contract) - no analysable template can carry its record.
		$path = $candidate->getPath();
		if (strncmp($path, DIRECTORY_SEPARATOR, 1) === 0) {
			return;
		}

		$recordsByTemplate[$path][] = [
			'class' => $className,
			'view' => $view,
			'kind' => $candidate->getKind(),
			'certainty' => $certainty,
		];
	}

	/**
	 * @param list<DiscoveryRecord> $records
	 * @return list<DiscoveryRecord>
	 */
	private static function deduplicate(array $records): array
	{
		$deduplicated = [];
		foreach ($records as $record) {
			$key = $record['class'] . "\x00" . ($record['view'] ?? "\x01") . "\x00" . $record['kind']
				. "\x00" . $record['certainty'];
			$deduplicated[$key] = $record;
		}

		$result = [];
		foreach ($deduplicated as $record) {
			$result[] = $record;
		}

		return $result;
	}

}
