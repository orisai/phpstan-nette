<?php declare(strict_types = 1);

$scenario = (string) getenv('FAKE_PHPSTAN_SCENARIO');
$state = json_decode((string) file_get_contents($scenario), true);
$arguments = array_slice($argv, 1);
$report = getenv('ORISAI_NETTE_LATTE_CONVERGE_REPORT');
$state['calls'][] = [
	'arguments' => $arguments,
	'prune' => getenv('ORISAI_NETTE_LATTE_NARROWING_PRUNE'),
	'reportIsFresh' => is_string($report) && $report !== '' && !file_exists($report),
	'directoryMode' => is_string($report) && is_dir(dirname($report)) ? sprintf('%o', fileperms(dirname($report)) & 0777) : null,
];

if ($arguments[0] === 'clear-result-cache') {
	$response = ['exitCode' => 0, 'stdout' => "cleared\n"];
} else {
	$response = $state['runs'][$state['run']] ?? $state['runs'][count($state['runs']) - 1];
	$state['run']++;
	if (isset($response['report'])) {
		file_put_contents((string) $report, $response['report']);
	}

	if (($response['pruneEvaluated'] ?? false) === true) {
		file_put_contents($report . '.prune', '');
	}
}

$state['reports'][] = $report;
file_put_contents($scenario, json_encode($state));

fwrite(STDERR, $response['stderr'] ?? '');
fwrite(STDOUT, $response['stdout'] ?? '');

exit($response['exitCode']);
