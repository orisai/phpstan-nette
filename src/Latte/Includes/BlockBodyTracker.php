<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use function array_key_last;
use function array_pop;
use function strpos;

// Shared {block}/{define} body-depth tracker. DeclarationScanner (mid-file {varType} placement)
// and TemplateFactExtractor (blockDeclaredVars) both need the same answer - is the current
// position sitting directly at a named block/define body's own top level, keyed by the same
// MacroPairing depth rules and the same "is this name a real call target" gate - kept as one
// implementation after this codebase was bitten (again) by a second, independently-written copy
// of it drifting from the first: an anonymous or dynamically-named {block} was exempt from
// placement checking in one copy while never entering blockDeclaredVars in the other, so a
// {varType} inside one was checked by neither.
final class BlockBodyTracker
{

	private int $depth = 0;

	/** @var list<array{name: string, bodyDepth: int}> */
	private array $stack = [];

	// Call for every CLOSING macro tag, before any other handling of it, with whether it closes a
	// body (MacroPairing::closesBody() for a Latte 2 token).
	public function closing(bool $closesBody): void
	{
		if ($this->depth > 0 && $closesBody) {
			$this->depth--;
		}

		while ($this->stack !== [] && $this->stack[array_key_last($this->stack)]['bodyDepth'] > $this->depth) {
			array_pop($this->stack);
		}
	}

	// Call once per {block}/{define} tag that OPENS a body, with its statically-resolved name. An
	// anonymous ('') or dynamically-named (contains '$') block/define gets no frame at all - the
	// same gate TemplateFactExtractor's own blockNames/defineNames collection applies, because
	// neither is ever a valid call target whose declared vars or placement-exempt body could be
	// looked up by name afterward.
	public function enterBlockOrDefine(string $name): void
	{
		if ($name === '' || strpos($name, '$') !== false) {
			return;
		}

		$this->stack[] = ['name' => $name, 'bodyDepth' => $this->depth + 1];
	}

	// Call once per token, after all other handling of it, with whether IT opens a body.
	public function advance(bool $opensBody): void
	{
		if ($opensBody) {
			$this->depth++;
		}
	}

	public function depth(): int
	{
		return $this->depth;
	}

	// Whether the current position sits directly at the innermost open named block/define body's
	// own top level - not nested deeper inside an {if}/{foreach} within it.
	public function isAtOwnTopLevel(): bool
	{
		return $this->stack !== [] && $this->depth === $this->stack[array_key_last($this->stack)]['bodyDepth'];
	}

	// The innermost open named block/define, or null outside of one. TemplateFactExtractor keys
	// blockDeclaredVars by its name.

	/**
	 * @return array{name: string, bodyDepth: int}|null
	 */
	public function currentFrame(): ?array
	{
		return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
	}

}
