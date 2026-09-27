<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\Support;

interface IOpaqueContentControlFactory
{

	public function create(): OpaqueContentControl;

}
