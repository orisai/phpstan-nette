<?php

use Latte\Runtime as LR;
final class LatteTpl_fixtures_statement_containers_latte_bfe136fb extends \Latte\Runtime\Template
{
    protected const BLOCKS = [0 => ['sub' => 'blockSub'], 'snippet' => ['s1' => 'blockS1']];
    /**
     * @param bool $cond
     * @param int $level
     * @param array $items
     * @param string $x
     * @param string $tagName
     * @return array{}
     */
    public function latteMain($cond, $level, $items, $x, $tagName): array
    {
        echo "\n";
        $this->blockSub($cond, $level, $items, $x, $tagName);
        echo '

';
        if ($cond) {
            echo '	plain
';
        } elseif ($level > 1) {
            echo '	<p>';
            echo $x;
            echo '</p>
';
            echo '	<div';
            echo \Latte\Runtime\Filters::htmlAttributes(['data-x' => $level]);
            echo '></div>
';
            $latteTag0 = $tagName ?? 'div';
            \Latte\Runtime\Filters::checkTagSwitch('div', $latteTag0);
            echo '	<';
            echo $latteTag0;
            echo '>tag</';
            echo $latteTag0;
            echo '>
	';
            echo $x;
            $elseifCaptured = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
            echo "\n";
            \OriPhpstan\Nette\Latte\Runtime\Helpers::component("menu");
        } else {
            echo '	<p>';
            echo $x;
            echo '</p>
';
            echo '	';
            try {
                echo 'risky';
            } catch (\Throwable $latteException) {
                if (!$latteException instanceof \Latte\Runtime\RollbackException && isset($this->global->coreExceptionHandler)) {
                    ($this->global->coreExceptionHandler)($latteException, $this);
                }
            }
            echo '
	';
            $this->blockSub($cond, $level, $items, $x, $tagName);
            echo '
	<div';
            echo ' id="' . \htmlspecialchars(\OriPhpstan\Nette\Latte\Runtime\Helpers::snippetId('s1')) . '"';
            echo '>';
            $this->blockS1($cond, $level, $items, $x, $tagName);
            echo '</div>
	';
            $iterations = 0;
            foreach ($items as $item) {
                echo $item;
                $iterations++;
            }
            echo "\n";
        }
        echo "\n";
        $latteSwitch = $level;
        if (\false) {
        } elseif (\in_array($latteSwitch, [1], \true)) {
            echo '		<p>';
            echo $x;
            echo '</p>
';
            echo '		';
            echo $x;
            $caseCaptured = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
            echo "\n";
            \OriPhpstan\Nette\Latte\Runtime\Helpers::component("menu");
        }
        echo "\n";
        $i = 0;
        while ($i < 3) {
            echo '	<p>';
            echo $x;
            echo '</p>
';
            \OriPhpstan\Nette\Latte\Runtime\Helpers::component("menu");
            $i++;
        }
        echo "\n";
        for ($j = 0; $j < 3; $j++) {
            echo '	<div';
            echo \Latte\Runtime\Filters::htmlAttributes(['data-j' => $j]);
            echo '></div>
	';
            try {
                echo 'in for';
            } catch (\Throwable $latteException) {
                if (!$latteException instanceof \Latte\Runtime\RollbackException && isset($this->global->coreExceptionHandler)) {
                    ($this->global->coreExceptionHandler)($latteException, $this);
                }
            }
            echo '
	';
            $iterations = 0;
            foreach ($items as $item) {
                echo $item;
                $iterations++;
            }
            echo "\n";
        }
        echo "\n";
        try {
            echo '	<p>';
            echo $x;
            echo '</p>
';
            echo '	';
            echo $x;
            $tryCaptured = \OriPhpstan\Nette\Latte\Runtime\Helpers::capturedString();
            echo "\n";
        } catch (\Throwable $latteException) {
            if (!$latteException instanceof \Latte\Runtime\RollbackException && isset($this->global->coreExceptionHandler)) {
                ($this->global->coreExceptionHandler)($latteException, $this);
            }
            $latteTag0 = $tagName ?? 'div';
            \Latte\Runtime\Filters::checkTagSwitch('div', $latteTag0);
            echo '	<';
            echo $latteTag0;
            echo '>caught</';
            echo $latteTag0;
            echo '>
';
        }
        return [];
    }
    /**
     * @param bool $cond
     * @param int $level
     * @param array $items
     * @param string $x
     * @param string $tagName
     */
    public function lattePrepare($cond, $level, $items, $x, $tagName): void
    {
        \Nette\Bridges\ApplicationLatte\UIRuntime::initialize($this, $this->parentName, $this->blocks);
    }
    /**
     * @param bool $cond
     * @param int $level
     * @param array $items
     * @param string $x
     * @param string $tagName
     */
    public function blockSub($cond, $level, $items, $x, $tagName): void
    {
        echo 'embedded sub block';
    }
    /**
     * @param bool $cond
     * @param int $level
     * @param array $items
     * @param string $x
     * @param string $tagName
     */
    public function blockS1($cond, $level, $items, $x, $tagName): void
    {
        echo 'snippet content';
    }
}