<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use InvalidArgumentException;
use function preg_match;
use function sprintf;

// Which generated-code shapes a compiled template carries: the Latte line decides the core/UI
// shapes and the line markers, the forms bridge how nette/forms' Latte bridge renders controls.
final class ShapeFamily
{

	public const LATTE_2 = '2';

	public const LATTE_30 = '3.0';

	public const LATTE_31 = '3.1';

	public const FORMS_MACROS = 'macros';

	public const FORMS_ITEM = 'item';

	public const FORMS_PROVIDER = 'provider';

	public const LINE_MARKER_PATTERN_LINE = '~/\* line (?<line>\d+) \*/~';

	public const LINE_MARKER_PATTERN_POS = '~/\* pos (?<line>\d+):\d+ \*/~';

	public string $latteLine;

	public string $formsBridge;

	public function __construct(string $latteLine, string $formsBridge)
	{
		$this->latteLine = $latteLine;
		$this->formsBridge = $formsBridge;
	}

	public static function detect(string $latteVersion, ?string $formsVersion): self
	{
		$latte = self::majorMinor($latteVersion);
		if ($latte === null || !self::supports($latteVersion)) {
			throw new InvalidArgumentException(sprintf('Unsupported latte/latte version "%s".', $latteVersion));
		}

		[, $minor] = $latte;
		if ($latte[0] === 2) {
			return new self(self::LATTE_2, self::FORMS_MACROS);
		}

		$forms = $formsVersion !== null ? self::majorMinor($formsVersion) : null;
		$formsBridge = $forms !== null && ($forms[0] > 3 || ($forms[0] === 3 && $forms[1] >= 3))
			? self::FORMS_PROVIDER
			: self::FORMS_ITEM;

		return new self($minor === 0 ? self::LATTE_30 : self::LATTE_31, $formsBridge);
	}

	public static function supports(string $latteVersion): bool
	{
		$latte = self::majorMinor($latteVersion);

		return $latte !== null && ($latte[0] === 2 || ($latte[0] === 3 && $latte[1] <= 1));
	}

	// Every combination detect() can produce.

	/**
	 * @return list<self>
	 */
	public static function all(): array
	{
		return [
			new self(self::LATTE_2, self::FORMS_MACROS),
			new self(self::LATTE_30, self::FORMS_ITEM),
			new self(self::LATTE_30, self::FORMS_PROVIDER),
			new self(self::LATTE_31, self::FORMS_ITEM),
			new self(self::LATTE_31, self::FORMS_PROVIDER),
		];
	}

	public function id(): string
	{
		return $this->latteLine . '/' . $this->formsBridge;
	}

	// Latte 2 and 3.0 mark generated lines as `/* line N */`, Latte 3.1 as `/* pos L:C */`.
	public function lineMarkerPattern(): string
	{
		return $this->latteLine === self::LATTE_31 ? self::LINE_MARKER_PATTERN_POS : self::LINE_MARKER_PATTERN_LINE;
	}

	/**
	 * @return array{int, int}|null
	 */
	private static function majorMinor(string $version): ?array
	{
		if (preg_match('~^v?(\d+)\.(\d+)~', $version, $m) !== 1) {
			return null;
		}

		return [(int) $m[1], (int) $m[2]];
	}

}
