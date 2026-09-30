<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

final class StoreChangeSignal
{

	public const IDENTIFIER = 'orisai.nette.latte.narrowingStoreChanged';

	public const PRUNE_ENVIRONMENT_VARIABLE = 'ORISAI_NETTE_LATTE_NARROWING_PRUNE';

	private function __construct()
	{
	}

}
