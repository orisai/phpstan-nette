<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\Form;

use Kdyby\Replicator\Container as ReplicatorContainer;
use Nette\ComponentModel\IContainer as ComponentContainer;
use Nette\Forms\Controls\SubmitButton;
use function assert;

final class CustomSubmitButton extends SubmitButton
{

	/**
	 * @param callable(ReplicatorContainer, ComponentContainer): (void)|null $callback
	 * @return $this
	 */
	public function addRemoveOnClick(?callable $callback = null): self
	{
		$this->setValidationScope([]);
		$this->onClick[] = static function (SubmitButton $button) use ($callback): void {
			/** @var ReplicatorContainer $replicator */
			$replicator = $button->lookup(ReplicatorContainer::class);
			$parent = $button->getParent();
			assert($parent !== null);

			if ($callback !== null) {
				$callback($replicator, $parent);
			}

			if (($form = $button->getForm(false)) !== null) {
				$form->onSuccess = [];
			}

			$replicator->remove($parent);
		};

		return $this;
	}

	/**
	 * @param callable(ReplicatorContainer, ComponentContainer|null): (void)|null $callback
	 * @return $this
	 */
	public function addCreateOnClick(bool $allowEmpty = false, ?callable $callback = null): self
	{
		$this->onClick[] = static function (SubmitButton $button) use ($allowEmpty, $callback): void {
			/** @var ReplicatorContainer $replicator */
			$replicator = $button->lookup(ReplicatorContainer::class);

			if ($allowEmpty === true || $replicator->isAllFilled() === true) {
				$newContainer = $replicator->createOne();
				if ($callback !== null) {
					$callback($replicator, $newContainer);
				}
			}

			$button->getForm()->onSuccess = [];
		};

		return $this;
	}

}
