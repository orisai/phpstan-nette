<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use function assert;
use function is_int;
use function is_string;

final class RegistrationFact
{

	public const KIND_EVENT_HANDLER = 'eventHandler';

	public const KIND_PARAM_PASS_THROUGH = 'paramPassThrough';

	public const KIND_TRAIT_USE = 'traitUse';

	public const KIND_COMPONENT_MUTATION = 'componentMutation';

	public const KIND_PARAM_MUTATION = 'paramMutation';

	// The owner of a mutated component, as far as syntax can name it: the mutating class itself
	// ($this['x']->addText()), one of its own components ($this['ctrl']['x']->addText()), or nothing
	// nameable ($local['x']->addText(), a dynamic outer offset) - in which case every class is a
	// candidate owner.
	public const OWNER_SELF = 'self';

	public const OWNER_COMPONENT = 'component';

	public const OWNER_UNKNOWN = 'unknown';

	// The mutated component of a site that reached one without naming it ($this['ctrl']->getForm()
	// ->addText()). No Nette component name can spell it, so it collides with none: every component
	// of the resolved owner is a candidate, and componentMutationSites folds it into every lookup.
	public const MUTATED_ANY = '*';

	private string $kind;

	/** @var array<string, mixed> */
	private array $fields;

	/**
	 * @param array<string, mixed> $fields
	 */
	private function __construct(string $kind, array $fields)
	{
		$this->kind = $kind;
		$this->fields = $fields;
	}

	public static function eventHandlerRegistration(
		string $registeringClass,
		string $registeringMethod,
		string $formVar,
		string $handlerClass,
		string $handlerMethod,
		string $eventProperty
	): self
	{
		return new self(self::KIND_EVENT_HANDLER, [
			'registeringClass' => $registeringClass,
			'registeringMethod' => $registeringMethod,
			'formVar' => $formVar,
			'handlerClass' => $handlerClass,
			'handlerMethod' => $handlerMethod,
			'eventProperty' => $eventProperty,
		]);
	}

	/**
	 * @param int|string $callerOrigin caller param index (int) or local var site id (string)
	 */
	public static function paramPassThrough(
		string $callerClass,
		string $callerMethod,
		$callerOrigin,
		string $calleeClass,
		string $calleeMethod,
		int $calleeParamIdx
	): self
	{
		return new self(self::KIND_PARAM_PASS_THROUGH, [
			'callerClass' => $callerClass,
			'callerMethod' => $callerMethod,
			'callerOrigin' => $callerOrigin,
			'calleeClass' => $calleeClass,
			'calleeMethod' => $calleeMethod,
			'calleeParamIdx' => $calleeParamIdx,
		]);
	}

	/**
	 * @param self::OWNER_* $ownerScope
	 * @param string|null $handOverCallee the callee key a hand-over site is conditional on, null for a
	 *                                    mutation spelled at the site itself
	 */
	public static function componentMutation(
		string $mutatingClass,
		string $ownerScope,
		string $ownerComponent,
		string $mutatedComponent,
		?string $handOverCallee = null
	): self
	{
		return new self(self::KIND_COMPONENT_MUTATION, [
			'mutatingClass' => $mutatingClass,
			'ownerScope' => $ownerScope,
			'ownerComponent' => $ownerComponent,
			'mutatedComponent' => $mutatedComponent,
			'handOverCallee' => $handOverCallee,
		]);
	}

	/**
	 * A method parameter mutated by the method itself ($handOverCallee null) or handed on to another
	 * callee, which is what makes the mutation of a HANDED-OVER component decidable: passing a
	 * component to a method that only reads it registers nothing.
	 */
	public static function paramMutation(
		string $paramClass,
		string $paramMethod,
		int $paramIdx,
		?string $handOverCallee = null
	): self
	{
		return new self(self::KIND_PARAM_MUTATION, [
			'paramClass' => $paramClass,
			'paramMethod' => $paramMethod,
			'paramIdx' => $paramIdx,
			'handOverCallee' => $handOverCallee,
		]);
	}

	public static function traitUse(string $className, string $traitName): self
	{
		return new self(self::KIND_TRAIT_USE, [
			'className' => $className,
			'traitName' => $traitName,
		]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$kind = $data['kind'];
		assert(is_string($kind));
		unset($data['kind']);

		return new self($kind, $data);
	}

	// The universe-wide key a hand-over is decided against: the callee's method name and the argument
	// position, deliberately NOT its class. A syntactic fold cannot resolve the receiver's runtime
	// class, so the coarser key answers for every class declaring that method - conservative in the
	// direction that matters, since one mutating implementation opens every hand-over to the name.
	public static function calleeKey(string $method, int $paramIdx): string
	{
		return $method . "\0" . $paramIdx;
	}

	public function getKind(): string
	{
		return $this->kind;
	}

	public function getRegisteringClass(): string
	{
		return $this->stringField('registeringClass');
	}

	public function getRegisteringMethod(): string
	{
		return $this->stringField('registeringMethod');
	}

	public function getFormVar(): string
	{
		return $this->stringField('formVar');
	}

	public function getHandlerClass(): string
	{
		return $this->stringField('handlerClass');
	}

	public function getHandlerMethod(): string
	{
		return $this->stringField('handlerMethod');
	}

	public function getEventProperty(): string
	{
		return $this->stringField('eventProperty');
	}

	public function getCallerClass(): string
	{
		return $this->stringField('callerClass');
	}

	public function getCallerMethod(): string
	{
		return $this->stringField('callerMethod');
	}

	/**
	 * @return int|string
	 */
	public function getCallerOrigin()
	{
		$value = $this->fields['callerOrigin'] ?? null;
		assert(is_int($value) || is_string($value));

		return $value;
	}

	public function getCalleeClass(): string
	{
		return $this->stringField('calleeClass');
	}

	public function getCalleeMethod(): string
	{
		return $this->stringField('calleeMethod');
	}

	public function getCalleeParamIdx(): int
	{
		$value = $this->fields['calleeParamIdx'] ?? null;
		assert(is_int($value));

		return $value;
	}

	public function getMutatingClass(): string
	{
		return $this->stringField('mutatingClass');
	}

	/**
	 * @return self::OWNER_*
	 */
	public function getOwnerScope(): string
	{
		$value = $this->stringField('ownerScope');
		assert($value === self::OWNER_SELF || $value === self::OWNER_COMPONENT || $value === self::OWNER_UNKNOWN);

		return $value;
	}

	public function getOwnerComponent(): string
	{
		return $this->stringField('ownerComponent');
	}

	public function getMutatedComponent(): string
	{
		return $this->stringField('mutatedComponent');
	}

	public function getHandOverCallee(): ?string
	{
		$value = $this->fields['handOverCallee'] ?? null;
		assert($value === null || is_string($value));

		return $value;
	}

	public function getParamKey(): string
	{
		$idx = $this->fields['paramIdx'] ?? null;
		assert(is_int($idx));

		return self::calleeKey($this->stringField('paramMethod'), $idx);
	}

	public function getClassName(): string
	{
		return $this->stringField('className');
	}

	public function getTraitName(): string
	{
		return $this->stringField('traitName');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return ['kind' => $this->kind] + $this->fields;
	}

	private function stringField(string $key): string
	{
		$value = $this->fields[$key] ?? null;
		assert(is_string($value));

		return $value;
	}

}
