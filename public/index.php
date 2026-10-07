<?php

declare(strict_types=1);

use BWC\Visa\Auth;
use BWC\Visa\Config;
use BWC\Visa\VisaController;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// --- environment -----------------------------------------------------------
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$debug = Config::bool('APP_DEBUG', false);
$storageDir = dirname(__DIR__) . '/storage';

// Logging can be switched off via LOGGING_ENABLED=false (NullHandler → no files
// written, incl. the passport-reads.log with cleartext passport data).
$loggingEnabled = Config::bool('LOGGING_ENABLED', true);

$logger = new Logger('visa');
$extractionLogger = new Logger('passport');

if ($loggingEnabled) {
    $logger->pushHandler(new StreamHandler($storageDir . '/visa.log', Logger::INFO));

    // Dedicated, human-readable log of what was read from each passport copy.
    $extractionHandler = new StreamHandler($storageDir . '/passport-reads.log', Logger::INFO);
    $extractionHandler->setFormatter(new LineFormatter("[%datetime%]\n%message%\n", 'Y-m-d H:i:s', true, true));
    $extractionLogger->pushHandler($extractionHandler);
} else {
    $logger->pushHandler(new NullHandler());
    $extractionLogger->pushHandler(new NullHandler());
}

// --- app -------------------------------------------------------------------
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware($debug, true, true);

// large multipart uploads (24 passport images) — raise limits at PHP level too
@ini_set('memory_limit', '1024M');

// CORS so the static test page can call the API from a browser.
$app->add(function (Request $request, Handler $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, X-API-Key, X-Signature, Content-Type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
});

// preflight
$app->options('/{routes:.+}', fn (Request $req, Response $res) => $res);

// --- routes ----------------------------------------------------------------
$app->get('/health', function (Request $req, Response $res): Response {
    $res->getBody()->write(json_encode(['ok' => true, 'service' => 'bwc-visa', 'time' => date('c')]));
    return $res->withHeader('Content-Type', 'application/json');
});

$app->post('/api/visa-request', function (Request $request, Response $response) use ($storageDir, $logger, $extractionLogger): Response {
    $auth = Auth::check($request);
    if (!$auth['ok']) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => $auth['error']]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
    return (new VisaController($storageDir, $logger, $extractionLogger))->handle($request, $response);
});

$app->run();
