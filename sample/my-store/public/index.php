<?php

/**
 * MyStore -- a runnable demo of the PHP Presto Pay SDK against Presto's real staging gateway, using only
 * PHP's built-in web server and the SDK itself. No framework, no third-party dependencies (Tailwind CSS and
 * Font Awesome are browser assets loaded from a CDN, not PHP packages), mirroring the Go SDK's net-http
 * sample. Route handling and feature coverage mirror the Java SDK's sample/my-store: a checkout page with a
 * toggle for where the shopper picks a payment method, a return page, and webhook verification with a
 * recent-deliveries list.
 *
 * Run: php -S localhost:8080 -t public public/index.php
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use PrestoUniverse\PrestoPay\Exception\ConfigException;
use PrestoUniverse\PrestoPay\Exception\PrestoPayException;
use PrestoUniverse\PrestoPay\PaymentMethod;
use PrestoUniverse\PrestoPay\Request\InitRequest;
use PrestoUniverse\PrestoPay\Request\QueryRequest;
use PrestoUniverse\PrestoPay\Request\RefundRequest;
use PrestoUniverse\PrestoPay\Request\ReverseRequest;
use PrestoUniverse\PrestoPay\Sample\MyStore\AppConfig;
use PrestoUniverse\PrestoPay\Sample\MyStore\Money;
use PrestoUniverse\PrestoPay\TxnType;
use PrestoUniverse\PrestoPay\Webhook\NotifyAck;

// Let the built-in server serve a real static file (e.g. /js/checkout.js) directly instead of routing it.
// realpath()'s containment check stops a crafted "../" request URI from resolving to a file outside public/.
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $resolved = realpath($requestedFile);
    if ($_SERVER['REQUEST_URI'] !== '/' && $resolved !== false && str_starts_with($resolved, __DIR__ . DIRECTORY_SEPARATOR) && is_file($resolved)) {
        return false;
    }
}

try {
    $app = AppConfig::fromEnvironment();
} catch (ConfigException $error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Configuration error: {$error->getMessage()}\n";
    echo "See sample/my-store/.env.example.\n";
    return;
}

/** @return array<string, string> */
function validateCheckoutForm(array $form): array
{
    $errors = [];

    $displayDesc = trim((string) ($form['displayDesc'] ?? ''));
    if ($displayDesc === '') {
        $errors['displayDesc'] = 'Description is required';
    } elseif (strlen($displayDesc) > 200) {
        $errors['displayDesc'] = 'Description must be at most 200 characters';
    }

    $amountText = (string) ($form['amountInRinggit'] ?? '');
    $amountMinorUnits = Money::toMinorUnits($amountText);
    if ($amountMinorUnits === null || $amountMinorUnits < 1) {
        $errors['amountInRinggit'] = 'Amount must be at least 0.01';
    }

    $showPaymentMethods = (bool) ($form['showPaymentMethods'] ?? false);
    $selectedPaymentMethod = trim((string) ($form['selectedPaymentMethod'] ?? ''));
    if ($showPaymentMethods && $selectedPaymentMethod === '') {
        $errors['selectedPaymentMethod'] = 'Select a payment method';
    }

    return $errors;
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    $decoded = $raw === false || $raw === '' ? null : json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sendJson(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

/** Reports a PrestoPayException's idempotency fields alongside the message -- what a caller needs to decide
 *  whether it is safe to retry -- and never leaks the raw exception message from a signature failure. */
function sendGatewayError(PrestoPayException $error): void
{
    $body = ['message' => $error->getMessage(), 'mayHaveTakenEffect' => $error->mayHaveTakenEffect()];
    if ($error->reconcileBy() !== null) {
        $body['reconcileBy'] = $error->reconcileBy();
    }
    sendJson(502, $body);
}

function render(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/templates/' . $template . '.php';
}

/** @return list<array{code: string, name: string, icon: string}> */
function paymentMethodChoices(): array
{
    return [
        ['code' => PaymentMethod::PM_PG_CARD, 'name' => 'Credit / debit card', 'icon' => 'fa-credit-card'],
        ['code' => PaymentMethod::TOUCH_N_GO_EWALLET, 'name' => "Touch 'n Go eWallet", 'icon' => 'fa-wallet'],
        ['code' => PaymentMethod::GRAB_PAY, 'name' => 'GrabPay', 'icon' => 'fa-wallet'],
        ['code' => PaymentMethod::MAYBANK, 'name' => 'Maybank FPX', 'icon' => 'fa-building-columns'],
        ['code' => PaymentMethod::CIMB, 'name' => 'CIMB Clicks', 'icon' => 'fa-building-columns'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'GET' && $path === '/') {
    render('index', [
        'paymentMethods' => paymentMethodChoices(),
        'defaultSelectedMethod' => PaymentMethod::PM_PG_CARD,
        'recentWebhooks' => $app->activityStore->recentWebhooks(),
    ]);
    return;
}

if ($method === 'POST' && $path === '/checkout') {
    $form = jsonBody();
    $errors = validateCheckoutForm($form);
    if ($errors !== []) {
        sendJson(400, $errors);
        return;
    }

    $txnRefNum = Money::nextTxnRefNum();
    $amountMinorUnits = Money::toMinorUnits((string) $form['amountInRinggit']);
    $displayDesc = trim((string) $form['displayDesc']);
    $showPaymentMethods = (bool) ($form['showPaymentMethods'] ?? false);
    $selectedPaymentMethod = trim((string) ($form['selectedPaymentMethod'] ?? ''));

    $checkoutRecord = [
        'txnRefNum' => $txnRefNum,
        'displayDesc' => $displayDesc,
        'amountMinorUnits' => $amountMinorUnits,
        'currencyCode' => 'MYR',
        'selectedPaymentMethod' => $showPaymentMethods ? $selectedPaymentMethod : null,
        'initiatedAt' => date(DATE_ATOM),
    ];

    try {
        $result = $app->client->payments()->init(new InitRequest(
            prestoMrn: $app->prestoMrn,
            txnType: TxnType::WebPay,
            txnRefNum: $txnRefNum,
            displayDesc: $displayDesc,
            amount: $amountMinorUnits,
            currencyCode: 'MYR',
            notifyUrl: $app->notifyUrl(),
            redirectUrl: $app->returnUrl($txnRefNum),
            allowedPaymentMethods: $showPaymentMethods ? [$selectedPaymentMethod] : null,
        ));
    } catch (PrestoPayException $error) {
        // init may or may not have reached Presto; the checkout record is not saved, since there is nothing
        // to reconcile against yet by this txnRefNum unless the caller retries with the same one.
        sendGatewayError($error);
        return;
    }

    $checkoutRecord['paymentRefNum'] = $result->paymentRefNum;
    $checkoutRecord['paymentStatus'] = $result->paymentStatus;
    $app->activityStore->saveCheckout($txnRefNum, $checkoutRecord);

    sendJson(200, ['paymentUrl' => $result->paymentUrl, 'txnRefNum' => $result->txnRefNum ?? $txnRefNum]);
    return;
}

if ($method === 'GET' && $path === '/return') {
    render('return', ['missingTxnRefNum' => true, 'recentWebhooks' => $app->activityStore->recentWebhooks()]);
    return;
}

if ($method === 'GET' && preg_match('#^/return/([^/]+)$#', $path, $matches) === 1) {
    $txnRefNum = rawurldecode($matches[1]);
    $checkout = $app->activityStore->findCheckout($txnRefNum);

    $data = [
        'txnRefNum' => $txnRefNum,
        'checkout' => $checkout,
        'query' => null,
        'queryError' => null,
        'recentWebhooks' => $app->activityStore->recentWebhooks(),
    ];

    // Any status other than PendingAuthorise means Presto has finalised the payment. This page's query and
    // the /presto/notify webhook are triggered independently by Presto and can arrive in either order -- this
    // page must not assume the webhook has (or has not) already been processed.
    try {
        $data['query'] = $app->client->payments()->query(new QueryRequest(
            prestoMrn: $app->prestoMrn,
            txnRefNum: $txnRefNum,
        ));
    } catch (PrestoPayException $error) {
        $data['queryError'] = $error->getMessage();
    }

    render('return', $data);
    return;
}

if ($method === 'GET' && preg_match('#^/payments/([^/]+)$#', $path, $matches) === 1) {
    try {
        $result = $app->client->payments()->query(new QueryRequest(
            prestoMrn: $app->prestoMrn,
            paymentRefNum: rawurldecode($matches[1]),
        ));
    } catch (PrestoPayException $error) {
        sendGatewayError($error);
        return;
    }
    sendJson(200, (array) $result);
    return;
}

if ($method === 'POST' && preg_match('#^/payments/([^/]+)/reverse$#', $path, $matches) === 1) {
    try {
        $result = $app->client->payments()->reverse(new ReverseRequest(
            prestoMrn: $app->prestoMrn,
            paymentRefNum: rawurldecode($matches[1]),
            reversalRefNum: 'rev-' . bin2hex(random_bytes(8)),
            remark: 'Requested via demo',
        ));
    } catch (PrestoPayException $error) {
        sendGatewayError($error);
        return;
    }
    sendJson(200, (array) $result);
    return;
}

if ($method === 'POST' && preg_match('#^/payments/([^/]+)/refund$#', $path, $matches) === 1) {
    try {
        $result = $app->client->payments()->refund(new RefundRequest(
            prestoMrn: $app->prestoMrn,
            paymentRefNum: rawurldecode($matches[1]),
            refundRefNum: 'rfnd-' . bin2hex(random_bytes(8)),
            remark: 'Requested via demo',
        ));
    } catch (PrestoPayException $error) {
        sendGatewayError($error);
        return;
    }
    sendJson(200, (array) $result);
    return;
}

if ($method === 'POST' && $path === '/presto/notify') {
    try {
        $event = $app->webhookVerifier->verify((string) file_get_contents('php://input'));
    } catch (\Throwable $error) {
        error_log('Webhook rejected: ' . $error->getMessage());
        header('Content-Type: application/json; charset=UTF-8');
        echo NotifyAck::forThrowable($error)->body();
        return;
    }

    try {
        $payment = $app->client->payments()->query(new QueryRequest(
            prestoMrn: $event->prestoMrn,
            paymentRefNum: $event->paymentRefNum,
        ));
    } catch (PrestoPayException $error) {
        // A webhook says what happened, not the payment's resulting status, so the status comes from query. If
        // that fails, ask Presto to redeliver rather than acknowledging an event that was never processed.
        error_log('Webhook eventRefNum=' . $event->eventRefNum . ' not processed, query failed: ' . $error->getMessage());
        header('Content-Type: application/json; charset=UTF-8');
        echo NotifyAck::Resend->body();
        return;
    }

    $firstDelivery = $app->activityStore->recordWebhook($event->eventRefNum, [
        'receivedAt' => date(DATE_ATOM),
        'eventCode' => $event->eventCode,
        'paymentStatus' => $payment->paymentStatus,
        'txnRefNum' => $event->txnRefNum,
        'paymentRefNum' => $event->paymentRefNum,
        'success' => $event->success,
        'amountMinorUnits' => $event->amount,
        'currencyCode' => $event->currencyCode,
    ]);
    if ($firstDelivery) {
        error_log(sprintf(
            'Webhook verified eventCode=%s success=%s queried paymentStatus=%s txnRefNum=%s paymentRefNum=%s eventRefNum=%s',
            $event->eventCode,
            $event->success ? 'true' : 'false',
            (string) $payment->paymentStatus,
            $event->txnRefNum,
            $event->paymentRefNum,
            $event->eventRefNum,
        ));
    } else {
        error_log('Duplicate webhook delivery eventRefNum=' . $event->eventRefNum . '; acking without refulfilling');
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo NotifyAck::Ok->body();
    return;
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not found: {$method} {$path}\n";
