<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Latte\Customs\CustomsHarvester;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\CapturedOverlay;
use OriPhpstan\Nette\Latte\Includes\ContextResolver;
use OriPhpstan\Nette\Latte\Includes\DeclarationConsistencyChecker;
use OriPhpstan\Nette\Latte\Includes\IncludeContractChecker;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\SiteScopeStore;
use OriPhpstan\Nette\Latte\Includes\TemplateEdgeIndex;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Postprocess\AnalysisPipeline;
use OriPhpstan\Nette\Latte\Postprocess\RichAttributeDecorator;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use function assert;

final class PipelineFactory
{

	// $projectRoot null: an empty-paths LatteUniverse, matching every pre-existing caller that
	// never threads a $relativePath into process()/dump() either - outgoingSites() for any path
	// is always [] then, so EdgeAnchorInjector/DeclarationInjector's own capture consumption are a
	// guaranteed no-op and those callers' golden output stays untouched. $store defaults to one
	// backed by a store directory that never exists, so callers not exercising narrowing keep getting a
	// guaranteed no-op overlay, byte-identical to before capture-overlay support existed. Callers exercising edge anchors
	// or capture consumption pass their fixture tree's root (and, if narrowing, a seeded store).
	// $narrowingEnabled defaults true: every pre-existing caller of this factory was written
	// against narrowing-live behaviour (pre-flag); callers pinning the opt-in flag itself pass
	// false explicitly. $providerScanEnabled defaults true for the identical reason: every
	// pre-existing caller was written against AnalysisPipeline's own unconditional-scan behaviour
	// (pre-flag) - production wiring is the only caller that pins it to %orisaiNette.latte.discovery.enabled%
	// explicitly (see Latte/wiring.neon's latteAnalysisPipeline service).
	public static function create(
		?string $projectRoot = null,
		?SiteScopeStore $store = null,
		bool $narrowingEnabled = true,
		?CustomsHarvester $harvester = null,
		bool $providerScanEnabled = true
	): AnalysisPipeline
	{
		$parser = PHPStanTestCase::getContainer()->getService('currentPhpVersionSimpleParser');
		assert($parser instanceof Parser);

		$root = $projectRoot ?? '';
		$universe = new LatteUniverse($projectRoot === null ? [] : [$projectRoot], $root);
		$capturedOverlay = new CapturedOverlay(
			$store ?? new SiteScopeStore($root . '/__absent_sitescope_store__'),
			$narrowingEnabled,
		);

		return new AnalysisPipeline(
			$parser,
			TestAdapter::create(),
			new TemplateEdgeIndex($universe, new TemplateFactExtractor()),
			new DeclarationScanner(),
			$universe,
			$capturedOverlay,
			$narrowingEnabled,
			$harvester,
			null,
			false,
			$providerScanEnabled,
		);
	}

	// Not the container's shared `latteContextResolver` (wired off the whole-project %paths%,
	// unaware of a router test's ad hoc project root): a lightweight resolver scoped to the
	// caller's own project root, mirroring the production wiring's collaborator graph
	// (LatteUniverse -> TemplateEdgeIndex -> ContextResolver) without a cache. $store defaults to
	// one backed by a store directory that never exists, so callers not exercising narrowing keep
	// getting a guaranteed no-op overlay, byte-identical to before capture-overlay support existed.
	public static function createContextResolver(
		string $projectRoot,
		?SiteScopeStore $store = null,
		bool $narrowingEnabled = true
	): ContextResolver
	{
		$universe = new LatteUniverse([$projectRoot], $projectRoot);

		return new ContextResolver(
			new TemplateEdgeIndex($universe, new TemplateFactExtractor()),
			new DeclarationScanner(),
			$universe,
			new CapturedOverlay(
				$store ?? new SiteScopeStore($projectRoot . '/__absent_sitescope_store__'),
				$narrowingEnabled,
			),
		);
	}

	// Not the container's shared `latteIncludeContractChecker`, for the same reason as
	// createContextResolver() above; $contextResolver must be the SAME instance the caller used to
	// resolve $relativePath's contexts, so cutCycleEdges() reflects this call's cuts. $store follows
	// the same absent-by-default degradation as createContextResolver() above.
	public static function createIncludeContractChecker(
		string $projectRoot,
		ContextResolver $contextResolver,
		?SiteScopeStore $store = null,
		bool $narrowingEnabled = true
	): IncludeContractChecker
	{
		$universe = new LatteUniverse([$projectRoot], $projectRoot);

		return new IncludeContractChecker(
			new TemplateEdgeIndex($universe, new TemplateFactExtractor()),
			new DeclarationScanner(),
			$universe,
			$contextResolver,
			PHPStanTestCase::getContainer()->getByType(TypeStringResolver::class),
			new CapturedOverlay(
				$store ?? new SiteScopeStore($projectRoot . '/__absent_sitescope_store__'),
				$narrowingEnabled,
			),
		);
	}

	// Not the container's shared `latteDeclarationConsistencyChecker`, for the same reason as
	// createContextResolver() above; scoped to the caller's own project root, uncached.
	public static function createDeclarationConsistencyChecker(
		string $projectRoot,
		bool $allowNarrowingOverride = false
	): DeclarationConsistencyChecker
	{
		$universe = new LatteUniverse([$projectRoot], $projectRoot);

		return new DeclarationConsistencyChecker(
			new TemplateEdgeIndex($universe, new TemplateFactExtractor()),
			PHPStanTestCase::getContainer()->getByType(TypeStringResolver::class),
			$allowNarrowingOverride,
		);
	}

	// Store-less by default, exactly like createSiteScopeStore()'s never-existing directory: with
	// the discovery store off the checker is fully dormant, so every pre-existing caller of this
	// factory keeps byte-identical output. The container is never touched in that state (both lazy
	// services are fetched behind the same flag), which is why the plain test container is enough.
	// Not the container's shared `latteTemplateEdgeIndex`, for the same reason as
	// createContextResolver() above; scoped to the caller's own project root, uncached.
	public static function createTemplateEdgeIndex(string $projectRoot): TemplateEdgeIndex
	{
		return new TemplateEdgeIndex(new LatteUniverse([$projectRoot], $projectRoot), new TemplateFactExtractor());
	}

	// Not the container's shared `latteSiteScopeStore`, for the same reason as
	// createContextResolver() above; a fresh instance backed by a store directory that never exists by
	// default, matching every other collaborator's absent-store degradation in this class.
	public static function createSiteScopeStore(string $projectRoot): SiteScopeStore
	{
		return new SiteScopeStore($projectRoot . '/__absent_sitescope_store__');
	}

	// PHPStanTestCase::getContainer()'s generic (project-config-free) container already tags every
	// #[AutowiredService] PhpParser\NodeVisitor - including ParentStmtTypesVisitor - under
	// RichParser::VISITOR_SERVICE_TAG: that tagging comes from vendor's own core config.neon
	// (AutowiredAttributeServicesExtension), not from this project's wiring, so the generic
	// container is a faithful stand-in for production's real one here.
	public static function createRichAttributeDecorator(): RichAttributeDecorator
	{
		return new RichAttributeDecorator(PHPStanTestCase::getContainer());
	}

}
