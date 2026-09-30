<?php declare(strict_types = 1);

$scenario = (string) getenv('FAKE_PHPSTAN_SCENARIO');
$state = json_decode((string) file_get_contents($scenario), true);
$call = count($state['calls']);
$state['calls'][] = ['arguments' => array_slice($argv, 1)];
file_put_contents($scenario, json_encode($state));

$response = $state['responses'][$call] ?? $state['responses'][count($state['responses']) - 1];
fwrite(STDERR, $response['stderr']);
fwrite(STDOUT, $response['stdout']);

exit($response['exitCode']);
