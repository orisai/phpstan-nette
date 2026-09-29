<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;

// Channels are provenance labels for where the resolved class came from, not competing
// resolution mechanisms. Class-level (conflict-judged): phpdoc is the entry class's own
// @property override, genericBinding is the reflection-resolved surface (inherited and generic
// members included, vendor-declared ones left to the floor), new is the creation inside the
// entry class's resolved createTemplate() override (return new and trusted factory calls alike),
// convention is the entry class's resolved getTemplateClass()/formatTemplateClass() hook return.
// Per-site (never conflict): createTemplate/factoryStatic are walked call sites outside the
// override. factoryDefault and templateFloor are the fallback rungs of the resolution ladder,
// never walked observations.
final class TemplateClassFact
{

	public const CHANNEL_CREATE_TEMPLATE = 'createTemplate';

	public const CHANNEL_CONVENTION = 'convention';

	public const CHANNEL_NEW = 'new';

	public const CHANNEL_FACTORY_STATIC = 'factoryStatic';

	public const CHANNEL_GENERIC_BINDING = 'genericBinding';

	public const CHANNEL_PHPDOC = 'phpdoc';

	public const CHANNEL_FACTORY_DEFAULT = 'factoryDefault';

	public const CHANNEL_TEMPLATE_FLOOR = 'templateFloor';

	// A convention hook whose return is not a resolvable ::class constant records this
	// never-a-class marker as its className; the judge maps it to an opaque finding.
	public const DYNAMIC_CLASS_NAME = '*dynamic*';

	private string $className;

	/** @var self::CHANNEL_* */
	private string $channel;

	/** @var Certainty::* */
	private string $certainty;

	/** @var list<int> */
	private array $sites;

	/**
	 * @param self::CHANNEL_* $channel
	 * @param Certainty::* $certainty
	 * @param list<int> $sites
	 */
	public function __construct(string $className, string $channel, string $certainty, array $sites = [])
	{
		$this->className = $className;
		$this->channel = $channel;
		$this->certainty = $certainty;
		$this->sites = $sites;
	}

	/**
	 * @param array{className: string, channel: self::CHANNEL_*, certainty: Certainty::*, sites: list<int>} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['className'], $data['channel'], $data['certainty'], $data['sites']);
	}

	public function getClassName(): string
	{
		return $this->className;
	}

	/**
	 * @return self::CHANNEL_*
	 */
	public function getChannel(): string
	{
		return $this->channel;
	}

	/**
	 * @return Certainty::*
	 */
	public function getCertainty(): string
	{
		return $this->certainty;
	}

	/**
	 * @return list<int>
	 */
	public function getSites(): array
	{
		return $this->sites;
	}

	/**
	 * @return array{className: string, channel: self::CHANNEL_*, certainty: Certainty::*, sites: list<int>}
	 */
	public function toArray(): array
	{
		return [
			'className' => $this->className,
			'channel' => $this->channel,
			'certainty' => $this->certainty,
			'sites' => $this->sites,
		];
	}

}
