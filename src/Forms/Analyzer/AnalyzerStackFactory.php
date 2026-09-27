<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Catalog\NetteEffectiveControlValueTypeResolver;
use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Graph\NodeContributionSummaryFactory;
use PHPStan\Parser\Parser;

/**
 * The single funnel every FormShapeAnalyzer is built through. The parser it takes MUST be the rich
 * one: FormShapeCache entries are shared between every consumer, so two consumers holding different
 * parsers would compute different shapes for the same key and poison each other.
 *
 * $analysedPaths carries the same hazard and the same rule: it decides which declarations count as
 * OURS, so every consumer must be given the config's declared paths (`%paths%`). An empty list means
 * no universe was declared and nothing may be called foreign — the fail-safe default a harness that
 * wires none falls back to, never a second opinion held alongside a wired one.
 */
final class AnalyzerStackFactory
{

	/**
	 * @param list<string> $analysedPaths
	 */
	public static function build(
		ControlAnnotationValueTypeReader $catalogReader,
		FormShapeCache $cache,
		Parser $richParser,
		array $analysedPaths = []
	): FormShapeAnalyzer
	{
		$callees = self::buildCallees($catalogReader, $cache, $richParser);

		return new FormShapeAnalyzer(
			self::buildFactory($catalogReader, $cache, $callees, $analysedPaths),
			$cache,
			$callees,
		);
	}

	public static function buildCallees(
		ControlAnnotationValueTypeReader $catalogReader,
		FormShapeCache $cache,
		Parser $richParser
	): CalleeShapeResolver
	{
		return new CalleeShapeResolver($richParser, $catalogReader->getReflectionProvider(), $cache);
	}

	/**
	 * @param list<string> $analysedPaths
	 */
	public static function buildFactory(
		ControlAnnotationValueTypeReader $catalogReader,
		FormShapeCache $cache,
		CalleeShapeResolver $callees,
		array $analysedPaths = []
	): NodeContributionSummaryFactory
	{
		return new NodeContributionSummaryFactory(
			new NetteEffectiveControlValueTypeResolver($catalogReader, $callees, $analysedPaths),
			$cache->recorder(),
		);
	}

}
