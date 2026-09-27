<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index\Fixtures;

trait NotificationLogTrait
{

}

trait NotificationFormTrait
{

	use NotificationLogTrait;

	public function createComponentNotification()
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'notificationSucceeded'];

		return $form;
	}

}

class UsesNotificationForm
{

	use NotificationFormTrait;

}
