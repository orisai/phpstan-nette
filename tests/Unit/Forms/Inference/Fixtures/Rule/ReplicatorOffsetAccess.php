<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;

final class ReplicatorOffsetAccess
{

	// no error — a replicator's rows are created on demand and keyed by index, so a runtime int
	// offset is legitimate
	public function runtimeIntOffset(int $i): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});

		$form['rows'][$i];
	}

	// no error — a string offset on a bare replicator leaf must fall back to the pre-existing
	// (silent) behaviour, not to a hard-coded No: a named control added straight onto the
	// replicator holder (e.g. an addNode button) is a real, legitimate pattern; only integer row
	// reads are this task's concern
	public function stringOffset(): void
	{
		$form = new ApplicationForm();
		$form->addDynamic('rows', static function (FormContainer $c): void {
			$c->addText('x');
		});

		$form['rows']['addNode'];
	}

	// sanity control — the core rule still reports a genuinely out-of-range constant array offset,
	// proving this harness detects offsetAccess.notFound when it should
	public function constantArrayOffsetStillReported(): void
	{
		$arr = [1, 2, 3];
		$arr[5];
	}

}
