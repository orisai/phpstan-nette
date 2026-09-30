<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use RuntimeException;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_diff;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function is_array;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function strlen;
use function substr;
use const PREG_OFFSET_CAPTURE;

final class ConfigReferenceCoverageTest extends BaseTestCase
{

	private const SCHEMA_LEAVES = [
		'component.enabled',
		'dic.containerLoader',
		'forms.catalogs',
		'forms.defaultContainerClass',
		'forms.enabled',
		'forms.internals.indexShadowCompare',
		'forms.reportUnannotatedRegistrars',
		'latte.allowNarrowingOverride',
		'latte.discovery.coarseInvalidationAccepted',
		'latte.discovery.enabled',
		'latte.discovery.formulas',
		'latte.discovery.storePath',
		'latte.enabled',
		'latte.engineLoader',
		'latte.firstPartyPaths',
		'latte.includeIsolation',
		'latte.narrowing.enabled',
		'latte.narrowing.storePath',
		'latte.reportAnyTypeWideningInVarType',
		'latte.reportWrongPhpDocTypeInVarType',
		'latte.templateFactoryContainerLoader',
		'latte.templateTypeRequired',
	];

	public function testDocumentedKeysMatchSchema(): void
	{
		$root = dirname(__DIR__, 3);
		$extension = Neon::decode(self::read($root . '/extension.neon'));
		$orisai = $extension['parametersSchema']['orisai'];
		self::assertInstanceOf(Entity::class, $orisai);
		$schema = $orisai->attributes[0]['nette'];
		self::assertInstanceOf(Entity::class, $schema);

		$schemaPaths = self::schemaPaths($schema, '');
		$schemaLeaves = self::leaves($schemaPaths);
		self::assertSame(self::SCHEMA_LEAVES, $schemaLeaves);

		$section = self::configurationSection(self::read($root . '/docs/README.md'));
		$inNeon = [];
		foreach (self::neonBlocks($section) as $block) {
			$decoded = Neon::decode($block);
			$nette = $decoded['parameters']['orisai']['nette'] ?? $decoded['orisai']['nette'] ?? null;
			if (is_array($nette)) {
				$inNeon = array_merge($inNeon, self::documentedPaths($nette, '', $schemaPaths));
			}
		}

		preg_match_all('~orisai\.nette\.([A-Za-z][A-Za-z.]*[A-Za-z])~', $section, $mentions);

		$missing = array_values(array_diff($schemaLeaves, $inNeon));
		$unknown = array_values(array_unique(array_diff(
			array_merge($inNeon, $mentions[1]),
			array_keys($schemaPaths),
		)));
		sort($unknown);

		self::assertSame([], $missing);
		self::assertSame([], $unknown);
	}

	/**
	 * @return array<string, bool>
	 */
	private static function schemaPaths(Entity $structure, string $prefix): array
	{
		$paths = [];
		$fields = $structure->attributes[0];
		self::assertIsArray($fields);
		foreach ($fields as $key => $field) {
			$path = $prefix . $key;
			$isStructure = $field instanceof Entity && $field->value === 'structure';
			$paths[$path] = !$isStructure;
			if ($isStructure) {
				$paths = array_merge($paths, self::schemaPaths($field, $path . '.'));
			}
		}

		return $paths;
	}

	/**
	 * @param array<string, bool> $schemaPaths
	 * @return list<string>
	 */
	private static function leaves(array $schemaPaths): array
	{
		$leaves = [];
		foreach ($schemaPaths as $path => $isLeaf) {
			if ($isLeaf) {
				$leaves[] = $path;
			}
		}

		sort($leaves);

		return $leaves;
	}

	/**
	 * @param array<mixed> $values
	 * @param array<string, bool> $schemaPaths
	 * @return list<string>
	 */
	private static function documentedPaths(array $values, string $prefix, array $schemaPaths): array
	{
		$paths = [];
		foreach ($values as $key => $value) {
			$path = $prefix . $key;
			$paths[] = $path;
			if (($schemaPaths[$path] ?? true) === false && is_array($value)) {
				$paths = array_merge($paths, self::documentedPaths($value, $path . '.', $schemaPaths));
			}
		}

		return $paths;
	}

	private static function configurationSection(string $docs): string
	{
		if (preg_match('~^(#{1,6}) Configuration\b.*$~m', $docs, $heading, PREG_OFFSET_CAPTURE) !== 1) {
			return '';
		}

		$level = strlen($heading[1][0]);
		$section = (string) substr($docs, $heading[0][1] + strlen($heading[0][0]));
		if (preg_match(sprintf('~^#{1,%d} ~m', $level), $section, $next, PREG_OFFSET_CAPTURE) === 1) {
			$section = (string) substr($section, 0, $next[0][1]);
		}

		return $section;
	}

	/**
	 * @return list<string>
	 */
	private static function neonBlocks(string $section): array
	{
		preg_match_all('~^```neon\n(.*?)^```~ms', $section, $blocks);

		return $blocks[1];
	}

	private static function read(string $path): string
	{
		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException(sprintf('Cannot read "%s".', $path));
		}

		return $content;
	}

}
