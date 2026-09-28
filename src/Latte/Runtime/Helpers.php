<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Runtime;

use Latte\Runtime\FilterInfo;
use Latte\Runtime\Html;
use LogicException;
use Nette\Application\UI\Form as UiForm;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;
use Nette\Utils\DateTime;

final class Helpers
{

	private function __construct()
	{
	}

	/**
	 * @return Html|string
	 */
	public static function capturedString()
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed $name
	 */
	public static function snippetId($name): string
	{
		return '';
	}

	/**
	 * @param object|string $nameOrObject
	 */
	public static function component($nameOrObject): void
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param string $destination
	 * @param array<mixed> $args
	 */
	public static function uiLink($destination, array $args = []): string
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed $destination
	 */
	public static function uiIsLinkCurrent($destination): bool
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed $target
	 */
	public static function embedTemplate($target): void
	{
		throw new LogicException('never executed');
	}

	public static function form(string $name): UiForm
	{
		throw new LogicException('never executed');
	}

	public static function formObject(Form $form): UiForm
	{
		throw new LogicException('never executed');
	}

	public static function formContainer(string $name): Container
	{
		throw new LogicException('never executed');
	}

	public static function formField(string $name): BaseControl
	{
		throw new LogicException('never executed');
	}

	public static function filterInfo(): FilterInfo
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed $message
	 * @param mixed ...$parameters
	 */
	public static function translate($message, ...$parameters): string
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed $time
	 * @param mixed $delta
	 */
	public static function modifyDate($time, $delta, ?string $unit = null): ?DateTime
	{
		throw new LogicException('never executed');
	}

	/**
	 * @template TValue of array<mixed>|string
	 * @param TValue $value
	 * @return (TValue is array ? array<key-of<TValue>, value-of<TValue>> : string)
	 */
	public static function slice($value, int $start, ?int $length = null, bool $preserveKeys = false)
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed ...$args
	 * @return mixed
	 */
	public static function unknownFilter(...$args)
	{
		throw new LogicException('never executed');
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public static function templateTypeInstance(string $class)
	{
		throw new LogicException('never executed');
	}

	/**
	 * @param mixed ...$args
	 */
	public static function analyzed(...$args): void
	{
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public static function edgeScope(string $edge, array $args = []): void
	{
	}

	/**
	 * @param array<int|string, string|bool|null> $classes
	 */
	public static function classes(array $classes): string
	{
		throw new LogicException('never executed');
	}

}
