<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Support;

use Nette\Forms\Controls\BaseControl;

/**
 * @form-read-type 'a'|'b'|'c'
 * @form-write-type 'a'|'b'|'c'
 */
final class AnnotatedCustomControl extends BaseControl
{

	/** @form-modifier nullable */
	public function asNullable(): self
	{
		return $this;
	}

	/** @form-modifier required */
	public function asRequired(): self
	{
		return $this;
	}

	/** @form-modifier bogus */
	public function unknownEffect(): self
	{
		return $this;
	}

	public function untagged(): self
	{
		return $this;
	}

}
