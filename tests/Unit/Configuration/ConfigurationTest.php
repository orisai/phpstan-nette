<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Configuration;

use OriPhpstan\Nette\Configuration\Configuration;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;
use function implode;

final class ConfigurationTest extends BaseTestCase
{

	public function testDefaultsAreAccepted(): void
	{
		$configuration = new Configuration(TestGuard::defaults());

		self::assertSame(TestGuard::defaults(), $configuration->toArray());
	}

	public function testGetReadsANestedOption(): void
	{
		$config = TestGuard::defaults();
		$config['latte']['discovery']['storePath'] = '/store';

		self::assertSame('/store', (new Configuration($config))->get('latte.discovery.storePath'));
	}

	public function testGetRejectsAnUnknownOption(): void
	{
		$configuration = new Configuration(TestGuard::defaults());

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage('orisai.nette.latte.discovery.nope is not a configuration option.');

		$configuration->get('latte.discovery.nope');
	}

	public function testUnknownOptionIsRejected(): void
	{
		$config = TestGuard::defaults();
		$config['latte']['enabledd'] = true;

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage('Unexpected item ' . self::item('latte', 'enabledd'));

		new Configuration($config);
	}

	public function testWrongTypeIsRejected(): void
	{
		$config = TestGuard::defaults();
		$config['latte']['enabled'] = 'yes';

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage(
			'The item ' . self::item('latte', 'enabled') . " expects to be bool, 'yes' given.",
		);

		new Configuration($config);
	}

	public function testWrongFormulaShapeIsRejected(): void
	{
		$config = TestGuard::defaults();
		$config['latte']['discovery']['formulas'] = ['X' => ['formula' => ['samedir-single']]];

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage(
			'The item ' . self::item(
				'latte',
				'discovery',
				'formulas',
				'X',
				'formula',
			) . ' expects to be string, array given.',
		);

		new Configuration($config);
	}

	public function testMissingOptionIsRejected(): void
	{
		$config = TestGuard::defaults();
		unset($config['forms']['enabled']);

		$this->expectException(InvalidConfiguration::class);
		$this->expectExceptionMessage('The mandatory item ' . self::item('forms', 'enabled') . ' is missing.');

		new Configuration($config);
	}

	private static function item(string ...$keys): string
	{
		return "'" . implode("\u{a0}›\u{a0}", ['parameters', 'orisai', 'nette', ...$keys]) . "'";
	}

}
