<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Configuration;

use Tests\OriPhpstan\Nette\Toolkit\ScratchProject;

final class ConfigurationCorpus
{

	public const PATHS = ['src'];

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

}

PHP);
		$project->write('src/ProfileControl.php', <<<'PHP'
<?php declare(strict_types = 1);

namespace Corpus;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

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
