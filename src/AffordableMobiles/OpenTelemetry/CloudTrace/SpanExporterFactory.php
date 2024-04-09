<?php

declare(strict_types=1);

namespace AffordableMobiles\OpenTelemetry\CloudTrace;

use Google\Cloud\Trace\TraceClient;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class SpanExporterFactory implements SpanExporterFactoryInterface
{
    public function create(): SpanExporterInterface
    {
        $client = new TraceClient([
            'requestTimeout' => Configuration::getInt(Variables::OTEL_EXPORTER_CLOUD_TRACE_TIMEOUT, 30),
        ]);

        return new Exporter($client);
    }
}
