<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Integration;

use Nette\Application\UI\Presenter;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Integration\Configuration\ConfigurationCorpus;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function sort;
use function str_replace;
use const PHP_VERSION_ID;

// Filters PHP code registers on a template or its engine before rendering it are known to the
// templates its renderers render, and to everything those include, extend or use as a layout, typed
// with the registered callable's own signature. A template no registering renderer reaches still
// reports the name unknown.
final class RenderTimeFiltersSpawnTest extends BaseTestCase
{

	private ScratchProject $project;

	protected function setUp(): void
	{
		parent::setUp();
		$this->project = ScratchProject::create('render-time-filters');
	}

	protected function tearDown(): void
	{
		$this->project->cleanup();
		parent::tearDown();
	}

	public function testRegisteredFiltersAreTypedWhereverTheirRenderersReach(): void
	{
		$createTemplateParameters = (new ReflectionMethod(
			Presenter::class,
			'createTemplate',
		))->getNumberOfParameters() > 0
			? '?string $class = null'
			: '';

		$this->project->write('src/Functions.php', <<<'PHP'
<?php declare(strict_types = 1);

final class Functions
{

	public static function email(string $email): string
	{
		return $email;
	}

}

PHP);
		$this->project->write('src/PhoneNumberFormatter.php', <<<'PHP'
<?php declare(strict_types = 1);

final class PhoneNumberFormatter
{

	public static function formatInternational(string $number): string
	{
		return $number;
	}

}

PHP);
		$this->project->write('src/HelpersLoader.php', <<<'PHP'
<?php declare(strict_types = 1);

final class HelpersLoader
{

	public function formatDescription(string $description): string
	{
		return $description;
	}

}

PHP);
		$this->project->write('src/TDescriptionHelper.php', <<<PHP
<?php declare(strict_types = 1);

trait TDescriptionHelper
{

	public function formatDescription(string \$description): string
	{
		return \$description;
	}

	protected function createTemplate($createTemplateParameters): Nette\\Application\\UI\\Template
	{
		/** @var Nette\\Bridges\\ApplicationLatte\\Template \$template */
		\$template = parent::createTemplate();
		\$template->addFilter('formatDescription', [\$this, 'formatDescription']);

		return \$template;
	}

}

PHP);
		$this->project->write('src/BasePresenter.php', <<<PHP
<?php declare(strict_types = 1);

/**
 * @property-read Nette\\Bridges\\ApplicationLatte\\DefaultTemplate \$template
 */
abstract class BasePresenter extends Nette\\Application\\UI\\Presenter
{

	protected function createTemplate($createTemplateParameters): Nette\\Application\\UI\\Template
	{
		/** @var Nette\\Bridges\\ApplicationLatte\\Template \$template */
		\$template = parent::createTemplate();
		\$template->getLatte()->addFilter('email', 'Functions::email');

		return \$template;
	}

	protected function beforeRender(): void
	{
		\$this->template->addFilter('visibleForRole', function (?string \$disableFor): string {
			return (string) \$disableFor;
		});
	}

}

PHP);
		$this->project->write('src/NewsPresenter.php', <<<PHP
<?php declare(strict_types = 1);

final class NewsPresenter extends BasePresenter
{

	private HelpersLoader \$helpersLoader;

	public function __construct(HelpersLoader \$helpersLoader)
	{
		parent::__construct();
		\$this->helpersLoader = \$helpersLoader;
	}

	protected function createTemplate($createTemplateParameters): Nette\\Application\\UI\\Template
	{
		/** @var Nette\\Bridges\\ApplicationLatte\\Template \$template */
		\$template = parent::createTemplate();
		\$template->addFilter('formatDescription', [\$this->helpersLoader, 'formatDescription']);

		return \$template;
	}

	public function actionDefault(): void
	{
		\$this->template->addFilter('isSlideTypeInProject', function (int \$slideType): Nette\\Utils\\Html {
			return Nette\\Utils\\Html::el('span', (string) \$slideType);
		});
	}

	public function renderDefault(): void
	{
		\$latte = \$this->template->getLatte();
		\$latte->addFilter('phoneNumber', [PhoneNumberFormatter::class, 'formatInternational']);
		\$this->template->getLatte()->addFilter('mail', [Functions::class, 'email']);
		\$this->template->setFile(__DIR__ . '/news.latte');
	}

}

PHP);
		$this->project->write('src/ApiPresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

final class ApiPresenter extends BasePresenter
{

	use TDescriptionHelper;

	public function renderDefault(): void
	{
		$this->template->setFile(__DIR__ . '/api.latte');
	}

}

PHP);
		$this->project->write('src/GuestPresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

final class GuestPresenter extends BasePresenter
{

	public function renderDefault(bool $wide): void
	{
		if ($wide) {
			$this->template->addFilter('widen', 'strtoupper');
		}

		$this->template->addFilter('opaque', $this->makeFilter());
		$this->template->setFile(__DIR__ . '/guest.latte');
	}

	private function makeFilter(): callable
	{
		return 'strtolower';
	}

}

PHP);
		$this->project->write('src/OtherControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class OtherControl extends Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->template->render(__DIR__ . '/other.latte', ['title' => 'Other']);
	}

}

PHP);
		$this->project->write('src/DynamicControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class DynamicControl extends Nette\Application\UI\Control
{

	public function render(string $name): void
	{
		$this->template->addFilter($name, 'strtoupper');
		$html = $this->template->renderToString(__DIR__ . '/dynamic.latte');
		echo $html;
	}

}

PHP);
		$this->project->write(
			'src/news.latte',
			"{layout 'layout.latte'}\n"
			. "{block content}\n"
			. "{='admin'|visibleForRole}\n"
			. "{=1|visibleForRole}\n"
			. "{=1|isSlideTypeInProject}\n"
			. "{='slide'|isSlideTypeInProject}\n"
			. "{=1|phoneNumber}\n"
			. "{=1|mail}\n"
			. "{=1|formatDescription}\n"
			. "{include 'partial.latte'}\n"
			. "{/block}\n",
		);
		$this->project->write('src/layout.latte', "{=1|email}\n{include content}\n");
		$this->project->write('src/partial.latte', "{=1|phoneNumber}\n{='x'|nope}\n");
		$this->project->write('src/api.latte', "{=1|formatDescription}\n{=1|email}\n{=1|phoneNumber}\n");
		$this->project->write('src/guest.latte', "{=1|widen}\n{='x'|opaque}\n");
		$this->project->write('src/other.latte', "{='x'|visibleForRole}\n");
		$this->project->write('src/dynamic.latte', "{='x'|anything}\n");

		self::assertSame(
			[
				'api.latte:1 argument.type Parameter #1 $description of method ApiPresenter::formatDescription() expects string, int given.',
				'api.latte:2 argument.type Parameter #1 $email of static method Functions::email() expects string, int given.',
				"api.latte:3 orisai.nette.latte.unknownFilter Unknown Latte filter 'phoneNumber'.",
				'guest.latte:1 argument.type Parameter #1 $' . (PHP_VERSION_ID >= 80000 ? 'string' : 'str')
					. ' of function strtoupper expects string, int given.',
				'layout.latte:1 argument.type Parameter #1 $email of static method Functions::email() expects string, int given.',
				'news.latte:4 argument.type Parameter #1 $disableFor of closure expects string|null, int given.',
				'news.latte:6 argument.type Parameter #1 $slideType of closure expects int, string given.',
				'news.latte:7 argument.type Parameter #1 $number of static method PhoneNumberFormatter::formatInternational() expects string, int given.',
				'news.latte:8 argument.type Parameter #1 $email of static method Functions::email() expects string, int given.',
				'news.latte:9 argument.type Parameter #1 $description of method HelpersLoader::formatDescription() expects string, int given.',
				'other.latte:1 orisai.nette.latte.unknownFilter Unknown Latte filter \'visibleForRole\'.',
				'partial.latte:1 argument.type Parameter #1 $number of static method PhoneNumberFormatter::formatInternational() expects string, int given.',
				"partial.latte:2 orisai.nette.latte.unknownFilter Unknown Latte filter 'nope'.",
			],
			$this->analyse(),
		);
	}

	/**
	 * @requires PHP >= 8.1
	 */
	public function testFirstClassCallablesAreTyped(): void
	{
		$this->project->write('src/Formatter.php', <<<'PHP'
<?php declare(strict_types = 1);

final class Formatter
{

	public static function money(int $cents): string
	{
		return (string) $cents;
	}

	public function percent(float $value): string
	{
		return (string) $value;
	}

}

PHP);
		$this->project->write('src/PriceControl.php', <<<'PHP'
<?php declare(strict_types = 1);

/**
 * @property-read Nette\Bridges\ApplicationLatte\DefaultTemplate $template
 */
final class PriceControl extends Nette\Application\UI\Control
{

	public function render(): void
	{
		$this->template->addFilter('money', Formatter::money(...));
		$this->template->addFilter('percent', (new Formatter())->percent(...));
		$this->template->addFilter('upper', strtoupper(...));
		$this->template->addFilter('own', $this->own(...));
		$this->template->setFile(__DIR__ . '/price.latte');
		$this->template->render();
	}

	private function own(int $value): string
	{
		return (string) $value;
	}

}

PHP);
		$this->project->write('src/price.latte', "{='x'|money}\n{='x'|percent}\n{=1|upper}\n{='x'|own}\n{=1|money}\n");

		self::assertSame(
			[
				'price.latte:1 argument.type Parameter #1 $cents of static method Formatter::money() expects int, string given.',
				'price.latte:2 argument.type Parameter #1 $value of method Formatter::percent() expects float, string given.',
				'price.latte:3 argument.type Parameter #1 $string of function strtoupper expects string, int given.',
				'price.latte:4 argument.type Parameter #1 $value of closure expects int, string given.',
			],
			$this->analyse(['phpVersion' => 80100]),
		);
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return list<string>
	 */
	private function analyse(array $parameters = []): array
	{
		$result = $this->project->analyse(
			$parameters + ConfigurationCorpus::PHPSTAN_NETTE_SWITCHES + [
				'fileExtensions' => ['php', 'latte'],
				'orisai' => [
					'nette' => [
						'latte' => [
							'enabled' => true,
							'discovery' => [
								'enabled' => true,
								'storePath' => $this->project->path('discovery'),
							],
							'firstPartyPaths' => [$this->project->path('src')],
						],
					],
				],
			],
			['src'],
		);
		self::assertSame([], $result['errors'], $result['stderr']);

		$findings = [];
		foreach ($result['messages'] as $message) {
			if ($message['identifier'] === 'orisai.nette.latte.orphanTemplate') {
				continue;
			}

			$findings[] = str_replace($this->project->path('src') . '/', '', $message['file']) . ':' . $message['line']
				. ' ' . $message['identifier'] . ' ' . $message['message'];
		}

		sort($findings);

		return $findings;
	}

}
