<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Converge;

final class StoreChangeSignal
{

	public const IDENTIFIER = 'orisai.nette.latte.narrowingStoreChanged';

	public const PRUNE_REFUSED_IDENTIFIER = 'orisai.nette.latte.narrowingPruneRefused';

	public const PRUNE_ENVIRONMENT_VARIABLE = 'ORISAI_NETTE_LATTE_NARROWING_PRUNE';

	public const REPORT_ENVIRONMENT_VARIABLE = 'ORISAI_NETTE_LATTE_CONVERGE_REPORT';

	public const PRUNE_EVALUATED_SUFFIX = '.prune';

	private function __construct()
	{
	}

}
