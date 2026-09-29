<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use Closure;
use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;

// Each fact is resolved on first access and memoised, so a consumer that reads only the
// declarations never pays for the include-edge or form-site scan.
final class ExtractedFacts
{

	/** @var Closure(): Declarations */
	private Closure $declarationsResolver;

	/** @var Closure(): TemplateFacts */
	private Closure $templateFactsResolver;

	/** @var Closure(): list<FormSite> */
	private Closure $formSitesResolver;

	private ?Declarations $declarations = null;

	private ?TemplateFacts $templateFacts = null;

	/** @var list<FormSite>|null */
	private ?array $formSites = null;

	/**
	 * @param Closure(): Declarations $declarationsResolver
	 * @param Closure(): TemplateFacts $templateFactsResolver
	 * @param Closure(): list<FormSite> $formSitesResolver
	 */
	private function __construct(
		Closure $declarationsResolver,
		Closure $templateFactsResolver,
		Closure $formSitesResolver
	)
	{
		$this->declarationsResolver = $declarationsResolver;
		$this->templateFactsResolver = $templateFactsResolver;
		$this->formSitesResolver = $formSitesResolver;
	}

	/**
	 * @param Closure(): Declarations $declarations
	 * @param Closure(): TemplateFacts $templateFacts
	 * @param Closure(): list<FormSite> $formSites
	 */
	public static function lazy(Closure $declarations, Closure $templateFacts, Closure $formSites): self
	{
		return new self($declarations, $templateFacts, $formSites);
	}

	/**
	 * @param list<FormSite> $formSites
	 */
	public static function eager(Declarations $declarations, TemplateFacts $templateFacts, array $formSites): self
	{
		return new self(
			static fn (): Declarations => $declarations,
			static fn (): TemplateFacts => $templateFacts,
			static fn (): array => $formSites,
		);
	}

	public function getDeclarations(): Declarations
	{
		return $this->declarations ??= ($this->declarationsResolver)();
	}

	public function getTemplateFacts(): TemplateFacts
	{
		return $this->templateFacts ??= ($this->templateFactsResolver)();
	}

	/**
	 * @return list<FormSite>
	 */
	public function getFormSites(): array
	{
		return $this->formSites ??= ($this->formSitesResolver)();
	}

}
