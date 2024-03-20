<?php

declare(strict_types=1);

namespace AffordableMobiles\OpenTelemetry\CloudTrace;

use Google\Cloud\Trace\Span as GoogleSpan;
use Google\Cloud\Trace\Trace as GoogleTrace;
use Google\Cloud\Trace\TraceClient as GoogleTraceClient;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class Exporter implements SpanExporterInterface
{
    private readonly SpanConverter $converter;

    public function __construct(
        private readonly GoogleTraceClient $traceClient
    ) {
        $this->converter = new SpanConverter();
    }

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        /**
         * Create a new Trace object with blank metadata,
         *  as projectId is inherited from the TraceClient and traceId should already be filled on the spans.
         */
        $result = new GoogleTrace(
            projectId: '-',
            traceId: '-',
        );

        /*
         * Set the spans directly (almost) with setSpans,
         *  so that the metadata isn't overwritten by the Trace object.
         */
        $result->setSpans(
            $this->iterable_map(
                $batch,
                static fn (SpanDataInterface $span): GoogleSpan => $this->converter->convertSpan($span),
            ),
        );

        // need to return something compatible with FutureInterface
        return '...';
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    private function iterable_map(iterable $batch, callable $fn): array
    {
        $result = [];

        foreach ($batch as $item) {
            $result[] = $fn($item);
        }

        return $result;
    }
}
