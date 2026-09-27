<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

final class ConfigurationCorpus
{

	public const PATHS = ['src'];

	// Required setup: phpstan-nette's own component and getValues() typing otherwise pre-empts the extension's.
	public const PHPSTAN_NETTE_SWITCHES = [
		'netteComponentModelDynamicReturnType' => false,
		'netteFormContainerValuesDynamicReturnType' => false,
	];

	/**
	 * @param list<array{file: string, line: int, message: string, identifier: string|null}> $messages
	 * @return list<string>
	 */
	public static function findings(ScratchProject $project, array $messages, string $identifierPrefix): array
	{
		$findings = [];
		foreach ($messages as $message) {
			$identifier = $message['identifier'] ?? '';
			if (strpos($identifier, $identifierPrefix) !== 0) {
				continue;
			}

			$findings[] = sprintf(
				'%s:%d %s',
				(string) substr($message['file'], strlen($project->path(''))),
				$message['line'],
				$identifier,
			);
		}

		return $findings;
	}

	public static function write(ScratchProject $project): void
	{
		$project->write('src/HomePresenter.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

use Nette\Application\UI\Presenter;

final class HomePresenter extends Presenter
{

	protected function createComponentProfile(): ProfileControl
	{
		return new ProfileControl();
	}

	public function renderDefault(): void
	{
		$detached = new ProfileControl();
		$detached->getPresenter();
	}

}

PHP);
		$project->write('src/ProfileControl.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\ComponentModel\IComponent;

final class ProfileControl extends Control
{

	public function render(): void
	{
		$this->template->setFile(__DIR__ . '/tpl.latte');
		$this->template->render();
	}

	protected function createComponentForm(): Form
	{
		$form = new Form();
		$form->addText('name', 'Name');

		return $form;
	}

	public function nope(): IComponent
	{
		return $this['form']['nope'];
	}

}

PHP);
		$project->write('src/tpl.latte', "{form form}{input nope}{/form}\n");
		$project->write('src/AppContainer.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

use Nette\DI\Container;

final class AppContainer extends Container
{

}

PHP);
		$project->write('container-loader.php', <<<'PHP'
<?php declare(strict_types = 1);

return new Nette\DI\Container();

PHP);
	}

}
