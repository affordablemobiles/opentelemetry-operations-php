# Open-Telemetry Operations Exporters for PHP

Provides OpenTelemetry PHP exporters for Google Cloud Platform [operation suite](https://cloud.google.com/products/operations) products.


## Installation

Available via composer as `affordablemobiles/opentelemetry-operations-php`.

## Usage

Example usage:

```php
use AffordableMobiles\GServerlessSupportLaravel\Trace\Propagator\CloudTracePropagator;
use AffordableMobiles\OpenTelemetry\CloudTrace\SpanExporterFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;

$propagator = CloudTracePropagator::getInstance();

$spanProcessor = new SimpleSpanProcessor(
    (new SpanExporterFactory())->create(),
);

$sampler = new ParentBased(
    new AlwaysOnSampler(),
);

$tracerProvider = (new TracerProviderBuilder())
    ->addSpanProcessor($spanProcessor)
    ->setSampler($sampler)
    ->build()
;

Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setPropagator($propagator)
    ->setAutoShutdown(true)
    ->buildAndRegisterGlobal()
;
```