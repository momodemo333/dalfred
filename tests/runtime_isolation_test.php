<?php

/*
 * Proves the released Dalfred package survives other modules' copies of its
 * libraries. Same failure as emMCP in production in November 2026:
 *
 *   "Declaration of Psr\Log\NullLogger::log($level, Stringable|string $message,
 *    array $context = []): void must be compatible with
 *    Psr\Log\LoggerInterface::log($level, $message, array $context = [])"
 *
 * Dalfred carries two dependency trees loaded into one process by the chat:
 * its own (NeuronAI, guzzle, psr/http-*) and the MCP runtime's (mcp/sdk,
 * psr/log 3, guzzle, symfony...). Both are prefixed by the build.
 *
 * Each scenario runs in its own PHP process, since the failure being tested is
 * a fatal error nobody can catch. No Dolibarr and no network are needed: the
 * HTTP calls target a closed local port and must fail as exceptions.
 *
 * Usage (run by tools/isolate-dependencies.sh during `make build-release`):
 *   php tests/runtime_isolation_test.php <scoped module dir> [<unscoped module dir>]
 *
 * A module dir holds vendor/, src/ and dolibarr-mcp-server/. With the optional
 * unscoped copy the test first reproduces both failures on it, so a pass
 * is known to mean something: an MCP fatal error (psr/log), and the chat
 * provider silently running on a foreign guzzle.
 */

$fixtures = __DIR__.'/fixtures';
$closedPort = 'http://127.0.0.1:9';

// --- child scenarios -------------------------------------------------------------

function initializeBody()
{
	return json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array(
		'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(),
		'clientInfo' => array('name' => 'isolation-test', 'version' => '1'),
	)));
}

if (($argv[1] ?? '') === '--child') {
	$mode = $argv[2];
	$dir = rtrim($argv[3], '/');

	// The MCP endpoint, unscoped (2.34.0 and before), after another module
	// loaded psr/log 1.x: the production fatal error.
	if ($mode === 'unscoped-mcp') {
		require $fixtures.'/psr-log-1/LoggerInterface.php';
		require $dir.'/vendor/autoload.php';
		require $dir.'/dolibarr-mcp-server/vendor/autoload.php';
		$response = \DolibarrMcp\Bootstrap::handleHttpRequest(
			(new \GuzzleHttp\Psr7\ServerRequest('POST', 'https://example.test/mcp.php', array('Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream')))
				->withBody(\GuzzleHttp\Psr7\Utils::streamFor(initializeBody())),
			sys_get_temp_dir().'/dalfred-isolation-'.getmypid(),
			new \DolibarrMcp\Config\ConnectionConfig('https://example.test', 'test-key')
		);
		echo "NO-FATAL ".$response->getStatusCode()."\n";
		exit(0);
	}
	// The chat, unscoped, after another module loaded its own guzzle (an older
	// major, say): NeuronAI silently talks through the foreign class.
	if ($mode === 'unscoped-chat') {
		eval('namespace GuzzleHttp; class Client { public function __construct() { throw new \RuntimeException("foreign guzzle client used"); } }');
		require $dir.'/vendor/autoload.php';
		try {
			(new \NeuronAI\Providers\Ollama\Ollama($closedPort.'/api', 'test-model'))->chat(new \NeuronAI\Chat\Messages\UserMessage('ping'));
		} catch (\Throwable $e) {
			echo get_class($e).': '.$e->getMessage()."\n";
			exit(strpos($e->getMessage(), 'foreign') !== false ? 5 : 0);
		}
		exit(0);
	}

	// Scoped: every foreign copy at once, loaded before anything of ours.
	require $fixtures.'/psr-log-1/LoggerInterface.php';
	require $fixtures.'/psr-http-message-1/MessageInterface.php';
	eval('namespace GuzzleHttp\Psr7; class ServerRequest { public static function fromGlobals() { throw new \RuntimeException("foreign guzzle used"); } }');
	eval('namespace GuzzleHttp; class Client { public function __construct() { throw new \RuntimeException("foreign guzzle client used"); } }');
	eval('namespace NeuronAI\Chat\Messages; class UserMessage { public function __construct() { throw new \RuntimeException("foreign NeuronAI used"); } }');

	$readPrefix = function ($vendorDir) {
		$prefix = trim((string) @file_get_contents($vendorDir.'/dalfred-scope-prefix.txt'));

		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) ? $prefix : '';
	};
	$modulePrefix = $readPrefix($dir.'/vendor');
	$runtimePrefix = $readPrefix($dir.'/dolibarr-mcp-server/vendor');
	if ($modulePrefix === '' || $runtimePrefix === '' || $modulePrefix === $runtimePrefix) {
		echo "NOT-SCOPED module='$modulePrefix' runtime='$runtimePrefix'\n";
		exit(3);
	}

	require $dir.'/vendor/autoload.php';
	$fail = array();

	// 1. The user's turn, built where ajax/chat.php builds it.
	$message = (new \Dalfred\Service\FileAttachmentService())->buildUserMessage(array(), 'ping');
	if (strpos(get_class($message), $modulePrefix.'\NeuronAI\\') !== 0) {
		$fail[] = 'UserMessage is '.get_class($message);
	}

	// 2. A provider round trip through the module's guzzle: must fail as an
	//    exception (closed port), not as a fatal error.
	$ollama = $modulePrefix.'\NeuronAI\Providers\Ollama\Ollama';
	try {
		(new $ollama($closedPort.'/api', 'test-model'))->chat($message);
		$fail[] = 'provider call to a closed port succeeded';
	} catch (\Throwable $e) {
		if (strpos($e->getMessage(), 'foreign') !== false) {
			$fail[] = 'provider used a foreign class: '.$e->getMessage();
		}
	}

	// 3. The chat's MCP tools: runtime loaded next to the module's tree.
	$bridge = new \Dalfred\MCP\DirectMcpBridge($closedPort, 'test-key');
	$tools = $bridge->tools();
	if (count($tools) < 10) {
		$fail[] = 'only '.count($tools).' MCP tools';
	}
	foreach ($tools as $tool) {
		if ($tool->getName() === 'dolibarr_list') {
			$tool->setInputs(array('resource' => 'thirdparties'));
			$tool->execute();
			$result = (string) $tool->getResult();
			if (strpos($result, 'foreign') !== false || strpos($result, 'error') === false) {
				$fail[] = 'dolibarr_list answered '.substr($result, 0, 200);
			}
		}
	}

	// 4. The external MCP endpoint, the way mcp.php drives the runtime.
	$requestClass = $runtimePrefix.'\GuzzleHttp\Psr7\ServerRequest';
	$streamUtils = $runtimePrefix.'\GuzzleHttp\Psr7\Utils';
	$nullLogger = $runtimePrefix.'\Psr\Log\NullLogger';
	new $nullLogger();

	$sessionDir = sys_get_temp_dir().'/dalfred-isolation-'.getmypid();
	@mkdir($sessionDir);
	$body = initializeBody();
	$request = (new $requestClass('POST', 'https://example.test/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
	)))->withBody($streamUtils::streamFor($body));

	$config = new \DolibarrMcp\Config\ConnectionConfig('https://example.test', 'test-key');
	$response = \DolibarrMcp\Bootstrap::handleHttpRequest($request, $sessionDir, $config);
	$payload = (string) $response->getBody();
	$sid = $response->getHeaderLine('Mcp-Session-Id');

	$list = (new $requestClass('POST', 'https://example.test/mcp.php', array(
		'Content-Type' => 'application/json',
		'Accept' => 'application/json, text/event-stream',
		'Mcp-Session-Id' => $sid,
	)))->withBody($streamUtils::streamFor(json_encode(array('jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'))));
	$toolsPayload = (string) \DolibarrMcp\Bootstrap::handleHttpRequest($list, $sessionDir, $config)->getBody();

	array_map('unlink', glob($sessionDir.'/*') ?: array());
	@rmdir($sessionDir);

	if ($response->getStatusCode() !== 200 || strpos($payload, 'serverInfo') === false) {
		$fail[] = 'initialize: '.$response->getStatusCode().' '.substr($payload, 0, 300);
	}
	if (substr_count($toolsPayload, '"name"') <= 5) {
		$fail[] = 'tools/list: '.substr($toolsPayload, 0, 300);
	}

	echo $fail === array() ? "SCOPED-OK\n" : 'SCOPED-BAD '.implode(' | ', $fail)."\n";
	exit($fail === array() ? 0 : 4);
}

// --- driver ----------------------------------------------------------------------

if ($argc < 2) {
	fwrite(STDERR, "Usage: php tests/runtime_isolation_test.php <scoped module dir> [<unscoped module dir>]\n");
	exit(2);
}

$failures = 0;
$run = function ($mode, $dir) {
	$cmd = escapeshellarg(PHP_BINARY).' -d display_errors=stderr -d error_reporting=-1 '.escapeshellarg(__FILE__)
		.' --child '.escapeshellarg($mode).' '.escapeshellarg($dir).' 2>&1';
	exec($cmd, $out, $code);

	return array($code, implode("\n", $out));
};
$check = function ($label, $ok, $detail = '') use (&$failures) {
	echo ($ok ? '  OK   ' : '  FAIL ').$label.($ok || $detail === '' ? '' : "\n         ".substr($detail, 0, 800))."\n";
	if (!$ok) {
		$failures++;
	}
};

echo "Dalfred dependency isolation against foreign psr/log 1.x, psr/http-message 1.0, guzzle and NeuronAI copies\n";

if (isset($argv[2])) {
	list($code, $out) = $run('unscoped-mcp', $argv[2]);
	$check('unscoped package: MCP initialize dies on the psr/log fatal error', $code !== 0 && strpos($out, 'must be compatible') !== false, $out);
	list($code, $out) = $run('unscoped-chat', $argv[2]);
	$check('unscoped package: the chat provider runs on the foreign guzzle', $code === 5, $out);
}

list($code, $out) = $run('scoped', $argv[1]);
$check('scoped package: user message, provider call, chat MCP tools, MCP initialize + tools/list', $code === 0 && strpos($out, 'SCOPED-OK') !== false, $out);

echo $failures === 0 ? "PASSED\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
