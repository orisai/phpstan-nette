<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Fixtures\Rule;

use RuntimeException;

class TerminatingRenderProbeControl
{

	public function renderAborted(): void
	{
		throw new RuntimeException('aborted before the dispatch');
	}

	public function renderConditional(bool $flag): void
	{
		if ($flag) {
			throw new RuntimeException('sometimes');
		}
	}

	public function renderPlain(): void
	{
		$this->renderConditional(true);
	}

	public function helperAborted(): void
	{
		throw new RuntimeException('not a render hook');
	}

}
