<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use InvalidArgumentException;
use Latte\CompileException;
use Latte\MacroTokens;
use Latte\Parser;
use Latte\RegexpException;
use Latte\Token;
use Nette\IOException;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Shape\ComponentPath;
use OriPhpstan\Nette\Latte\Cache\LatteAnalysisCache;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\MacroPairing;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_splice;
use function array_values;
use function count;
use function explode;
use function is_string;
use function preg_match;
use function preg_replace;
use function sha1;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

// Purely syntactic form-macro extraction: every {form}/{formContext}/<form n:name> scope in a
// template, and inside each one every control reference with its {formContainer} path. Nothing is
// resolved - no PHP class is consulted, no discovery record is read - so an argument that is not a
// component-name literal becomes a null name rather than a guess.
final class FormMacroCollector
{

	private const CACHE_NODE_ID = 'latteforms-macros-v2';

	private const HTML_TAG_FORM = 'form';

	private const MACRO_FORM = 'form';

	private const MACRO_FORM_CONTEXT = 'formContext';

	private const MACRO_FORM_CONTAINER = 'formContainer';

	private const MACRO_INPUT = 'input';

	private const MACRO_INPUT_ERROR = 'inputError';

	private const MACRO_LABEL = 'label';

	private const MACRO_IFSET = 'ifset';

	private const ATTRIBUTE_NAME = MacroPairing::N_ATTRIBUTE_PREFIX . 'name';

	private const ATTRIBUTE_LABEL = MacroPairing::N_ATTRIBUTE_PREFIX . self::MACRO_LABEL;

	private const ATTRIBUTE_FORM_CONTAINER = MacroPairing::N_ATTRIBUTE_PREFIX . self::MACRO_FORM_CONTAINER;

	private const ATTRIBUTE_IFSET = MacroPairing::N_ATTRIBUTE_PREFIX . self::MACRO_IFSET;

	private const FRAME_FORM = 'form';

	private const FRAME_CONTAINER = 'container';

	// An existence check over a component of the enclosing form - n:ifset="$form['x']" or
	// {ifset $form['x']}. It opens no scope of its own; it only marks the references it encloses, so
	// a consumer can decline to report a name the author already declared conditional.
	private const FRAME_GUARD = 'guard';

	// A {form}/{formContainer} scope ends at its own closing macro tag (or a bare {/}), an
	// n:name/n:formContainer scope at its host element's closing tag - two different closers over
	// one token stream, so each frame records which one owns it.
	private const CLOSER_MACRO = 'macro';

	private const CLOSER_HTML = 'html';

	// $form[...] / $container[...] - a variable with an offset is the only spelling that can be a
	// component existence check. The variable's own name is not compared: {form X} binds $form, but
	// a nested {formContainer} body may legitimately check through a differently named local.
	private const GUARD_RECEIVER_PATTERN = '~^\\$[a-zA-Z_][a-zA-Z0-9_]*(?=\\s*\\[)~';

	private const GUARD_OFFSET_PATTERN = '~^\\s*\\[\\s*(?:\'([^\']*)\'|"([^"]*)")\\s*\\]~';

	// vendor/latte/latte/src/Latte/Helpers.php::$emptyElements, mirrored rather than referenced
	// because Latte\Helpers is @internal (same treatment TemplateFactExtractor gives Parser::N_PREFIX).
	// Latte\Compiler::processHtmlTagEnd() closes these at their own '>' with no closing tag, so the
	// element nesting tracked below must too or <input n:name> would never pop.
	private const VOID_ELEMENTS = [
		'img' => true, 'hr' => true, 'br' => true, 'input' => true, 'meta' => true, 'area' => true,
		'embed' => true, 'keygen' => true, 'source' => true, 'base' => true, 'col' => true,
		'link' => true, 'param' => true, 'basefont' => true, 'frame' => true, 'isindex' => true,
		'wbr' => true, 'command' => true, 'track' => true,
	];

	private LatteUniverse $universe;

	private ?LatteAnalysisCache $cache;

	/** @var array<string, list<FormSite>> */
	private array $sitesByPath = [];

	/** @var array<string, string>|null */
	private ?array $filesByPath = null;

	public function __construct(LatteUniverse $universe, ?LatteAnalysisCache $cache = null)
	{
		$this->universe = $universe;
		$this->cache = $cache;
	}

	/**
	 * @return list<FormSite>
	 */
	public function sitesFor(string $templateRelPath): array
	{
		if (!isset($this->sitesByPath[$templateRelPath])) {
			$this->sitesByPath[$templateRelPath] = $this->load($templateRelPath);
		}

		return $this->sitesByPath[$templateRelPath];
	}

	/**
	 * @return list<FormSite>
	 */
	private function load(string $templateRelPath): array
	{
		if ($this->filesByPath === null) {
			$map = [];
			foreach ($this->universe->files() as $file) {
				$map[$this->universe->relativePath($file)] = $file;
			}

			$this->filesByPath = $map;
		}

		$file = $this->filesByPath[$templateRelPath] ?? null;
		if ($file === null) {
			return [];
		}

		try {
			$source = FileSystem::read($file);
		} catch (IOException $e) {
			return [];
		}

		if ($this->cache === null) {
			return $this->scan($source);
		}

		/**
		 * @var array{sites: list<array{
		 *     formName: string|null,
		 *     line: int,
		 *     references: list<array{
		 *         kind: ControlReference::KIND_*,
		 *         name: string|null,
		 *         containerPath: list<string>,
		 *         line: int,
		 *         guarded: bool,
		 *     }>,
		 * }>} $entry
		 */
		$entry = $this->cache->rememberContentAddressed(
			sha1($source),
			self::CACHE_NODE_ID,
			fn (): array => ['sites' => $this->scanToArrays($source)],
		);

		$sites = [];
		foreach ($entry['sites'] as $site) {
			$sites[] = FormSite::fromArray($site);
		}

		return $sites;
	}

	/**
	 * @return list<array{formName: string|null, line: int, references: list<array{kind: ControlReference::KIND_*, name: string|null, containerPath: list<string>, line: int, guarded: bool}>}>
	 */
	private function scanToArrays(string $latteSource): array
	{
		$sites = [];
		foreach ($this->scan($latteSource) as $site) {
			$sites[] = $site->toArray();
		}

		return $sites;
	}

	/**
	 * @return list<FormSite>
	 */
	private function scan(string $latteSource): array
	{
		try {
			$tokens = (new Parser())->parse($latteSource);
		} catch (CompileException | RegexpException | InvalidArgumentException $e) {
			return [];
		}

		/** @var list<array{name: string|null, line: int}> $sites */
		$sites = [];

		// References live beside $sites rather than inside them: the walk appends through an index
		// it only knows at runtime, and a nested write like that erases the sites' own key shapes.
		/** @var array<int, list<ControlReference>> $references */
		$references = [];

		/** @var list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames */
		$frames = [];

		// Full HTML element nesting (not just scope-opening elements): a </div> must be matched
		// against the right <div>, and frameBase records how tall the frame stack was before the
		// element's own n:attributes pushed onto it, so closing it truncates back to exactly that.
		/** @var list<array{tag: string, frameBase: int}> $elements */
		$elements = [];

		/** @var array{tag: string, frameBase: int}|null $opening */
		$opening = null;

		// The references this element's own n:attributes have produced so far. n:ifset may sit either
		// side of the n:name it guards, so a guard marks them retroactively as well as prospectively.
		/** @var list<array{site: int, index: int}> $elementRefs */
		$elementRefs = [];

		$depth = 0;

		foreach ($tokens as $index => $token) {
			if ($token->type === Token::HTML_TAG_BEGIN) {
				$opening = null;

				$tag = $this->elementName($token->name);

				if ($tag === '') {
					continue;
				}

				if ($token->closing) {
					$this->closeElement($tag, $elements, $frames);
				} else {
					$opening = ['tag' => $tag, 'frameBase' => count($frames)];
					$elementRefs = [];
				}
			} elseif ($token->type === Token::HTML_ATTRIBUTE_BEGIN) {
				if ($opening !== null) {
					$this->applyAttribute($token, $opening['tag'], $sites, $references, $frames, $elementRefs);
				}
			} elseif ($token->type === Token::HTML_TAG_END) {
				if ($opening !== null) {
					if (strpos($token->text, '/') !== false || isset(self::VOID_ELEMENTS[$opening['tag']])) {
						$this->closeHtmlFrames($frames, $opening['frameBase']);
					} else {
						$elements[] = $opening;
					}

					$opening = null;
				}
			} elseif ($token->type === Token::MACRO_TAG) {
				if ($token->closing) {
					if ($depth > 0 && MacroPairing::closesBody($token)) {
						$depth--;
					}

					while (
						$frames !== []
						&& $frames[count($frames) - 1]['closer'] === self::CLOSER_MACRO
						&& $frames[count($frames) - 1]['depth'] > $depth
					) {
						array_pop($frames);
					}

					continue;
				}

				$opensBody = MacroPairing::opensBody($tokens, (int) $index);
				$this->applyMacro($token, $opensBody, $depth, $sites, $references, $frames);

				if ($opensBody) {
					$depth++;
				}
			}
		}

		$result = [];
		foreach ($sites as $index => $site) {
			$result[] = new FormSite($site['name'], $site['line'], $references[$index] ?? []);
		}

		return $result;
	}

	/**
	 * @param list<array{name: string|null, line: int}> $sites
	 * @param array<int, list<ControlReference>> $references
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 * @param list<array{site: int, index: int}> $elementRefs
	 */
	private function applyAttribute(
		Token $token,
		string $tag,
		array &$sites,
		array &$references,
		array &$frames,
		array &$elementRefs
	): void
	{
		if ($token->name === self::ATTRIBUTE_IFSET) {
			$this->openGuard($token->value, self::CLOSER_HTML, 0, $references, $frames, $elementRefs);

			return;
		}

		if ($token->name === self::ATTRIBUTE_NAME) {
			// FormMacros::macroNameAttr() branches on strtolower($node->htmlNode->name) === 'form':
			// on a <form> it pushes onto $this->global->formsStack (a scope), on anything else it
			// emits end($this->global->formsStack)[name] (a lookup). The host tag is the only
			// discriminator, and it is compared case-insensitively.
			if ($tag === self::HTML_TAG_FORM) {
				$this->openForm($this->firstWord($token->value), $token->line, self::CLOSER_HTML, 0, $sites, $frames);
			} else {
				$this->recordElementRef($elementRefs, $this->addReference(
					ControlReference::KIND_NAME_ATTR,
					$this->firstWord($token->value),
					$token->line,
					$references,
					$frames,
				));
			}
		} elseif ($token->name === self::ATTRIBUTE_LABEL) {
			$this->recordElementRef($elementRefs, $this->addReference(
				ControlReference::KIND_LABEL,
				$this->firstWord($token->value),
				$token->line,
				$references,
				$frames,
			));
		} elseif ($token->name === self::ATTRIBUTE_FORM_CONTAINER) {
			$container = $this->singleWord($token->value);
			$this->recordElementRef($elementRefs, $this->addReference(
				ControlReference::KIND_CONTAINER,
				$container,
				$token->line,
				$references,
				$frames,
			));
			$frames[] = [
				'kind' => self::FRAME_CONTAINER,
				'closer' => self::CLOSER_HTML,
				'depth' => 0,
				'siteIndex' => 0,
				'container' => $container,
				'guard' => null,
			];
		}
	}

	/**
	 * @param list<array{name: string|null, line: int}> $sites
	 * @param array<int, list<ControlReference>> $references
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 */
	private function applyMacro(
		Token $token,
		bool $opensBody,
		int $depth,
		array &$sites,
		array &$references,
		array &$frames
	): void
	{
		// {formContext X} pushes $this->global->uiControl[X] exactly like {form X}, only without
		// rendering the tag - ignoring it would attribute its body's references to the enclosing form.
		if ($token->name === self::MACRO_FORM || $token->name === self::MACRO_FORM_CONTEXT) {
			$this->openForm(
				$this->singleWord($token->value),
				$token->line,
				self::CLOSER_MACRO,
				$depth + 1,
				$sites,
				$frames,
				$opensBody,
			);
		} elseif ($token->name === self::MACRO_IFSET) {
			if ($opensBody) {
				$this->openGuard($token->value, self::CLOSER_MACRO, $depth + 1, $references, $frames, []);
			}
		} elseif ($token->name === self::MACRO_FORM_CONTAINER) {
			$container = $this->singleWord($token->value);
			$this->addReference(ControlReference::KIND_CONTAINER, $container, $token->line, $references, $frames);

			if ($opensBody) {
				$frames[] = [
					'kind' => self::FRAME_CONTAINER,
					'closer' => self::CLOSER_MACRO,
					'depth' => $depth + 1,
					'siteIndex' => 0,
					'container' => $container,
					'guard' => null,
				];
			}
		} elseif ($token->name === self::MACRO_INPUT) {
			$this->addReference(
				ControlReference::KIND_INPUT,
				$this->firstWord($token->value),
				$token->line,
				$references,
				$frames,
			);
		} elseif ($token->name === self::MACRO_INPUT_ERROR) {
			// An argument-less {inputError} echoes the $ʟ_input the preceding {input} left behind
			// (FormMacros::macroInputError()) - it consults no form and names no control, so it is
			// not a reference at all. With an argument it reads one fetchWord() and offsets the form
			// with it verbatim, parts included, so ':' is not a part separator here as it is for
			// {input}/{label}.
			if (trim($token->value) !== '') {
				$this->addReference(
					ControlReference::KIND_INPUT_ERROR,
					$this->singleWord($token->value),
					$token->line,
					$references,
					$frames,
				);
			}
		} elseif ($token->name === self::MACRO_LABEL) {
			$this->addReference(
				ControlReference::KIND_LABEL,
				$this->firstWord($token->value),
				$token->line,
				$references,
				$frames,
			);
		}
	}

	/**
	 * @param list<array{name: string|null, line: int}> $sites
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 */
	private function openForm(
		?string $name,
		int $line,
		string $closer,
		int $depth,
		array &$sites,
		array &$frames,
		bool $opensBody = true
	): void
	{
		$sites[] = ['name' => $name, 'line' => $line];

		if (!$opensBody) {
			return;
		}

		$frames[] = [
			'kind' => self::FRAME_FORM,
			'closer' => $closer,
			'depth' => $depth,
			'siteIndex' => count($sites) - 1,
			'container' => null,
			'guard' => null,
		];
	}

	/**
	 * @param ControlReference::KIND_* $kind
	 * @param array<int, list<ControlReference>> $references
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 * @return array{site: int, index: int}|null
	 */
	private function addReference(string $kind, ?string $name, int $line, array &$references, array $frames): ?array
	{
		$path = [];

		/** @var list<list<string>|null> $guards */
		$guards = [];

		for ($i = count($frames) - 1; $i >= 0; $i--) {
			$frame = $frames[$i];

			if ($frame['kind'] === self::FRAME_GUARD) {
				$guards[] = $frame['guard'];

				continue;
			}

			if ($frame['kind'] === self::FRAME_FORM) {
				$containerPath = array_reverse($path);
				$reference = new ControlReference($kind, $name, $containerPath, $line);
				$references[$frame['siteIndex']][] = self::isGuardedBy($guards, $containerPath, $name)
					? $reference->asGuarded()
					: $reference;

				return [
					'site' => $frame['siteIndex'],
					'index' => count($references[$frame['siteIndex']]) - 1,
				];
			}

			// A dynamic {formContainer $x} makes every name below it unresolvable - the container
			// path could not express it - so the whole scope is dropped, exactly as the design's
			// skip-never-guess rule prescribes. Siblings outside it stay recorded.
			if ($frame['container'] === null) {
				return null;
			}

			$path[] = $frame['container'];
		}

		return null;
	}

	/**
	 * @param list<array{site: int, index: int}> $elementRefs
	 * @param array{site: int, index: int}|null $location
	 */
	private function recordElementRef(array &$elementRefs, ?array $location): void
	{
		if ($location !== null) {
			$elementRefs[] = $location;
		}
	}

	/**
	 * @param array<int, list<ControlReference>> $references
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 * @param list<array{site: int, index: int}> $elementRefs
	 */
	private function openGuard(
		string $args,
		string $closer,
		int $depth,
		array &$references,
		array &$frames,
		array $elementRefs
	): void
	{
		$expression = trim($args);

		// {ifset $var} / {ifset #block} / n:ifset="$item->x" check something that is not a component
		// of the enclosing form, so they guard nothing this collector records.
		if (preg_match(self::GUARD_RECEIVER_PATTERN, $expression) !== 1) {
			return;
		}

		$guard = $this->guardPath($expression);

		$frames[] = [
			'kind' => self::FRAME_GUARD,
			'closer' => $closer,
			'depth' => $depth,
			'siteIndex' => 0,
			'container' => null,
			'guard' => $guard,
		];

		// n:ifset guards its whole element, including the n:name Latte parsed before it.
		foreach ($elementRefs as $location) {
			$siteRefs = $references[$location['site']];
			$reference = $siteRefs[$location['index']];
			if (!self::isGuardedBy([$guard], $reference->getContainerPath(), $reference->getName())) {
				continue;
			}

			$siteRefs[$location['index']] = $reference->asGuarded();
			$references[$location['site']] = array_values($siteRefs);
		}
	}

	// The literal component path an existence check names, relative to the form: $form['a']['b'] and
	// $form['a-b'] both read ['a', 'b'], because Container::getComponent() explodes the offset the same
	// way. Null when the expression is a component check whose path cannot be read (a computed offset,
	// a compound condition) - the conservative reading, which suppresses everything the guard encloses
	// rather than guessing which name it protects.

	/**
	 * @return list<string>|null
	 */
	private function guardPath(string $expression): ?array
	{
		$rest = trim((string) preg_replace(self::GUARD_RECEIVER_PATTERN, '', $expression, 1));

		$path = [];
		while ($rest !== '') {
			if (preg_match(self::GUARD_OFFSET_PATTERN, $rest, $matches) !== 1) {
				return null;
			}

			$literal = ($matches[2] ?? '') !== '' ? $matches[2] : $matches[1];
			$name = $this->literalName($literal);
			if ($name === null) {
				return null;
			}

			foreach (ComponentPath::split($name) as $segment) {
				$path[] = $segment;
			}

			$rest = trim((string) substr($rest, strlen($matches[0])));
		}

		return $path === [] ? null : $path;
	}

	// A guard covers a reference when it names the same component: the guard's path must be a suffix of
	// the reference's own full path, so {ifset $form['x']} covers {input x} and, inside
	// {formContainer c}, an $x['street'] check covers c-street. A guard naming a DIFFERENT component
	// covers nothing - that is the whole scope of the suppression.

	/**
	 * @param list<list<string>|null> $guards
	 * @param list<string> $containerPath
	 */
	private static function isGuardedBy(array $guards, array $containerPath, ?string $name): bool
	{
		$reference = $name === null ? null : [...$containerPath, ...ComponentPath::split($name)];

		foreach ($guards as $guard) {
			if ($guard === null) {
				return true;
			}

			if ($reference === null || count($guard) > count($reference)) {
				continue;
			}

			if (array_slice($reference, -count($guard)) === $guard) {
				return true;
			}
		}

		return false;
	}

	// <!--, <!DOCTYPE and <? share Token::HTML_TAG_BEGIN with real tags but are not elements:
	// Parser::contextHtmlText() never assigns ->name for them, so it is null at runtime despite
	// vendor declaring it string. Taking the value as mixed is what lets that be checked at all;
	// treating those tokens as elements would unbalance the element stack.

	/**
	 * @param mixed $name
	 */
	private function elementName($name): string
	{
		return is_string($name) ? strtolower($name) : '';
	}

	/**
	 * @param list<array{tag: string, frameBase: int}> $elements
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 */
	private function closeElement(string $tag, array &$elements, array &$frames): void
	{
		for ($i = count($elements) - 1; $i >= 0; $i--) {
			if ($elements[$i]['tag'] !== $tag) {
				continue;
			}

			$this->closeHtmlFrames($frames, $elements[$i]['frameBase']);
			array_splice($elements, $i);

			return;
		}
	}

	/**
	 * @param list<array{kind: string, closer: string, depth: int, siteIndex: int, container: string|null, guard: list<string>|null}> $frames
	 */
	private function closeHtmlFrames(array &$frames, int $frameBase): void
	{
		// Latte balances an element only against its own n:attributes: {form}/{formContainer} bodies
		// may legally start inside an element and end after it, so an element close must remove the
		// frames its n:attributes opened and leave macro-closed frames to their own closing tag.
		for ($i = count($frames) - 1; $i >= $frameBase; $i--) {
			if ($frames[$i]['closer'] === self::CLOSER_HTML) {
				array_splice($frames, $i, 1);
			}
		}
	}

	// {input}/{label}/n:name take the first ':'-separated word as the component name; vendor's own
	// fetchWords() turns the rest into getControlPart()/getLabelPart() arguments, so a part suffix
	// is never part of the name. fetchWords() is @deprecated and TokenIterator::joinUntil() is
	// @internal, so the split is done on fetchWord()'s result instead - fetchWord() keeps ':' and
	// everything after it in one word (its own operator-continuation branch lists ':').
	private function firstWord(string $args): ?string
	{
		$word = (new MacroTokens($args))->fetchWord();

		return $this->literalName($word === null ? null : explode(':', $word)[0]);
	}

	// {form}/{formContext}/{formContainer}/n:formContainer read a single fetchWord(), with no
	// ':'-part vocabulary at all.
	private function singleWord(string $args): ?string
	{
		return $this->literalName((new MacroTokens($args))->fetchWord());
	}

	// A macro argument is a component NAME only if every NameSeparator segment of it is one:
	// getComponent() explodes on '-' and applies Container::NameRegexp per part, so 'a-b' is a legal
	// (nested) reference while 'a|b', 'foo()' and '$x' are not names at all. Asked of ComponentPath
	// rather than spelled as a regex of its own - that spelling was a seventh copy of the alphabet,
	// and a relaxed NameRegexp upstream would have moved the PHP channel while leaving this one
	// rejecting.
	private function literalName(?string $word): ?string
	{
		if ($word === null) {
			return null;
		}

		$word = $this->dequote(trim($word));
		foreach (ComponentPath::split($word) as $segment) {
			if (!ComponentPath::isValidSegment($segment)) {
				return null;
			}
		}

		return $word;
	}

	private function dequote(string $value): string
	{
		$length = strlen($value);
		if ($length >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[$length - 1] === $value[0]) {
			return (string) substr($value, 1, -1);
		}

		return $value;
	}

}
