<?php declare(strict_types = 1);

$scenario = (string) getenv('FAKE_PHPSTAN_SCENARIO');
$state = json_decode((string) file_get_contents($scenario), true);
$arguments = array_slice($argv, 1);
$state['calls'][] = ['arguments' => $arguments, 'prune' => getenv('ORISAI_NETTE_LATTE_NARROWING_PRUNE')];

if ($arguments[0] === 'dump-parameters') {
	$response = $state['dumpParameters'];
} elseif ($arguments[0] === 'clear-result-cache') {
	$response = ['exitCode' => 0, 'stdout' => "cleared\n", 'stderr' => ''];
} else {
	$response = $state['runs'][$state['run']] ?? $state['runs'][count($state['runs']) - 1];
	$state['run']++;
	foreach ($response['write'] ?? [] as $name => $content) {
		file_put_contents($state['store'] . '/' . $name, $content);
	}

	foreach ($response['delete'] ?? [] as $name) {
		unlink($state['store'] . '/' . $name);
	}
}

file_put_contents($scenario, json_encode($state));

fwrite(STDERR, $response['stderr'] ?? '');
fwrite(STDOUT, $response['stdout'] ?? '');

exit($response['exitCode']);
