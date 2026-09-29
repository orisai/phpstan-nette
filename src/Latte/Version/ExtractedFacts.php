<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use OriPhpstan\Nette\Latte\Declarations\Declarations;
use OriPhpstan\Nette\Latte\Includes\TemplateFacts;
use OriPhpstan\Nette\LatteForms\FormSite;

final class ExtractedFacts
{

	private Declarations $declarations;

	private TemplateFacts $templateFacts;

	/** @var list<FormSite> */
	private array $formSites;

	/**
	 * @param list<FormSite> $formSites
	 */
	public function __construct(Declarations $declarations, TemplateFacts $templateFacts, array $formSites)
	{
		$this->declarations = $declarations;
		$this->templateFacts = $templateFacts;
		$this->formSites = $formSites;
	}

	public function getDeclarations(): Declarations
	{
		return $this->declarations;
	}

	public function getTemplateFacts(): TemplateFacts
	{
		return $this->templateFacts;
	}

	/**
	 * @return list<FormSite>
	 */
	public function getFormSites(): array
	{
		return $this->formSites;
	}

}
