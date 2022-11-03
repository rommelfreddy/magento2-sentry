<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Model;

// phpcs:disable Magento2.Functions.DiscouragedFunction

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

class SentryPerformance
{
    /** @var Transaction|null */
    private $transaction;

    /** @var \Magento\Framework\App\ResourceConnection */
    private $resourceConnection;

    public function __construct(\Magento\Framework\App\ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function startTransaction(HttpRequest $request)
    {
        $requestStartTime = $request->getServer('REQUEST_TIME_FLOAT', microtime(true));

        $context = TransactionContext::fromHeaders(
            $request->getHeader('sentry-trace') ?: '',
            $request->getHeader('baggage') ?: ''
        );

        $requestPath = '/'.ltrim($request->getRequestUri(), '/');

        $context->setOp('http.server');
        $context->setName($requestPath);
        $context->setSource(TransactionSource::url());
        $context->setStartTimestamp($requestStartTime);

        $context->setData([
            'url'    => $requestPath,
            'method' => strtoupper($request->getMethod()),
        ]);

        // Start the transaction
        $transaction = \Sentry\startTransaction($context);

        // If this transaction is not sampled, don't set it either and stop doing work from this point on
        if (!$transaction->getSampled()) {
            return;
        }

        $this->transaction = $transaction;

        // Set the current transaction as the current span so we can retrieve it later
        \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);
    }

    /**
     * @param ResponseInterface|Response $response
     */
    public function finishTransaction(ResponseInterface $response)
    {
        if ($this->transaction) {
            if ($response instanceof HttpResponse) {
                $this->transaction->setHttpStatus($response->getStatusCode());
            }

            $this->addSqlQueries();

            // Finish the transaction, this submits the transaction and it's span to Sentry
            $this->transaction->finish();

            $this->transaction = null;
        }
    }

    private function addSqlQueries()
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        if (!$parentSpan) {
            return;
        }

        /** @var \Zend_Db_Profiler $profiler */
        $profiler = $this->resourceConnection->getConnection('read')->getProfiler();
        if (!$profiler) {
            return;
        }

        /** @var \Zend_Db_Profiler_Query[]|false $profiles */
        $profiles = $profiler->getQueryProfiles();
        if (!$profiles) {
            return;
        }

        foreach ($profiles as $profile) {
            $context = new SpanContext();
            $context->setOp('db.sql.query');
            $context->setDescription($profile->getQuery());
            $context->setStartTimestamp($profile->getStartedMicrotime());
            $context->setEndTimestamp(($profile->getStartedMicrotime() + $profile->getElapsedSecs()));

            $parentSpan->startChild($context);
        }
    }
}