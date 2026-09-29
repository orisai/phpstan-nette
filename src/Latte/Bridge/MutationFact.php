<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

final class MutationFact
{

	public const KIND_SET_VIEW = 'setView';

	public const KIND_SET_ACTION = 'changeAction';

	// nette/application 3.2: throws SwitchException, which run() turns into changeAction() from an
	// action method and setView() from a render method - a view write either way.
	public const KIND_SWITCH = 'switch';

	public const KIND_SET_FILE = 'setFile';

	public const PHASE_STARTUP = 'startup';

	public const PHASE_ACTION = 'action';

	public const PHASE_SIGNAL = 'signal';

	public const PHASE_BEFORE_RENDER = 'beforeRender';

	public const PHASE_RENDER = 'render';

	// setFile's no-too-late-window proof covers full-render flows only (AJAX snippet responses
	// send inside run(), before shutdown); EFFECTIVE_YES stays the safe never-suppress direction.
	public const PHASE_OUTSIDE = 'outside';

	public const EFFECTIVE_YES = 'yes';

	public const EFFECTIVE_NO = 'no';

	public const EFFECTIVE_MAYBE = 'maybe';

	public const LIFECYCLE_PHASE_ORDER = [
		self::PHASE_STARTUP,
		self::PHASE_ACTION,
		self::PHASE_SIGNAL,
		self::PHASE_BEFORE_RENDER,
		self::PHASE_RENDER,
		self::PHASE_OUTSIDE,
	];

	/** @var self::KIND_* */
	private string $kind;

	/** @var self::PHASE_* */
	private string $phase;

	/** @var self::EFFECTIVE_* */
	private string $effectiveness;

	private int $line;

	private ?string $argument;

	/**
	 * @param self::KIND_* $kind
	 * @param self::PHASE_* $phase
	 * @param self::EFFECTIVE_* $effectiveness
	 */
	public function __construct(string $kind, string $phase, string $effectiveness, int $line, ?string $argument)
	{
		$this->kind = $kind;
		$this->phase = $phase;
		$this->effectiveness = $effectiveness;
		$this->line = $line;
		$this->argument = $argument;
	}

	/**
	 * @param array{kind: self::KIND_*, phase: self::PHASE_*, effectiveness: self::EFFECTIVE_*, line: int, argument: string|null} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['kind'], $data['phase'], $data['effectiveness'], $data['line'], $data['argument']);
	}

	/**
	 * @return self::KIND_*
	 */
	public function getKind(): string
	{
		return $this->kind;
	}

	/**
	 * @return self::PHASE_*
	 */
	public function getPhase(): string
	{
		return $this->phase;
	}

	/**
	 * @return self::EFFECTIVE_*
	 */
	public function getEffectiveness(): string
	{
		return $this->effectiveness;
	}

	public function getLine(): int
	{
		return $this->line;
	}

	public function getArgument(): ?string
	{
		return $this->argument;
	}

	/**
	 * @return array{kind: self::KIND_*, phase: self::PHASE_*, effectiveness: self::EFFECTIVE_*, line: int, argument: string|null}
	 */
	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'phase' => $this->phase,
			'effectiveness' => $this->effectiveness,
			'line' => $this->line,
			'argument' => $this->argument,
		];
	}

}
