<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\DependencyRecorder;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Cache\FormsValueExtension;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Index\FileFactIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationIndex;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PHPStan\Parser\Parser;
use PHPStan\Testing\PHPStanTestCase;
use Tests\OriPhpstan\Nette\Toolkit\RecordingDependencyTracker;
use Tests\OriPhpstan\Nette\Toolkit\TestFileFinder;
use Tests\OriPhpstan\Nette\Toolkit\VersionGroupGate;
use function assert;
use function getcwd;
use function getmypid;
use function sys_get_temp_dir;
use function uniqid;

final class FormsDependencyTrackingTest extends PHPStanTestCase
{

	use VersionGroupGate;

	private ?string $dir = null;

	protected function tearDown(): void
	{
		if ($this->dir !== null) {
			FileSystem::delete($this->dir);
			$this->dir = null;
		}
	}

	public function testEveryFileAndContributorSetTheComputationReadBecomesADependency(): void
	{
		$recorder = new DependencyRecorder();
		$tracker = new RecordingDependencyTracker();
		$ipKey = InterproceduralShapeKey::forMethodParam('App\\Presenter', 'handle', 0);

		$recorder->track($tracker, static function () use ($recorder, $ipKey): void {
			$recorder->record(__FILE__);
			// A store entry computed inside the tracked computation: its reads belong to both.
			$recorder->capture(static function () use ($recorder, $ipKey): void {
				$recorder->recordFingerprint($ipKey, 'fingerprint');
			});
		});

		self::assertSame(
			[
				[FormsValueExtension::class, FormsValueExtension::sourceKey(__FILE__)],
				[FormsValueExtension::class, FormsValueExtension::contributorsKey($ipKey)],
			],
			$tracker->values,
		);
		self::assertFalse($recorder->hasActiveFrame(), 'the tracked frame must be closed again');
	}

	public function testAMemoisedAnswerReplaysItsDependenciesIntoEveryLaterReader(): void
	{
		$recorder = new DependencyRecorder();
		[, $dependencies] = $recorder->capture(static function () use ($recorder): void {
			$recorder->record(__FILE__);
		});

		$tracker = new RecordingDependencyTracker();
		$recorder->track($tracker, static function () use ($recorder, $dependencies): void {
			$recorder->replay($dependencies);
		});

		self::assertSame([[FormsValueExtension::class, FormsValueExtension::sourceKey(__FILE__)]], $tracker->values);
	}

	public function testAShapeSourceIsValuedByItsTokensNotItsLayout(): void
	{
		$file = $this->dir() . '/Builder.php';
		$extension = $this->extension();
		$key = FormsValueExtension::sourceKey($file);

		FileSystem::write(
			$file,
			"<?php\n/**\n * @form-adds \$name\n */\nfunction a(\$form) { \$form->addText('x'); }\n",
		);
		$original = $extension->getValue($key);

		FileSystem::write(
			$file,
			"<?php\n\t/**\n\t * @form-adds \$name\n\t */\n// a comment\nfunction a(\$form)\n{\n\t\$form->addText('x'); /* another */\n}\n",
		);
		self::assertSame(
			$original,
			$extension->getValue($key),
			'comments, whitespace and docblock layout carry no shape',
		);

		FileSystem::write(
			$file,
			"<?php\n/**\n * @form-adds \$name\n */\nfunction a(\$form) { \$form->addText('y'); }\n",
		);
		self::assertNotSame($original, $extension->getValue($key));

		FileSystem::write(
			$file,
			"<?php\n/**\n * @form-adds \$label\n */\nfunction a(\$form) { \$form->addText('x'); }\n",
		);
		self::assertNotSame($original, $extension->getValue($key), 'a doc comment tag is shape content');

		FileSystem::delete($file);
		self::assertSame('missing', $extension->getValue($key));
	}

	public function testAShapeSourceUnderTheProjectRootIsStoredRelative(): void
	{
		$extension = $this->extension();
		$inside = FormsValueExtension::sourceKey((string) getcwd() . '/src/Builder.php');
		$outside = FormsValueExtension::sourceKey('/elsewhere/Builder.php');

		self::assertSame(FormsValueExtension::sourceKey('src/Builder.php'), $extension->keyToResultCache($inside));
		self::assertSame($inside, $extension->keyFromResultCache($extension->keyToResultCache($inside)));
		self::assertSame($outside, $extension->keyFromResultCache($extension->keyToResultCache($outside)));
	}

	public function testAContributorKeyIsValuedByTheCurrentFingerprint(): void
	{
		$ipKey = InterproceduralShapeKey::forMethodParam('App\\Nowhere', 'handle', 0);
		$index = $this->registrationIndex();

		self::assertSame(
			$index->fingerprint($ipKey),
			(new FormsValueExtension($index, (string) getcwd()))->getValue(
				FormsValueExtension::contributorsKey($ipKey),
			),
		);
	}

	private function extension(): FormsValueExtension
	{
		return new FormsValueExtension($this->registrationIndex(), (string) getcwd());
	}

	private function registrationIndex(): RegistrationIndex
	{
		$parser = self::getContainer()->getService('defaultAnalysisParser');
		assert($parser instanceof Parser);
		$cache = new FormShapeCache($this->dir() . '/store');

		return new RegistrationIndex(
			[__DIR__ . '/Index/Fixtures'],
			TestFileFinder::create((string) getcwd()),
			new FileFactIndex($cache, $parser, new RegistrationRecognizer()),
		);
	}

	private function dir(): string
	{
		if ($this->dir === null) {
			$this->dir = sys_get_temp_dir() . '/forms-dependency-tracking-' . getmypid() . '-' . uniqid('', true);
			FileSystem::createDir($this->dir);
		}

		return $this->dir;
	}

}
