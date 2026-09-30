<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Postprocess\Eliminator;

use OriPhpstan\Nette\Latte\Postprocess\Eliminator\FormsMacroEliminator;
use OriPhpstan\Nette\Latte\Version\ShapeFamily;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\EliminatorRun;

// Inputs are the three bridges' own compiled output: Latte 2 FormMacros (nette/forms 3.1-3.2),
// Latte 3 FormsExtension with nette/forms 3.1.7-3.2 (identical on 3.0 and 3.1 up to line markers)
// and nette/forms 3.3's provider runtime (incl. its {form scope}/{form detached} modes). Every
// case carries another bridge's shape untouched; a paired {label} becomes the one Html stand-in
// statement on every bridge.
final class FormsMacroEliminatorTest extends BaseTestCase
{

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testItemShapeReducesToTheHelpers(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$form = $this->global->formsStack[] = $this->global->uiControl['f'] /* line 1 */;
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo '<form';
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin(end($this->global->formsStack), ['class' => null], false) /* line 1 */;
		echo ' class="c">';
		echo ($ʟ_elem = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getControlPart())->attributes() /* line 2 */;
		echo ($ʟ_label = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getLabel())?->startTag() /* line 3 */;
		echo 'Name';
		echo $ʟ_label?->endTag() /* line 3 */;
		echo ($ʟ_label = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getLabel())?->addAttributes(['class' => 'c'])?->startTag() /* line 3 */;
		echo $ʟ_label?->endTag() /* line 3 */;
		echo ($ʟ_label = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getLabelPart('part'))?->startTag() /* line 3 */;
		echo $ʟ_label?->endTag() /* line 3 */;
		echo ($ʟ_label = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getLabelPart('part')) /* line 4 */;
		echo Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getError() /* line 5 */;
		echo Nette\Bridges\FormsLatte\Runtime::item($dyn, $this->global)->getControl() /* line 6 */;
		echo ($ʟ_elem = Nette\Bridges\FormsLatte\Runtime::item('sel', $this->global)->getControlPart())->attributes() /* line 7 */;
		echo $ʟ_elem->getHtml() /* line 7 */;
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(end($this->global->formsStack), false) /* line 1 */;
		echo '</form>';
		array_pop($this->global->formsStack);
		$form = $this->global->formsStack[] = is_object($ʟ_tmp = $obj) ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp] /* line 9 */;
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, ['class' => 'ajax']) /* line 9 */;
		echo Nette\Bridges\FormsLatte\Runtime::item('y', $this->global)->getControl() /* line 10 */;
		$this->global->formsStack[] = $formContainer = Nette\Bridges\FormsLatte\Runtime::item('c', $this->global) /* line 11 */;
		echo Nette\Bridges\FormsLatte\Runtime::item('z', $this->global)->getControl() /* line 12 */;
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack)) /* line 14 */;
		$form = $this->global->formsStack[] = $this->global->uiControl['ctx'] /* line 15 */;
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo Nette\Bridges\FormsLatte\Runtime::item('w', $this->global)->getControl() /* line 16 */;
		array_pop($this->global->formsStack) /* line 17 */;
		$this->global->forms->begin($form = $this->global->uiControl['p'], global: $this->global);
		echo $this->global->forms->get('q')->getControl();
		$this->global->forms->end();
		echo end($this->global->formsStack)["m"]->getControl();
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo '<form';
        echo ' class="c">';
        echo ($latteElem = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getControlPart())->attributes();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo 'Name';
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->addAttributes(['class' => 'c'])->startTag();
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo $latteLabel->endTag();
        echo $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getLabelPart('part');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getError();
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField($dyn)->getControl();
        echo ($latteElem = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('sel')->getControlPart())->attributes();
        echo $latteElem->getHtml();
        echo '</form>';
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::formObject($obj);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
        $formContainer = \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('z')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('ctx');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('w')->getControl();
        $this->global->forms->begin($form = $this->global->uiControl['p'], global: $this->global);
        echo $this->global->forms->get('q')->getControl();
        $this->global->forms->end();
        echo \end($this->global->formsStack)['m']->getControl();
PHP), self::eliminate(new ShapeFamily($latteLine, ShapeFamily::FORMS_ITEM), $php));
	}

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testProviderShapeReducesToTheHelpers(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$this->global->forms->begin($form = $this->global->uiControl['f'], global: $this->global) /* pos 1:7 */;
		echo '<form';
		echo $this->global->forms->renderFormBegin(['class' => null], false) /* pos 1:7 */;
		echo ' class="c">';
		echo ($ʟ_elem = $this->global->forms->get('x')->getControlPart())->attributes() /* pos 2:9 */;
		echo ($ʟ_label = $this->global->forms->get('x')->getLabel())?->startTag() /* pos 3:2 */;
		echo 'Name';
		echo $ʟ_label?->endTag() /* pos 3:18 */;
		echo ($ʟ_label = $this->global->forms->get('x')->getLabel())?->addAttributes(['class' => 'c'])?->startTag() /* pos 3:2 */;
		echo $ʟ_label?->endTag() /* pos 3:31 */;
		echo ($ʟ_label = $this->global->forms->get('x')->getLabelPart('part'))?->startTag() /* pos 3:2 */;
		echo $ʟ_label?->endTag() /* pos 3:20 */;
		echo ($ʟ_label = $this->global->forms->get('x')->getLabelPart('part')) /* pos 4:2 */;
		echo $this->global->forms->get('x')->getError() /* pos 5:2 */;
		echo $this->global->forms->get($dyn)->getControl() /* pos 6:2 */;
		echo ($ʟ_elem = $this->global->forms->get('sel')->getControlPart())->attributes() /* pos 7:10 */;
		echo $ʟ_elem->getHtml() /* pos 7:10 */;
		echo $this->global->forms->renderFormEnd(false) /* pos 1:7 */;
		echo '</form>';
		$this->global->forms->end();
		$this->global->forms->begin($form = (is_object($ʟ_tmp = $obj) ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp]), global: $this->global) /* pos 9:1 */;
		echo $this->global->forms->renderFormBegin(['class' => 'ajax']) /* pos 9:1 */;
		echo $this->global->forms->get('y')->getControl() /* pos 10:2 */;
		$this->global->forms->begin($formContainer = $this->global->forms->get('c', Nette\Forms\Container::class)) /* pos 11:2 */;
		echo $this->global->forms->get('z')->getControl() /* pos 12:3 */;
		$this->global->forms->end();
		$formContainer = $this->global->forms->getScope();
		echo $this->global->forms->renderFormEnd() /* pos 14:1 */;
		$this->global->forms->end();
		$this->global->forms->begin($form = $this->global->uiControl['ctx'], global: $this->global) /* pos 15:1 */;
		echo $this->global->forms->get('w')->getControl() /* pos 16:2 */;
		$this->global->forms->end();
		$this->global->forms->begin($form = (is_object($ʟ_tmp = 'sc') ? $ʟ_tmp : ($this->global->forms->isNested() ? $this->global->forms->get($ʟ_tmp, Nette\Forms\Container::class) : $this->global->uiControl[$ʟ_tmp])), global: $this->global) /* pos 18:1 */;
		echo $this->global->forms->get('a')->getControl() /* pos 19:2 */;
		$this->global->forms->end();
		$this->global->forms->begin($form = (is_object($ʟ_tmp = $v) ? $ʟ_tmp : ($this->global->forms->isNested() ? $this->global->forms->get($ʟ_tmp, Nette\Forms\Container::class) : $this->global->uiControl[$ʟ_tmp])), global: $this->global) /* pos 21:1 */;
		echo $this->global->forms->get('b')->getControl() /* pos 22:2 */;
		$this->global->forms->end();
		$this->global->forms->begin($form = $this->global->uiControl['dt'], detached: true, global: $this->global) /* pos 24:1 */;
		echo $this->global->forms->renderFormBegin([]) /* pos 24:1 */;
		echo $this->global->forms->renderFormEnd() /* pos 26:1 */;
		echo $this->global->forms->get('c')->getControl() /* pos 25:2 */;
		$this->global->forms->end();
		$this->global->forms->begin($form = (is_object($ʟ_tmp = $w) ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp]), detached: true, global: $this->global) /* pos 28:1 */;
		echo $this->global->forms->renderFormBegin([]) /* pos 28:1 */;
		echo $this->global->forms->renderFormEnd() /* pos 30:1 */;
		echo $this->global->forms->get('d')->getControl() /* pos 29:2 */;
		$this->global->forms->end();
		$form = $this->global->formsStack[] = $this->global->uiControl['p'];
		echo Nette\Bridges\FormsLatte\Runtime::item('q', $this->global)->getControl();
		array_pop($this->global->formsStack);
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo '<form';
        echo ' class="c">';
        echo ($latteElem = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getControlPart())->attributes();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo 'Name';
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->addAttributes(['class' => 'c'])->startTag();
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo $latteLabel->endTag();
        echo $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getLabelPart('part');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getError();
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField($dyn)->getControl();
        echo ($latteElem = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('sel')->getControlPart())->attributes();
        echo $latteElem->getHtml();
        echo '</form>';
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::formObject($obj);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
        $formContainer = \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('z')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('ctx');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('w')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('sc');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('a')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::formObject($v);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('b')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('dt');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('c')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::formObject($w);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('d')->getControl();
        $form = $this->global->formsStack[] = $this->global->uiControl['p'];
        echo \Nette\Bridges\FormsLatte\Runtime::item('q', $this->global)->getControl();
        \array_pop($this->global->formsStack);
PHP), self::eliminate(new ShapeFamily($latteLine, ShapeFamily::FORMS_PROVIDER), $php));
	}

	// Review focus: an {embed} block layer renders its form in a block method, where the provider
	// shape must reduce to the very Helpers::form('x') the resolver types $form['x'] from.
	public function testProviderShapeInsideEmbedLayer(): void
	{
		$php = "<?php\n\nfinal class T extends Latte\\Runtime\\Template\n{\n"
			. "\tpublic function main(array \$ʟ_args): void\n\t{\n"
			. "\t\t\$this->enterBlockLayer(1, get_defined_vars()) /* pos 1:1 */;\n"
			. "\t\ttry {\n"
			. "\t\t\t\$this->createTemplate('layout.latte', [], \"embed\")->renderToContentType('html') /* pos 1:1 */;\n"
			. "\t\t} finally {\n"
			. "\t\t\t\$this->leaveBlockLayer();\n"
			. "\t\t}\n\t}\n\n\n"
			. "\t/** {block content} on line 2 */\n"
			. "\tpublic function blockContent(array \$ʟ_args): void\n\t{\n"
			. "\t\textract(end(\$this->varStack));\n"
			. "\t\textract(\$ʟ_args);\n"
			. "\t\tunset(\$ʟ_args);\n\n"
			. "\t\t\$this->global->forms->begin(\$form = \$this->global->uiControl['myForm'], global: \$this->global) /* pos 3:3 */;\n"
			. "\t\techo \$this->global->forms->renderFormBegin([]) /* pos 3:3 */;\n"
			. "\t\techo \$this->global->forms->get('name')->getControl() /* pos 4:4 */;\n"
			. "\t\techo \$form['name']->getValue() /* pos 5:4 */;\n"
			. "\t\techo \$this->global->forms->renderFormEnd() /* pos 6:3 */;\n"
			. "\t\t\$this->global->forms->end();\n"
			. "\t}\n}\n";

		$expected = "<?php\n\nfinal class T extends \\Latte\\Runtime\\Template\n{\n"
			. "    public function main(array \$ʟ_args): void\n    {\n"
			. "        \$this->enterBlockLayer(1, \\get_defined_vars());\n"
			. "        try {\n"
			. "            \$this->createTemplate('layout.latte', [], \"embed\")->renderToContentType('html');\n"
			. "        } finally {\n"
			. "            \$this->leaveBlockLayer();\n"
			. "        }\n    }\n"
			. "    /** {block content} on line 2 */\n"
			. "    public function blockContent(array \$ʟ_args): void\n    {\n"
			. "        \\extract(\\end(\$this->varStack));\n"
			. "        \\extract(\$ʟ_args);\n"
			. "        unset(\$ʟ_args);\n"
			. "        \$form = \\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::form('myForm');\n"
			. "        echo \\OriPhpstan\\Nette\\Latte\\Runtime\\Helpers::formField('name')->getControl();\n"
			. "        echo \$form['name']->getValue();\n"
			. "    }\n}";

		self::assertSame(
			$expected,
			self::eliminate(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER), $php),
		);
	}

	public function testMacrosShapeReducesToTheHelpers(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$form = $this->global->formsStack[] = $this->global->uiControl["f"] /* line 1 */;
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo '<form';
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin(end($this->global->formsStack), ['class' => null], false);
		echo ' class="c">';
		$ʟ_input = $_input = end($this->global->formsStack)["x"];
		echo $ʟ_input->getControlPart()->attributes() /* line 2 */;
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabel()) echo $ʟ_label->startTag();
		echo 'Name';
		if ($ʟ_label) echo $ʟ_label->endTag();
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabel()) echo $ʟ_label->addAttributes(['class' => "c"])->startTag();
		if ($ʟ_label) echo $ʟ_label->endTag();
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabelPart("part")) echo $ʟ_label->startTag();
		if ($ʟ_label) echo $ʟ_label->endTag();
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabelPart("part")) echo $ʟ_label;
		echo end($this->global->formsStack)["x"]->getError() /* line 5 */;
		$ʟ_input = $_input = is_object($ʟ_tmp = $dyn) ? $ʟ_tmp : end($this->global->formsStack)[$ʟ_tmp]; echo $ʟ_input->getControl() /* line 6 */;
		$ʟ_input = $_input = end($this->global->formsStack)["sel"];
		echo $ʟ_input->getControlPart()->attributes() /* line 7 */;
		echo $ʟ_input->getControl()->getHtml() /* line 7 */;
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack), false) /* line 1 */;
		echo '</form>';
		$form = $this->global->formsStack[] = is_object($ʟ_tmp = $obj) ? $ʟ_tmp : $this->global->uiControl[$ʟ_tmp];
		Nette\Bridges\FormsLatte\Runtime::initializeForm($form);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormBegin($form, ['class' => 'ajax']) /* line 9 */;
		echo end($this->global->formsStack)["y"]->getControl() /* line 10 */;
		$this->global->formsStack[] = $formContainer = end($this->global->formsStack)["c"] /* line 11 */;
		echo end($this->global->formsStack)["z"]->getControl() /* line 12 */;
		array_pop($this->global->formsStack);
		$formContainer = end($this->global->formsStack);
		echo Nette\Bridges\FormsLatte\Runtime::renderFormEnd(array_pop($this->global->formsStack));
		$form = $this->global->formsStack[] = $this->global->uiControl["ctx"] /* line 15 */;
		echo end($this->global->formsStack)["w"]->getControl() /* line 16 */;
		array_pop($this->global->formsStack);
		echo Nette\Bridges\FormsLatte\Runtime::item('q', $this->global)->getControl();
		echo $this->global->forms->get('r')->getControl();
		$this->global->forms->end();
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('f');
        echo '<form';
        echo ' class="c">';
        $latteInput = $_input = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x');
        echo $latteInput->getControlPart()->attributes();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo 'Name';
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->addAttributes(['class' => "c"])->startTag();
        echo $latteLabel->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo $latteLabel->endTag();
        if ($latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getLabelPart("part")) {
            echo $latteLabel;
        }
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('x')->getError();
        $latteInput = $_input = \is_object($ʟ_tmp = $dyn) ? $ʟ_tmp : \end($this->global->formsStack)[$ʟ_tmp];
        echo $latteInput->getControl();
        $latteInput = $_input = \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('sel');
        echo $latteInput->getControlPart()->attributes();
        echo $latteInput->getControl()->getHtml();
        echo '</form>';
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::formObject($obj);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
        $formContainer = \OriPhpstan\Nette\Latte\Runtime\Helpers::formContainer('c');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('z')->getControl();
        $form = \OriPhpstan\Nette\Latte\Runtime\Helpers::form('ctx');
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('w')->getControl();
        echo \Nette\Bridges\FormsLatte\Runtime::item('q', $this->global)->getControl();
        echo $this->global->forms->get('r')->getControl();
        $this->global->forms->end();
PHP), self::eliminate(EliminatorRun::family(ShapeFamily::LATTE_2), $php));
	}

	// A dynamic reference keeps the bridge's getLabel() assignment, so the label temp may still be
	// null at the closing tag: both of its guards stay. A static reference right after it binds the
	// temp again and drops them.
	public function testDynamicPairedLabelKeepsItsGuardsOnTheMacrosShape(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		$ʟ_input = is_object($ʟ_tmp = $dyn) ? $ʟ_tmp : end($this->global->formsStack)[$ʟ_tmp];
		if ($ʟ_label = $ʟ_input->getLabel()) echo $ʟ_label->addAttributes(['class' => "c"])->startTag();
		echo 'L';
		if ($ʟ_label) echo $ʟ_label->endTag();
		if ($ʟ_label = end($this->global->formsStack)["x"]->getLabel()) echo $ʟ_label->startTag();
		echo 'S';
		if ($ʟ_label) echo $ʟ_label->endTag();
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        $latteInput = \is_object($ʟ_tmp = $dyn) ? $ʟ_tmp : \end($this->global->formsStack)[$ʟ_tmp];
        if ($latteLabel = $latteInput->getLabel()) {
            echo $latteLabel->addAttributes(['class' => "c"])->startTag();
        }
        echo 'L';
        if ($latteLabel) {
            echo $latteLabel->endTag();
        }
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo 'S';
        echo $latteLabel->endTag();
PHP), self::eliminate(EliminatorRun::family(ShapeFamily::LATTE_2), $php));
	}

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testUnboundPairedLabelKeepsItsNullsafeGuardOnTheLatte3Shapes(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo ($ʟ_label = $control->getLabel())?->startTag();
		echo 'L';
		echo $ʟ_label?->endTag();
		echo ($ʟ_label = Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getLabel())?->startTag();
		echo 'S';
		echo $ʟ_label?->endTag();
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo ($latteLabel = $control->getLabel())?->startTag();
        echo 'L';
        echo $latteLabel?->endTag();
        $latteLabel = \OriPhpstan\Nette\Latte\Runtime\Helpers::formLabel('x');
        echo $latteLabel->startTag();
        echo 'S';
        echo $latteLabel->endTag();
PHP), self::eliminate(EliminatorRun::family($latteLine), $php));
	}

	// {input x, attrs} types through the Html stand-in on every bridge; a plain {input x} keeps its
	// formField('x')->getControl() read.
	public function testAttributedInputBindsTheHtmlStandInOnTheMacrosShape(): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo end($this->global->formsStack)["x"]->getControl()->addAttributes(['class' => 'c']) /* line 2 */;
		echo end($this->global->formsStack)["x"]->getControlPart("part")->addAttributes(['class' => 'c']) /* line 3 */;
		echo end($this->global->formsStack)["y"]->getControl() /* line 4 */;
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x', 'part')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
PHP), self::eliminate(EliminatorRun::family(ShapeFamily::LATTE_2), $php));
	}

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testAttributedInputBindsTheHtmlStandInOnTheItemShape(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getControl()->addAttributes(['class' => 'c']) /* line 2 */;
		echo Nette\Bridges\FormsLatte\Runtime::item('x', $this->global)->getControlPart('part')->addAttributes(['class' => 'c']) /* line 3 */;
		echo Nette\Bridges\FormsLatte\Runtime::item('y', $this->global)->getControl() /* line 4 */;
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x', 'part')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
PHP), self::eliminate(new ShapeFamily($latteLine, ShapeFamily::FORMS_ITEM), $php));
	}

	/**
	 * @dataProvider provideLatte3Lines
	 */
	public function testAttributedInputBindsTheHtmlStandInOnTheProviderShape(string $latteLine): void
	{
		$php = EliminatorRun::template(<<<'PHP'
		echo $this->global->forms->get('x')->getControl()->addAttributes(['class' => 'c']) /* pos 2:1 */;
		echo $this->global->forms->get('x')->getControlPart('part')->addAttributes(['class' => 'c']) /* pos 3:1 */;
		echo $this->global->forms->get('y')->getControl() /* pos 4:1 */;
PHP);

		self::assertSame(EliminatorRun::printed(<<<'PHP'
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formInput('x', 'part')->addAttributes(['class' => 'c']);
        echo \OriPhpstan\Nette\Latte\Runtime\Helpers::formField('y')->getControl();
PHP), self::eliminate(new ShapeFamily($latteLine, ShapeFamily::FORMS_PROVIDER), $php));
	}

	public function testEveryBridgeDescribesItsOwnShape(): void
	{
		$macros = (new FormsMacroEliminator(EliminatorRun::family(ShapeFamily::LATTE_2)))->describePattern();
		$item = (new FormsMacroEliminator(EliminatorRun::family(ShapeFamily::LATTE_30)))->describePattern();
		$provider = (new FormsMacroEliminator(new ShapeFamily(ShapeFamily::LATTE_31, ShapeFamily::FORMS_PROVIDER)))
			->describePattern();

		self::assertStringContainsString('stackOffset', $macros);
		self::assertStringContainsString('ʟ_input', $macros);
		self::assertStringContainsString('runtimeItem', $item);
		self::assertStringContainsString(
			'Nette\Bridges\FormsLatte\Runtime::initializeForm|renderFormBegin|renderFormEnd|item',
			$item,
		);
		self::assertStringContainsString('providerGet', $provider);
		self::assertStringContainsString('formsProvider: forms', $provider);
		self::assertStringNotContainsString('stackProvider', $provider);
		self::assertStringNotContainsString('formsProvider', $item);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public function provideLatte3Lines(): iterable
	{
		yield 'latte 3.0' => [ShapeFamily::LATTE_30];
		yield 'latte 3.1' => [ShapeFamily::LATTE_31];
	}

	private static function eliminate(ShapeFamily $family, string $php): string
	{
		return EliminatorRun::apply($php, new FormsMacroEliminator($family));
	}

}
