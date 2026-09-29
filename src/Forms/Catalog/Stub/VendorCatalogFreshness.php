<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Catalog\Stub;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use ReflectionClass;
use ReflectionException;
use function array_key_exists;
use function count;
use function dirname;
use function explode;
use function is_array;
use function is_file;
use function ltrim;
use function preg_match;
use function preg_replace;
use function realpath;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function token_get_all;
use function trim;
use const T_CLASS;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_FUNCTION;
use const T_INTERFACE;
use const T_NAMESPACE;
use const T_STRING;
use const T_WHITESPACE;

/**
 * The freshness gate over everything this extension copied out of, or keyed off, Nette's own source.
 *
 * Two things rot when nette/forms is upgraded, and both rot SILENTLY:
 *
 *  - control-value-types.stub REDECLARES vendor control classes to carry their form tags, and a
 *    PHPStan stub does not merge with the docblock it redeclares, it REPLACES it. Every vendor
 *    property or method tag on a stubbed class is therefore dropped unless the stub restates it —
 *    as already happened once here, to ChoiceControl's declared items property. A vendor upgrade
 *    that ADDS such a tag drops it again, silently.
 *  - FormValueTypeCatalog is keyed by Nette\Forms\Container's factory METHOD NAMES, and the control
 *    class behind each of them is read live off that method's declared return type. A factory Nette
 *    renames, drops, or stops giving a control return type turns its catalog entry inert; nothing
 *    else in the tree would notice.
 *
 * Both sources are read as SOURCE TEXT. Native reflection is used only to LOCATE a vendor file (and
 * the result is required to sit inside the vendor package), never to answer a docblock question:
 * once a stub is applied, PHPStan's reflection answers with the STUB's docblock, so a check that
 * asked reflection would compare the stub against itself and could never fail.
 *
 * What is deliberately NOT checked: a vendor factory the catalog does not list. Nette gaining an
 * add* method is a feature request, not a stale copy — the control degrades honestly and failing a
 * routine `composer update` over it would train the reader to ignore this gate.
 */
final class VendorCatalogFreshness
{

	public const STUB = 'stubs/forms-control-value-types.stub';

	public const CATALOG = 'src/Forms/Catalog/Stub/FormValueTypeCatalog.php';

	public const PACKAGE = 'nette/forms';

	public const FORMS_CONTAINER = 'Nette\Forms\Container';

	public const STUB_FIX_HINT = 'Restate the tag on the redeclared class in ' . self::STUB
		. ' (its type may differ from the vendor one deliberately — only the name is gated), then re-run this check.';

	public const CATALOG_FIX_HINT = 'Re-key the entry in ' . self::CATALOG
		. ' to the factory Nette declares now, or drop it, then re-run this check.';

	/** Member tags whose loss a redeclaring stub causes, and the group its NAME is captured in. */
	private const MEMBER_TAGS = '~^@(property-read|property-write|property|method)\b[^$]*(?:\$(\w+)|\b(\w+)\s*\()~';

	/**
	 * Where the files being GATED are read from. Defaults to the project itself; a test points it at
	 * a throwaway copy so a mutation can be introduced without touching the real tree.
	 */
	private string $root;

	/**
	 * Where the gated vendor source is read from. Defaults to the installed package; a test points
	 * it at a throwaway copy, like the root.
	 */
	private ?string $packageRoot;

	public function __construct(?string $root = null, ?string $packageRoot = null)
	{
		$this->root = $this->normalize($root ?? self::projectRoot());
		$packageRoot ??= self::installedPackageRoot();
		$this->packageRoot = $packageRoot !== null ? $this->normalize($packageRoot) : null;
	}

	/** The real project root, which is where a class is LOCATED even when contents are read elsewhere. */
	private static function projectRoot(): string
	{
		return dirname(__DIR__, 4);
	}

	private static function installedPackageRoot(): ?string
	{
		return ProjectInstalledVersions::get()->getInstallPath(self::PACKAGE);
	}

	/**
	 * Every way the extension's vendor-derived facts no longer match the installed vendor source,
	 * one message per divergence. An empty list is the pass.
	 *
	 * @return list<string>
	 */
	public function drifts(): array
	{
		return [...$this->stubDrifts(), ...$this->catalogDrifts()];
	}

	/**
	 * @return list<string>
	 */
	private function stubDrifts(): array
	{
		$stub = $this->declarations($this->root . '/' . self::STUB);
		if ($stub === null) {
			return [sprintf('Cannot read %s — is the project installed?', self::STUB)];
		}

		$drifts = [];
		foreach ($stub as $className => $declaration) {
			$file = $this->vendorFileOf($className);
			if ($file === null) {
				$drifts[] = sprintf(
					"%s redeclares %s, which %s no longer declares.\n  %s",
					self::STUB,
					$className,
					self::PACKAGE,
					self::STUB_FIX_HINT,
				);

				continue;
			}

			$vendor = $this->declarations($this->packageRoot . '/' . $file);
			$ours = $this->memberTags($declaration['doc']);

			foreach ($this->memberTags($vendor[$className]['doc'] ?? null) as $tag => $ignored) {
				if (array_key_exists($tag, $ours)) {
					continue;
				}

				$drifts[] = sprintf(
					"%s declares %s on %s and %s does not restate it, so redeclaring the class drops it.\n  %s",
					self::PACKAGE . '/' . $file,
					$tag,
					$className,
					self::STUB,
					self::STUB_FIX_HINT,
				);
			}
		}

		return $drifts;
	}

	/**
	 * @return list<string>
	 */
	private function catalogDrifts(): array
	{
		$catalog = $this->declarations($this->root . '/' . self::CATALOG);
		$containerFile = $this->vendorFileOf(self::FORMS_CONTAINER);
		if ($catalog === null || $containerFile === null) {
			return [
				sprintf(
					'Cannot read %s or the installed %s — is the project installed?',
					self::CATALOG,
					self::PACKAGE,
				),
			];
		}

		$vendor = $this->declarations($this->packageRoot . '/' . $containerFile);
		$factories = $vendor[self::FORMS_CONTAINER]['methods'] ?? [];
		$entries = $catalog[FormValueTypeCatalog::class]['methods'] ?? [];

		$drifts = [];
		if (count($entries) === 0) {
			$drifts[] = sprintf(
				'%s declares no factory at all — the whole vendor value catalog is gone.',
				self::CATALOG,
			);
		}

		foreach ($entries as $method => $ignored) {
			if (!array_key_exists($method, $factories)) {
				$drifts[] = sprintf(
					"%s types %s::%s(), which %s no longer declares.\n  %s",
					self::CATALOG,
					self::FORMS_CONTAINER,
					$method,
					self::PACKAGE . '/' . $containerFile,
					self::CATALOG_FIX_HINT,
				);

				continue;
			}

			$returnType = $factories[$method];
			if (preg_match('~(?:^|\\\\)Controls\\\\\w+$~', $returnType) === 1) {
				continue;
			}

			$drifts[] = sprintf(
				"%s::%s() no longer returns a Nette\\Forms\\Controls class (%s), so the control class behind the catalog entry can no longer be read from it.\n  %s",
				self::FORMS_CONTAINER,
				$method,
				$returnType === '' ? 'no declared return type' : $returnType,
				self::CATALOG_FIX_HINT,
			);
		}

		return $drifts;
	}

	/**
	 * The tag names a docblock declares for MEMBERS — the ones a redeclaring stub silently drops.
	 * Keyed by tag kind and name so only the NAME is gated: the stub retypes ChoiceControl's items
	 * deliberately (`array<int|string, mixed>` where vendor writes a bare `array`) and must be free
	 * to keep doing so.
	 *
	 * @return array<string, true>
	 */
	private function memberTags(?string $doc): array
	{
		$tags = [];
		foreach ($this->docLines($doc) as $line) {
			if (preg_match(self::MEMBER_TAGS, $line, $m) !== 1) {
				continue;
			}

			$tags[$m[1] . ' ' . (($m[2] ?? '') !== '' ? '$' . $m[2] : ($m[3] ?? '') . '()')] = true;
		}

		return $tags;
	}

	/**
	 * @return list<string>
	 */
	private function docLines(?string $doc): array
	{
		if ($doc === null) {
			return [];
		}

		$lines = [];
		foreach (explode("\n", $doc) as $rawLine) {
			$line = trim($rawLine);
			$line = trim((string) preg_replace('~^/\*\*+~', '', $line));
			$line = trim((string) preg_replace('~\*/$~', '', $line));
			$line = trim(ltrim($line, '*'));
			$line = trim((string) preg_replace('~\s+~', ' ', $line));

			if ($line !== '') {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Where the installed vendor package declares $className, as a package-relative path — or null
	 * when it declares it nowhere.
	 *
	 * Native reflection LOCATES the file, which is a question about the filesystem and not about
	 * docblocks: reflection is never asked what the class SAYS, because a PHPStan stub would answer
	 * that with itself. The located path is then required to sit inside the vendor package, so a
	 * stub of ours can never be mistaken for the source it copies.
	 */
	private function vendorFileOf(string $className): ?string
	{
		try {
			// @phpstan-ignore argument.type (the name is read off a class declaration in a source file, and ReflectionClass refusing it IS the answer this asks for)
			$reflection = new ReflectionClass($className);
		} catch (ReflectionException $exception) {
			return null;
		}

		$file = $reflection->getFileName();
		$installed = self::installedPackageRoot();
		if ($file === false || $installed === null) {
			return null;
		}

		$prefix = $this->normalize($installed) . '/';
		$located = $this->normalize($file);

		return strpos($located, $prefix) === 0
			? (string) substr($located, strlen($prefix))
			: null;
	}

	private function normalize(string $path): string
	{
		$real = realpath($path);

		return str_replace('\\', '/', $real !== false ? $real : $path);
	}

	/**
	 * Every class/interface a file declares: its docblock, and its methods' declared return types.
	 *
	 * A tokeniser rather than a parser on purpose — this is a `make` target of its own and runs
	 * before anything is bootstrapped, and a docblock, a name and a return type are exactly what the
	 * token stream hands over.
	 *
	 * @return array<string, array{doc: string|null, methods: array<string, string>}>|null
	 */
	private function declarations(string $file): ?array
	{
		if (!is_file($file)) {
			return null;
		}

		$tokens = token_get_all(FileSystem::read($file));
		$count = count($tokens);

		$docs = [];
		$methods = [];
		$namespace = '';
		$currentClass = null;
		$pendingDoc = null;
		$depth = 0;
		$parens = 0;

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			$id = is_array($token) ? $token[0] : -1;
			$text = is_array($token) ? $token[1] : $token;

			if ($id === T_WHITESPACE || $id === T_COMMENT) {
				continue;
			}

			if ($id === T_DOC_COMMENT) {
				$pendingDoc = $text;

				continue;
			}

			if ($text === '(') {
				$parens++;

				continue;
			}

			if ($text === ')') {
				$parens--;

				continue;
			}

			if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$depth++;
				$pendingDoc = null;

				continue;
			}

			if ($text === '}') {
				$depth--;
				$pendingDoc = null;

				continue;
			}

			if ($id === T_NAMESPACE && $depth === 0) {
				$namespace = $this->readQualifiedName($tokens, $count, $i + 1);

				continue;
			}

			if (($id === T_CLASS || $id === T_INTERFACE) && $depth === 0) {
				$name = $this->readName($tokens, $count, $i + 1);
				$currentClass = $namespace === '' ? $name : $namespace . '\\' . $name;
				$docs[$currentClass] = $pendingDoc;
				$methods[$currentClass] = [];
				$pendingDoc = null;

				continue;
			}

			if ($id === T_FUNCTION && $depth === 1 && $parens === 0 && $currentClass !== null) {
				[$method, $returnType, $i] = $this->readMethod($tokens, $count, $i);
				if ($method !== null) {
					$methods[$currentClass][$method] = $returnType;
				}

				$pendingDoc = null;

				continue;
			}

			if ($text === ';') {
				$pendingDoc = null;
			}
		}

		$declarations = [];
		foreach ($docs as $className => $doc) {
			$declarations[$className] = ['doc' => $doc, 'methods' => $methods[$className]];
		}

		return $declarations;
	}

	/**
	 * @param list<array{int, string, int}|string> $tokens
	 */
	private function readName(array $tokens, int $count, int $start): string
	{
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			if (is_array($token) && $token[0] === T_WHITESPACE) {
				continue;
			}

			return is_array($token) ? $token[1] : '';
		}

		return '';
	}

	/**
	 * A namespace name, which PHP 7.4 tokenises one segment at a time and PHP 8 as a single token —
	 * accumulated either way, because the gate runs on both.
	 *
	 * @param list<array{int, string, int}|string> $tokens
	 */
	private function readQualifiedName(array $tokens, int $count, int $start): string
	{
		$name = '';
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			$id = is_array($token) ? $token[0] : -1;
			$text = is_array($token) ? $token[1] : $token;

			if ($id === T_WHITESPACE) {
				continue;
			}

			if ($text === ';' || $text === '{') {
				break;
			}

			$name .= $text;
		}

		return trim($name, '\\');
	}

	/**
	 * A method's name and the raw text of its declared return type (empty when it declares none),
	 * plus the index the caller resumes at.
	 *
	 * @param list<array{int, string, int}|string> $tokens
	 * @return array{string|null, string, int}
	 */
	private function readMethod(array $tokens, int $count, int $start): array
	{
		$name = null;
		$parens = 0;
		$returnType = '';
		$afterParams = false;

		for ($i = $start + 1; $i < $count; $i++) {
			$token = $tokens[$i];
			$id = is_array($token) ? $token[0] : -1;
			$text = is_array($token) ? $token[1] : $token;

			if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
				continue;
			}

			if ($name === null) {
				if ($id === T_STRING) {
					$name = $text;
				}

				continue;
			}

			if ($text === '(') {
				$parens++;

				continue;
			}

			if ($text === ')') {
				$parens--;
				$afterParams = $parens === 0;

				continue;
			}

			if ($parens > 0) {
				continue;
			}

			if ($text === '{' || $text === ';') {
				return [$name, trim($returnType), $i - 1];
			}

			if ($afterParams && $text !== ':') {
				$returnType .= $text;
			}
		}

		return [$name, trim($returnType), $count];
	}

}
