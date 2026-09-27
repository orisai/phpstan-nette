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
use function implode;
use function in_array;
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
		$schema = $extension['parametersSchema']['orisaiNette'];
		self::assertInstanceOf(Entity::class, $schema);

		$schemaPaths = self::schemaPaths($schema, '');
		$schemaLeaves = self::leaves($schemaPaths);
		self::assertSame(self::SCHEMA_LEAVES, $schemaLeaves);

		$docs = self::read($root . '/docs/README.md');
		$documented = [];
		foreach (self::configurationNeonBlocks($docs) as $block) {
			$decoded = Neon::decode($block);
			$orisaiNette = $decoded['parameters']['orisaiNette'] ?? $decoded['orisaiNette'] ?? null;
			if (is_array($orisaiNette)) {
				$documented = array_merge($documented, self::documentedPaths($orisaiNette, '', $schemaPaths));
			}
		}

		preg_match_all('~orisaiNette\.([A-Za-z][A-Za-z.]*[A-Za-z])~', $docs, $mentions);
		$documented = array_merge($documented, $mentions[1]);
		$documented = array_values(array_unique($documented));
		sort($documented);

		$missing = array_values(array_diff($schemaLeaves, $documented));
		$unknown = array_values(array_diff($documented, array_keys($schemaPaths)));

		self::markTestIncomplete(sprintf(
			'docs arrive in Task 11; undocumented: [%s]; unknown: [%s]',
			implode(', ', $missing),
			implode(', ', $unknown),
		));
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

	/**
	 * @return list<string>
	 */
	private static function configurationNeonBlocks(string $docs): array
	{
		if (preg_match('~^(#{1,6}) Configuration\b.*$~m', $docs, $heading, PREG_OFFSET_CAPTURE) !== 1) {
			return [];
		}

		$level = strlen($heading[1][0]);
		$section = (string) substr($docs, $heading[0][1] + strlen($heading[0][0]));
		if (preg_match(sprintf('~^#{1,%d} ~m', $level), $section, $next, PREG_OFFSET_CAPTURE) === 1) {
			$section = (string) substr($section, 0, $next[0][1]);
		}

		preg_match_all('~^```neon\n(.*?)^```~ms', $section, $blocks);

		$result = [];
		foreach ($blocks[1] as $block) {
			if (!in_array($block, $result, true)) {
				$result[] = $block;
			}
		}

		return $result;
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
