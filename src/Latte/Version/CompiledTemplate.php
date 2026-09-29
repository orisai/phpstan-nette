<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use OriPhpstan\Nette\Latte\Compile\CompileResult;

final class CompiledTemplate
{

	private CompileResult $result;

	private ExtractedFacts $facts;

	public function __construct(CompileResult $result, ExtractedFacts $facts)
	{
		$this->result = $result;
		$this->facts = $facts;
	}

	public function getResult(): CompileResult
	{
		return $this->result;
	}

	public function getFacts(): ExtractedFacts
	{
		return $this->facts;
	}

}
