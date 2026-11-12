<?php declare(strict_types = 1);

use PHPStan\PhpDocParser\ParserConfig;

require __DIR__ . '/autoload.php';

// The lowest set installs phpdoc-parser 1 (a coding-standard dependency) next to PHPStan's bundled 2;
// a PHPStan run loads the bundled one first, so the tests must too.
if (!class_exists(ParserConfig::class)) {
	require_once 'phar://' . dirname(__DIR__) . '/' . getenv(
		'COMPOSER_VENDOR_DIR',
	) . '/phpstan/phpstan/phpstan.phar/preload.php';
}

require __DIR__ . '/Doubles/Forms/functions.php';

// PHPStan changes its output inside a coding agent (PHPStan\Internal\AgentDetector::ENV_VARS, phpstan 2.2.16).
foreach ([
	'AUGMENT_AGENT',
	'AMP_CURRENT_THREAD_ID',
	'AI_AGENT',
	'CURSOR_TRACE_ID',
	'CURSOR_AGENT',
	'GEMINI_CLI',
	'CODEX_SANDBOX',
	'CODEX_THREAD_ID',
	'OPENCODE_CLIENT',
	'OPENCODE',
	'CLAUDECODE',
	'CLAUDE_CODE',
	'REPL_ID',
] as $agentVariable) {
	putenv($agentVariable);
	unset($_SERVER[$agentVariable], $_ENV[$agentVariable]);
}
