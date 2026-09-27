<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\Support;

abstract class BaseOpaqueContentControl
{

	public function setLabel(string $value): self
	{
		return $this;
	}

	/**
	 * @param mixed $presenter
	 * @return mixed
	 */
	public function setPresenter($presenter)
	{
		return $this;
	}

	abstract public function create();

}
