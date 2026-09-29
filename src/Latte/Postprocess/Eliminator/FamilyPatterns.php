<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Postprocess\Eliminator;

use LogicException;
use OriPhpstan\Nette\Latte\Postprocess\FilterRewriter;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use function sprintf;

// The generated-code shapes each post-processing consumer matches, per shape family. Names every
// line shares stay constants on the consumer; a family-specific shape lives here, so a new Latte
// line is a new column of this table. The core shapes follow the Latte line, the forms shapes the
// forms bridge.
final class FamilyPatterns
{

	public const CONSUMERS = [
		PrologEliminator::class,
		EscapingEliminator::class,
		IteratorEliminator::class,
		ControlFlowEliminator::class,
		DevTagEliminator::class,
		CaptureEliminator::class,
		AttrShellEliminator::class,
		UiMacroEliminator::class,
		FormsMacroEliminator::class,
		BlockDispatchEliminator::class,
		FilterRewriter::class,
	];

	private const LATTE_RUNTIME_FILTERS = ['Latte\Runtime\Filters', 'LR\Filters'];

	private const LATTE_RUNTIME_HELPERS = ['Latte\Runtime\Helpers', 'LR\Helpers'];

	private const LATTE_RUNTIME_HTML_HELPERS = ['Latte\Runtime\HtmlHelpers', 'LR\HtmlHelpers'];

	private const LATTE_RUNTIME_XML_HELPERS = ['Latte\Runtime\XmlHelpers', 'LR\XmlHelpers'];

	private function __construct()
	{
	}

	public static function for(ShapeFamily $family, string $consumer): PatternSet
	{
		$line = $family->latteLine;

		switch ($consumer) {
			case PrologEliminator::class:
				return self::prolog($line);
			case EscapingEliminator::class:
				return self::escaping($line);
			case IteratorEliminator::class:
				return self::iterator($line);
			case ControlFlowEliminator::class:
				return self::controlFlow($line);
			case DevTagEliminator::class:
				return self::devTag($line);
			case CaptureEliminator::class:
				return self::capture($line);
			case AttrShellEliminator::class:
				return self::attrShell($line);
			case UiMacroEliminator::class:
				return self::uiMacro($line);
			case FormsMacroEliminator::class:
				return self::formsMacro($family->formsBridge);
			case BlockDispatchEliminator::class:
				return self::blockDispatch($line);
			case FilterRewriter::class:
				return self::filterRewriter($line);
		}

		throw new LogicException(sprintf('%s has no pattern table.', $consumer));
	}

	private static function prolog(string $line): PatternSet
	{
		$latte2 = $line === ShapeFamily::LATTE_2;

		return new PatternSet([], [
			PrologEliminator::ROLE_DEFINED_VARS => ['get_defined_vars'],
			PrologEliminator::ROLE_EXTENDS_GUARD => $latte2 ? ['getParentName'] : [],
			PrologEliminator::ROLE_OVERWRITE_WARNING => ['array_intersect_key', 'trigger_error'],
			PrologEliminator::ROLE_EMPTY_PREPARE => $latte2 ? ['lattePrepare'] : [],
		]);
	}

	private static function escaping(string $line): PatternSet
	{
		if ($line === ShapeFamily::LATTE_2) {
			return new PatternSet([
				EscapingEliminator::ROLE_UNWRAP_VALUE => self::calls(self::LATTE_RUNTIME_FILTERS, [
					'escapeHtmlText',
					'escapeHtmlAttr',
					'escapeHtmlComment',
					'escapeXml',
					'escapeCss',
					'escapeICal',
					'safeUrl',
				]),
			]);
		}

		if ($line === ShapeFamily::LATTE_30) {
			return new PatternSet([
				EscapingEliminator::ROLE_UNWRAP_VALUE => self::calls(self::LATTE_RUNTIME_FILTERS, [
					'escapeHtmlText',
					'escapeHtmlAttr',
					'escapeHtmlTag',
					'escapeHtmlComment',
					'escapeHtmlQuotes',
					'escapeHtmlRawTextHtml',
					'escapeXmlText',
					'escapeXmlAttr',
					'escapeXmlTag',
					'escapeCss',
					'escapeICal',
				]),
			], [
				EscapingEliminator::ROLE_UNWRAP_FILTER => ['checkUrl'],
			]);
		}

		return new PatternSet([
			EscapingEliminator::ROLE_UNWRAP_VALUE => self::calls(self::LATTE_RUNTIME_HTML_HELPERS, [
				'escapeText',
				'escapeAttr',
				'escapeTag',
				'escapeComment',
				'escapeQuotes',
				'escapeRawHtml',
			]) + self::calls(self::LATTE_RUNTIME_XML_HELPERS, ['escapeText', 'escapeAttr', 'escapeTag'])
				+ self::calls(self::LATTE_RUNTIME_HELPERS, ['escapeCss', 'escapeICal']),
			EscapingEliminator::ROLE_UNWRAP_ATTRIBUTE_VALUE => self::calls(
				self::LATTE_RUNTIME_HTML_HELPERS,
				['formatAttribute'],
			)
				+ self::calls(self::LATTE_RUNTIME_XML_HELPERS, ['formatAttribute']),
			EscapingEliminator::ROLE_ATTRIBUTE_STAND_IN => self::calls(self::LATTE_RUNTIME_HTML_HELPERS, [
				'formatBoolAttribute',
				'formatListAttribute',
				'formatStyleAttribute',
				'formatDataAttribute',
				'formatJsonAttribute',
				'formatAriaAttribute',
			]),
		], [
			EscapingEliminator::ROLE_UNWRAP_FILTER => ['checkUrl'],
		]);
	}

	private static function iterator(string $line): PatternSet
	{
		$latte2 = $line === ShapeFamily::LATTE_2;

		return new PatternSet([], [
			IteratorEliminator::ROLE_CACHING_ITERATOR => [
				$latte2 ? 'Latte\Runtime\CachingIterator' : 'Latte\Essential\CachingIterator',
			],
			IteratorEliminator::ROLE_ITERATIONS_VAR => $latte2 ? ['iterations'] : [],
		]);
	}

	private static function controlFlow(string $line): PatternSet
	{
		$latte2 = $line === ShapeFamily::LATTE_2;

		return new PatternSet([], [
			NoopObStart::ROLE => [$latte2 ? NoopObStart::SHAPE_EMPTY_CLOSURE : NoopObStart::SHAPE_EMPTY_STRING_ARROW],
			ControlFlowEliminator::ROLE_TRY_CATCH_OPEN => [$latte2 ? 'ob_end_clean' : 'ob_clean'],
			ControlFlowEliminator::ROLE_TRY_CATCH_CLOSE => $latte2 ? ['ob_start'] : [],
			ControlFlowEliminator::ROLE_TAG_IF_TEMP => $latte2 ? ["\u{29F}_if"] : [],
		]);
	}

	private static function devTag(string $line): PatternSet
	{
		$latte2 = $line === ShapeFamily::LATTE_2;

		return new PatternSet([
			DevTagEliminator::ROLE_DROPPED_CALL => ['Tracy\Debugger' => ['barDump']]
				+ [($latte2 ? 'Latte\Runtime\Tracer' : 'Latte\Essential\Tracer') => ['throw']],
			DevTagEliminator::ROLE_PRINT_CLASS => ($latte2
				? ['Nette\Bridges\ApplicationLatte\UIRuntime' => ['printClass']]
				: ['Nette\Bridges\ApplicationLatte\Nodes\TemplatePrintNode' => ['printClass']])
				+ ['Nette\Forms\Blueprint' => ['latte', 'dataClass']],
		]);
	}

	private static function capture(string $line): PatternSet
	{
		if ($line === ShapeFamily::LATTE_2) {
			return new PatternSet([], [
				NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_CLOSURE],
				CaptureEliminator::ROLE_SPACELESS_HANDLER => [
					'Latte\Runtime\Filters::spacelessHtmlHandler',
					'Latte\Runtime\Filters::spacelessText',
				],
			]);
		}

		if ($line === ShapeFamily::LATTE_30) {
			return new PatternSet([], [
				NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_STRING_ARROW],
				CaptureEliminator::ROLE_SPACELESS_HANDLER => [
					'Latte\Essential\Filters::spacelessHtmlHandler',
					'Latte\Essential\Filters::spacelessText',
				],
			]);
		}

		return new PatternSet([
			CaptureEliminator::ROLE_SPACELESS_START => ['Latte\Essential\WhitespaceMinifier' => ['start']],
			CaptureEliminator::ROLE_SPACELESS_END => ['Latte\Essential\WhitespaceMinifier' => ['end']],
		], [
			NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_STRING_ARROW],
			CaptureEliminator::ROLE_FILTERED_CAPTURE_HTML_GUARD => ['contentType'],
		]);
	}

	private static function attrShell(string $line): PatternSet
	{
		if ($line === ShapeFamily::LATTE_2) {
			return new PatternSet([
				AttrShellEliminator::ROLE_ATTRIBUTES => self::calls(self::LATTE_RUNTIME_FILTERS, ['htmlAttributes']),
			], [
				NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_CLOSURE],
				AttrShellEliminator::ROLE_TAG_ARRAY => ["\u{29F}_tag"],
			]);
		}

		$calls = [
			AttrShellEliminator::ROLE_ATTRIBUTES => ['Latte\Essential\Nodes\NAttrNode' => ['attrs']],
			AttrShellEliminator::ROLE_TAG_CHANGE => self::calls(
				self::LATTE_RUNTIME_HTML_HELPERS,
				['validateTagChange'],
			),
		];

		if ($line === ShapeFamily::LATTE_30) {
			return new PatternSet($calls, [
				NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_STRING_ARROW],
				AttrShellEliminator::ROLE_TAG_ARRAY => ["\u{29F}_tag"],
			]);
		}

		return new PatternSet($calls, [
			NoopObStart::ROLE => [NoopObStart::SHAPE_EMPTY_STRING_ARROW],
			AttrShellEliminator::ROLE_TAG_SCALAR => ["\u{29F}_tag"],
			AttrShellEliminator::ROLE_TAG_SNAPSHOT => ["\u{29F}_tags"],
		]);
	}

	private static function uiMacro(string $line): PatternSet
	{
		return new PatternSet([], [
			UiMacroEliminator::ROLE_DYNAMIC_COMPONENT => [
				$line === ShapeFamily::LATTE_2
					? UiMacroEliminator::SHAPE_IS_OBJECT_IF_ELSE
					: UiMacroEliminator::SHAPE_NOT_IS_OBJECT_ASSIGN,
			],
		]);
	}

	// Latte 2 FormMacros and Latte 3 + nette/forms 3.1.7-3.2 push the form on $this->global->formsStack
	// and call the static FormsLatte\Runtime; the first offsets end($this->global->formsStack)['x'] into
	// $ʟ_input/$ʟ_label temps, the second resolves Runtime::item('x', $this->global) into $ʟ_label/
	// $ʟ_elem. nette/forms 3.3 keeps the scope inside the $this->global->forms Runtime instance
	// (begin/get/getScope/renderFormBegin/renderFormEnd/end).
	private static function formsMacro(string $bridge): PatternSet
	{
		if ($bridge === ShapeFamily::FORMS_PROVIDER) {
			return new PatternSet([], [
				FormsMacroEliminator::ROLE_FORM_OPEN => [FormsMacroEliminator::SHAPE_PROVIDER_BEGIN],
				FormsMacroEliminator::ROLE_FIELD_LOOKUP => [FormsMacroEliminator::SHAPE_PROVIDER_GET],
				FormsMacroEliminator::ROLE_FORMS_PROVIDER => ['forms'],
				FormsMacroEliminator::ROLE_LABEL_TEMP => ["\u{29F}_label"],
				FormsMacroEliminator::ROLE_ELEM_TEMP => ["\u{29F}_elem"],
			]);
		}

		$macros = $bridge === ShapeFamily::FORMS_MACROS;

		return new PatternSet([
			FormsMacroEliminator::ROLE_RUNTIME => [
				'Nette\Bridges\FormsLatte\Runtime' => $macros
					? ['initializeForm', 'renderFormBegin', 'renderFormEnd']
					: ['initializeForm', 'renderFormBegin', 'renderFormEnd', 'item'],
			],
		], [
			FormsMacroEliminator::ROLE_FORM_OPEN => [FormsMacroEliminator::SHAPE_STACK_PUSH],
			FormsMacroEliminator::ROLE_FIELD_LOOKUP => [
				$macros ? FormsMacroEliminator::SHAPE_STACK_OFFSET : FormsMacroEliminator::SHAPE_RUNTIME_ITEM,
			],
			FormsMacroEliminator::ROLE_STACK_PROVIDER => ['formsStack'],
			FormsMacroEliminator::ROLE_INPUT_TEMP => $macros ? ["\u{29F}_input"] : [],
			FormsMacroEliminator::ROLE_LABEL_TEMP => ["\u{29F}_label"],
			FormsMacroEliminator::ROLE_ELEM_TEMP => $macros ? [] : ["\u{29F}_elem"],
		]);
	}

	private static function blockDispatch(string $line): PatternSet
	{
		if ($line === ShapeFamily::LATTE_2) {
			return new PatternSet([], [
				BlockDispatchEliminator::ROLE_PARENT_BLOCK => ['renderBlockParent'],
				BlockDispatchEliminator::ROLE_EMBED_DEAD_IF => ['ifFalse'],
			]);
		}

		return new PatternSet([
			BlockDispatchEliminator::ROLE_DYNAMIC_NAME => self::calls(
				self::LATTE_RUNTIME_HELPERS,
				['stringOrNull'],
			),
		], [
			BlockDispatchEliminator::ROLE_PARENT_BLOCK => ['renderParentBlock'],
			BlockDispatchEliminator::ROLE_INLINE_BLOCK_CLOSURE => ['func_get_arg'],
		]);
	}

	private static function filterRewriter(string $line): PatternSet
	{
		return new PatternSet([
			FilterRewriter::ROLE_CONVERT_TO => self::calls(
				$line === ShapeFamily::LATTE_31 ? self::LATTE_RUNTIME_HELPERS : self::LATTE_RUNTIME_FILTERS,
				['convertTo'],
			),
		], [
			FilterRewriter::ROLE_FUNCTION_TEMPLATE_ARG => $line === ShapeFamily::LATTE_2 ? [] : ['this'],
		]);
	}

	/**
	 * @param list<string> $classes
	 * @param list<string> $methods
	 * @return array<string, list<string>>
	 */
	private static function calls(array $classes, array $methods): array
	{
		$calls = [];
		foreach ($classes as $class) {
			$calls[$class] = $methods;
		}

		return $calls;
	}

}
