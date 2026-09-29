<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Latte\Declarations\VarTypePlacement;
use OriPhpstan\Nette\Latte\Forms\FormSite;
use OriPhpstan\Nette\Latte\Version\ExtractedFacts;
use function array_map;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

// The whole ExtractedFacts surface as one JSON document, in a fixed key order and in the adapter's
// own array/list order: what the Latte 2 adapter produced for a fixture is what every other line
// must produce for it.
final class FactsJson
{

	/**
	 * @return array<string, mixed>
	 */
	public static function normalize(ExtractedFacts $facts): array
	{
		$declarations = $facts->getDeclarations();

		return [
			'declarations' => [
				'templateTypeClass' => $declarations->getTemplateTypeClass(),
				'templateTypeLine' => $declarations->getTemplateTypeLine(),
				'headerVarTypes' => $declarations->getHeaderVarTypes(),
				'headerVarTypeLines' => $declarations->getHeaderVarTypeLines(),
				'midFileVarTypes' => $declarations->getMidFileVarTypes(),
				'typedVars' => $declarations->getTypedVars(),
				'typedDefaults' => $declarations->getTypedDefaults(),
				'parameters' => $declarations->getParameters(),
				'defineParams' => $declarations->getDefineParams(),
				'defineParamDefaults' => $declarations->getDefineParamDefaults(),
				'varTypePlacements' => array_map(
					static fn (VarTypePlacement $placement): array => [
						'name' => $placement->getName(),
						'type' => $placement->getType(),
						'line' => $placement->getLine(),
						'anchorKind' => $placement->getAnchorKind(),
						'anchorLine' => $placement->getAnchorLine(),
						'anchorLabel' => $placement->getAnchorLabel(),
						'boundVariables' => $placement->getBoundVariables(),
						'runSize' => $placement->getRunSize(),
						'neverAssigned' => $placement->isNeverAssigned(),
						'knownBeforeTag' => $placement->isKnownBeforeTag(),
						'insideBlockBody' => $placement->isInsideBlockBody(),
					],
					$declarations->getVarTypePlacements(),
				),
			],
			'formSites' => array_map(static fn (FormSite $site): array => $site->toArray(), $facts->getFormSites()),
			'templateFacts' => $facts->getTemplateFacts()->toArray(),
		];
	}

	public static function encode(ExtractedFacts $facts): string
	{
		return json_encode(
			self::normalize($facts),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		) . "\n";
	}

}
