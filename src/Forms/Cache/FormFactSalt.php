<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use Nette\Utils\FileSystem;
use PHPStan\File\FileFinder;
use Throwable;
use function array_keys;
use function implode;
use function is_array;
use function is_file;
use function preg_replace;
use function sha1;
use function sha1_file;
use function sort;
use function strncmp;
use function strpos;
use function token_get_all;
use const T_COMMENT;
use const T_CONST;
use const T_DOC_COMMENT;
use const T_STRING;
use const T_UNSET;
use const T_WHITESPACE;

/**
 * A content digest of the form-fact-bearing SUBSET of the analysed universe, in a normalisation
 * that ignores everything a form shape cannot depend on.
 *
 * This exists because the Forms extension derives cross-file facts from method BODIES while
 * PHPStan's result cache propagates a changed file to its dependents only when that file's
 * EXPORTED nodes move (ResultCacheManager::restore(), the `exportedNodesChanged() === null`
 * branch). A field added to, removed from or retyped inside a createComponentX()/constructor body
 * therefore never re-queues the consumer that reads the resulting shape from another file, and a
 * warm run keeps a verdict its inputs no longer support — including missing real errors. The
 * consumers are type-inference extensions that must answer during per-file analysis, so none of
 * them can be deferred to the aggregate stage the way a pure diagnostic can; a
 * ResultCacheMetaExtension over this digest is the only in-contract channel left.
 *
 * The same is true one hop further out: a constant's own exported node moves when its VALUE is
 * edited, so PHPStan re-queues the file that FETCHES it — but not that file's dependents, which is
 * where the consumer of the resulting shape lives. Hence the subset is not "files carrying a
 * marker" alone; see classify().
 *
 * Two properties make the coarseness affordable rather than merely correct:
 *
 *  - the SUBSET. Only files whose source carries at least one shape-affecting construct
 *    (see isMarker()) or declares a class constant one of them reads (see classify()) enter the
 *    digest at all, so editing anything else leaves the result cache intact. Measured on this
 *    project: 1093 of the 4003 files PHPStan analyses (27.3%).
 *  - the NORMALISATION. The digest is taken over the token stream with whitespace and `//`/`#`
 *    comments dropped and doc-comment whitespace collapsed, so reformatting or commenting a
 *    form-bearing file does not discard the cache either. Doc comments themselves stay IN: `@form-*`
 *    annotations (see the catalog) and PHPDoc types are genuine shape inputs.
 *
 * The subset predicate over-approximates on purpose — a construct wrongly included costs one
 * needless invalidation, a construct wrongly excluded costs the invariant. What it cannot do is
 * anticipate a shape input the extension learns to read AFTER this vocabulary was written, which
 * is why FormFactSaltTest pins the classification and drift-checks the vocabulary against the
 * extension's own recognised method names rather than trusting this list to stay current.
 *
 * The whole-universe scan is content-addressed by the same universe manifest RegistrationIndex
 * uses, so a run in which no file changed pays only the sha1_file sweep (~0.05s) and one blob
 * read; the ~1.2s scan is paid once per changed universe, in the main process.
 */
final class FormFactSalt
{

	/**
	 * Method names that move a resolved shape without being an `add*` call: the component-model
	 * mutators and the value-projection mutators the extension reads (setOmitted drops a field from
	 * getValues(), setNullable/setMappedType/setItems retype it, setParent/removeComponent/
	 * offsetUnset re-parent or drop a component, monitor makes a constructor non-inert, createOne
	 * is the replicator's item factory).
	 */
	private const MARKER_NAMES = [
		'createOne' => true,
		'monitor' => true,
		'offsetSet' => true,
		'offsetUnset' => true,
		'removeComponent' => true,
		'setDisabled' => true,
		'setItems' => true,
		'setMappedType' => true,
		'setNullable' => true,
		'setOmitted' => true,
		'setParent' => true,
		'setRequired' => true,
	];

	/**
	 * Method-name PREFIXES that declare a component: createComponentX() is Nette's factory
	 * convention and createStepN() is the wizard's (ControlAnnotationValueTypeReader's default
	 * step prefix). Values are the prefix lengths, so the scan spells no strlen() per token.
	 */
	private const MARKER_PREFIXES = [
		'createComponent' => 15,
		'createStep' => 10,
	];

	/** @var list<string> */
	private array $analysedPaths;

	private FileFinder $fileFinder;

	private ?FormShapeCache $cache;

	private ?string $salt = null;

	/**
	 * The universe is enumerated here rather than borrowed from RegistrationIndex, which enumerates
	 * the identical set: this class answers at result-cache-RESTORE time, before any analysis, and
	 * reaching into the index would pull its whole registration fold into that moment.
	 *
	 * @param list<string> $analysedPaths the config's declared paths, never PHPStan's CLI-narrowed
	 * analysedPaths — the same universe source RegistrationIndex folds, so a single-file/IDE run
	 * computes the identical salt a full run does instead of churning the whole cache
	 */
	public function __construct(array $analysedPaths, FileFinder $fileFinder, ?FormShapeCache $cache = null)
	{
		$this->analysedPaths = $analysedPaths;
		$this->fileFinder = $fileFinder;
		// Production wires the shared cache so the scan persists once per universe manifest; direct
		// unit construction omits it to drive the raw scan.
		$this->cache = $cache;
	}

	public function get(): string
	{
		if ($this->salt !== null) {
			return $this->salt;
		}

		$hashes = [];
		foreach ($this->enumerateUniverse() as $file) {
			$fileHash = is_file($file) ? sha1_file($file) : false;
			$hashes[$file] = $fileHash === false ? sha1($file) : $fileHash;
		}

		$manifest = $this->manifest($hashes);

		return $this->salt = $this->cache === null
			? $this->scan($hashes)
			: $this->cache->rememberFormFactSalt($manifest, fn (): string => $this->scan($hashes));
	}

	/**
	 * The files this digest is taken over — the fact-bearing subset itself. Public so the predicate
	 * can be pinned by a test over a fixture corpus: a silent shrink here is a silent return of the
	 * invalidation hole, and nothing else in the tree would notice it.
	 *
	 * @return list<string>
	 */
	public function shapeAffectingFiles(): array
	{
		return array_keys($this->classify($this->enumerateUniverse()));
	}

	/**
	 * @return list<string>
	 */
	private function enumerateUniverse(): array
	{
		$files = $this->fileFinder->findFiles($this->analysedPaths)->getFiles();
		sort($files);

		return $files;
	}

	/**
	 * @param array<string, string> $hashes file => content hash, in enumeration order
	 */
	private function manifest(array $hashes): string
	{
		$parts = [];
		foreach ($hashes as $file => $hash) {
			$parts[] = $file . "\x1f" . $hash;
		}

		return sha1(implode("\n", $parts));
	}

	/**
	 * @param array<string, string> $hashes
	 */
	private function scan(array $hashes): string
	{
		$lines = [];
		foreach ($this->classify(array_keys($hashes)) as $file => $digest) {
			$lines[] = $file . "\x1f" . $digest;
		}

		return sha1(implode("\n", $lines));
	}

	/**
	 * The shape-affecting subset of $files, in enumeration order: file => normalised digest.
	 *
	 * A file is in when it carries a marker construct outright, and ALSO when it declares a class
	 * constant whose NAME a file already in the subset fetches. That second rule is not a
	 * generalisation for its own sake: LiteralNameResolver reads a component NAME out of a
	 * `Foo::CONST` and ChoiceItemKeyResolver reads a choice control's item KEYS out of one, both
	 * recording the constant's declaring file as a dependency of the shape they produce. The
	 * declaring file needs no marker of its own — `final class FieldNames { public const ID = 'id'; }`
	 * is a complete shape input — so without this rule editing that literal moves the shape while
	 * leaving the digest untouched, and the warm run keeps the stale answer.
	 *
	 * Directing it by NAME rather than admitting every `const` is what keeps the subset small: on
	 * this project the blunt form takes the subset from 23.9% of the universe to 52.0%, this one
	 * to 27.3%.
	 *
	 * The rule is applied to a fixed point, so `const OUTER = Other::INNER` carries Other's file in
	 * as well; the fetching file is always in the subset first, because a constant can only reach a
	 * shape through an add*()/offsetSet()/factory construct, all of which are markers.
	 *
	 * @param list<string> $files
	 * @return array<string, string>
	 */
	private function classify(array $files): array
	{
		$facts = [];
		foreach ($files as $file) {
			$fileFacts = $this->fileFacts($file);
			if ($fileFacts !== null) {
				$facts[$file] = $fileFacts;
			}
		}

		$subset = [];
		$wantedNames = [];
		foreach ($facts as $file => $fact) {
			if ($fact['marker']) {
				$subset[$file] = true;
				$wantedNames += $fact['fetched'];
			}
		}

		do {
			$newNames = [];
			foreach ($facts as $file => $fact) {
				if (isset($subset[$file]) || !$this->declaresAny($fact['declared'], $wantedNames)) {
					continue;
				}

				$subset[$file] = true;
				foreach ($fact['fetched'] as $name => $fetched) {
					if (!isset($wantedNames[$name])) {
						$newNames[$name] = $fetched;
					}
				}
			}

			$wantedNames += $newNames;
		} while ($newNames !== []);

		$digests = [];
		foreach ($facts as $file => $fact) {
			if (isset($subset[$file])) {
				$digests[$file] = $fact['digest'];
			}
		}

		return $digests;
	}

	/**
	 * @param array<string, true> $names
	 * @param array<string, true> $wantedNames
	 */
	private function declaresAny(array $names, array $wantedNames): bool
	{
		foreach ($names as $name => $declared) {
			if (isset($wantedNames[$name])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Everything one file contributes to the classification: the digest of its normalised token
	 * stream, whether it carries a shape-affecting construct outright, and the class-constant names
	 * it declares and fetches. Null when the file can never enter the subset under any fold — no
	 * marker and no constant declaration — which is most of the universe, and which is why those
	 * files are never hashed.
	 *
	 * @return array{digest: string, marker: bool, declared: array<string, true>, fetched: array<string, true>}|null
	 */
	private function fileFacts(string $file): ?array
	{
		if (!is_file($file)) {
			return null;
		}

		try {
			$source = FileSystem::read($file);
		} catch (Throwable $exception) {
			return null;
		}

		// Silenced: the universe can hold a fixture written for a newer PHP than the one running
		// the analysis, and a tokeniser notice on stderr would corrupt an otherwise clean run. A
		// file the tokeniser cannot make sense of simply yields no markers.
		$tokens = @token_get_all($source);

		$parts = [];
		$previousText = '';
		$isMarked = false;
		$declared = [];
		$fetched = [];
		$inConstantDeclaration = false;
		$expectConstantName = false;
		$depth = 0;

		foreach ($tokens as $token) {
			$id = is_array($token) ? $token[0] : -1;
			$text = is_array($token) ? $token[1] : $token;

			if ($id === T_WHITESPACE || $id === T_COMMENT) {
				continue;
			}

			if ($id === T_DOC_COMMENT) {
				// Doc comments stay in the digest (`@form-*` annotations and PHPDoc types are shape
				// inputs) but their internal layout does not, so reindenting one keeps the cache.
				$text = (string) preg_replace('~\s+~', ' ', $text);
			}

			if (!$isMarked && $this->isMarker($id, $text, $previousText)) {
				$isMarked = true;
			}

			if ($id === T_STRING && $previousText === '::') {
				$fetched[$text] = true;
			}

			if ($id === T_CONST) {
				// `use const Foo\BAR;` imports one, it does not declare one.
				$inConstantDeclaration = $previousText !== 'use';
				$expectConstantName = $inConstantDeclaration;
				$depth = 0;
			} elseif ($inConstantDeclaration) {
				// Depth keeps the `,` of `const MAP = [1, 2];` from being read as the separator of
				// a `const A = 1, B = 2;` list, whose second name would otherwise be missed.
				if ($text === '(' || $text === '[' || $text === '{') {
					$depth++;
				} elseif ($text === ')' || $text === ']' || $text === '}') {
					$depth--;
				} elseif ($depth === 0) {
					if ($text === ';') {
						$inConstantDeclaration = false;
					} elseif ($text === ',') {
						$expectConstantName = true;
					} elseif ($text === '=') {
						$expectConstantName = false;
					} elseif ($expectConstantName && $id === T_STRING) {
						// Every T_STRING before the `=`, not just the first: a typed class constant
						// (`const string ID = 'id';`) puts the type there too, and admitting both
						// names errs on the side of one needless invalidation.
						$declared[$text] = true;
					}
				}
			}

			$parts[] = $id . "\x1f" . $text;
			$previousText = $text;
		}

		if (!$isMarked && $declared === []) {
			return null;
		}

		return [
			'digest' => sha1(implode("\x1e", $parts)),
			'marker' => $isMarked,
			'declared' => $declared,
			'fetched' => $fetched,
		];
	}

	// Compared on token TEXT rather than token id wherever an id would have to be spelled as a
	// version-dependent constant (T_NULLSAFE_OBJECT_OPERATOR does not exist on PHP 7.4, which is
	// what runs the analysis here) - the texts are fixed by the grammar and cannot drift.
	private function isMarker(int $id, string $text, string $previousText): bool
	{
		if ($id === T_UNSET) {
			return true;
		}

		if ($id === T_DOC_COMMENT) {
			return strpos($text, '@form') !== false;
		}

		// `] =` is the offsetSet grammar ($form['x'] = $control, $form->onSuccess[] = ...) that
		// ComponentAffectingNodeVisitor tags.
		if ($id === -1) {
			return $text === '=' && $previousText === ']';
		}

		if ($id !== T_STRING) {
			return false;
		}

		if (isset(self::MARKER_NAMES[$text])) {
			return true;
		}

		foreach (self::MARKER_PREFIXES as $prefix => $length) {
			if (strncmp($text, $prefix, $length) === 0) {
				return true;
			}
		}

		if (strncmp($text, 'add', 3) !== 0) {
			return false;
		}

		// An `add*` NAME is not enough - `$address = ...` or `use Foo\Address` would qualify half
		// the tree. Only a call/member access on something can add a component.
		return $previousText === '->' || $previousText === '?->' || $previousText === '::';
	}

}
