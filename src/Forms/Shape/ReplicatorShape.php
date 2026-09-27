<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Shape;

final class ReplicatorShape
{

	private FormShape $inner;

	private FormShape $own;

	public function __construct(FormShape $inner, FormShape $own)
	{
		$this->inner = $inner;
		$this->own = $own;
	}

	public function getInner(): FormShape
	{
		return $this->inner;
	}

	/**
	 * The children added straight onto the value addDynamic()/addMultiplier() itself returns
	 * (e.g. `$reservationsParameters->addSubmit('addNode', …)`) - as opposed to $inner, which is
	 * the per-ROW shape built from the item-factory closure. Nette's Container::getComponent()
	 * descends into one or the other depending on whether the offset is an integer (a row) or a
	 * name (an own child) - see FormReplicatorType.
	 */
	public function getOwn(): FormShape
	{
		return $this->own;
	}

}
