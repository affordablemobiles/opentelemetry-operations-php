<?php

declare(strict_types=1);

namespace AffordableMobiles\OpenTelemetry\CloudTrace;

use Google\Cloud\Trace\Annotation as GoogleAnnotation;
use Google\Cloud\Trace\Link as GoogleLink;
use Google\Cloud\Trace\Span as GoogleSpan;
use Google\Cloud\Trace\Status as GoogleStatus;
use Google\Rpc\Code as GrpcCode;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SemConv\TraceAttributes;

class SpanConverter
{
    public const KEY_DROPPED_ATTRIBUTES_COUNT      = 'otel.dropped_attributes_count';
    public const KEY_DROPPED_EVENTS_COUNT          = 'otel.dropped_events_count';
    public const KEY_DROPPED_LINKS_COUNT           = 'otel.dropped_links_count';
    public const KEY_AGENT                         = 'g.co/agent';

    public const STATUS_MAP = [
        StatusCode::STATUS_OK    => GrpcCode::OK,
        StatusCode::STATUS_ERROR => GrpcCode::UNKNOWN,
    ];

    public const ATTRIBUTE_MAP = [
        TraceAttributes::HTTP_SCHEME                                 => '/http/client_protocol',
        TraceAttributes::HTTP_METHOD                                 => '/http/method',
        TraceAttributes::HTTP_REQUEST_CONTENT_LENGTH                 => '/http/request/size',
        TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH                => '/http/response/size',
        TraceAttributes::HTTP_ROUTE                                  => '/http/route',
        TraceAttributes::HTTP_RESPONSE_STATUS_CODE                   => '/http/status_code',
        TraceAttributes::HTTP_URL                                    => '/http/url',
        TraceAttributes::HTTP_USER_AGENT                             => '/http/user_agent',
        TraceAttributes::HTTP_HOST                                   => '/http/host',
    ];

    private readonly \DateTimeZone $timezone;

    public function __construct()
    {
        $this->timezone = new \DateTimeZone('UTC');
    }

    public function convertSpan(SpanDataInterface $span): GoogleSpan
    {
        $spanParent = $span->getParentContext();

        $spanOptions = [
            'spanId'            => $span->getSpanId(),
            'name'              => $span->getName(),
            'startTime'         => $this->nanoEpochToZulu(
                $span->getStartEpochNanos(),
            ),
            'endTime' => $this->nanoEpochToZulu(
                $span->getEndEpochNanos(),
            ),
            'attributes' => [],
            'timeEvents' => [],
            'links'      => [],
        ];

        if ($spanParent->isValid()) {
            $spanOptions['parentSpanId'] = $spanParent->getSpanId();
        }

        if (StatusCode::STATUS_UNSET !== $span->getStatus()->getCode()) {
            $spanOptions['status'] = new GoogleStatus(
                $this->convertStatusCode(
                    $span->getStatus()->getCode(),
                ),
                $span->getStatus()->getDescription() ?? '',
            );
        }

        if (!empty($span->getInstrumentationScope()->getName())) {
            $version                      = $span->getInstrumentationScope()->getVersion() ?? 'UNKNOWN';
            $spanOptions[self::KEY_AGENT] = $span->getInstrumentationScope()->getName().'['.$version.']';
        }

        foreach ($span->getAttributes() as $k => $v) {
            $spanOptions['attributes'][$k] = $this->sanitiseAttributeValueString($v);
        }

        foreach ($span->getResource()->getAttributes() as $k => $v) {
            $spanOptions['attributes'][$k] = $this->sanitiseAttributeValueString($v);
        }
        foreach ($span->getInstrumentationScope()->getAttributes() as $k => $v) {
            $spanOptions['attributes'][$k] = $this->sanitiseAttributeValueString($v);
        }

        foreach ($span->getEvents() as $event) {
            $spanOptions['timeEvents'][] = self::toAnnotation($event);
        }

        foreach ($span->getLinks() as $link) {
            $spanOptions['links'][] = self::toLink($link);
        }

        if ($span->getTotalDroppedEvents() > 0) {
            $spanOptions['attributes'][self::KEY_DROPPED_EVENTS_COUNT] = $span->getTotalDroppedEvents();
        }

        if ($span->getTotalDroppedLinks() > 0) {
            $spanOptions['attributes'][self::KEY_DROPPED_LINKS_COUNT] = $span->getTotalDroppedLinks();
        }

        $droppedAttributes = $span->getAttributes()->getDroppedAttributesCount()
            + $span->getInstrumentationScope()->getAttributes()->getDroppedAttributesCount()
            + $span->getResource()->getAttributes()->getDroppedAttributesCount();

        if ($droppedAttributes > 0) {
            $spanOptions['attributes'][self::KEY_DROPPED_ATTRIBUTES_COUNT] = $droppedAttributes;
        }

        $spanOptions['attributes'] = $this->convertAttributes(
            $spanOptions['attributes'],
        );

        return new GoogleSpan($span->getTraceId(), $spanOptions);
    }

    private static function convertStatusCode(string $key): int
    {
        if (\array_key_exists($key, self::STATUS_MAP)) {
            return self::STATUS_MAP[$key];
        }

        throw new \Exception('invalid status code');
    }

    private static function convertAttributes(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if (\array_key_exists($key, self::ATTRIBUTE_MAP)) {
                $newAttributes[self::ATTRIBUTE_MAP[$key]] = $value;
            } else {
                $newAttributes[$key] = $value;
            }
        }

        return $newAttributes;
    }

    private function nanoEpochToZulu(int $nanos): string
    {
        $seconds = intdiv($nanos, ClockInterface::NANOS_PER_SECOND);
        $micros  = intdiv($nanos % ClockInterface::NANOS_PER_SECOND, ClockInterface::NANOS_PER_MICROSECOND);
        $nrem    = $nanos % ClockInterface::NANOS_PER_MICROSECOND;

        $stamp = \DateTimeImmutable::createFromFormat('U.u', $seconds.'.'.$micros);

        return $stamp->format('Y-m-d\TH:i:s.u').$nrem.'Z';
    }

    private function sanitiseAttributeValueString(array|bool|float|int|string $value): string
    {
        // Casting false to string makes an empty string
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Cloud Trace attributes must be strings, but opentelemetry
        // accepts strings, booleans, numbers, and lists of each.
        if (\is_array($value)) {
            return implode(',', array_map(fn ($value) => $this->sanitiseAttributeValueString($value), $value));
        }

        // Floats will lose precision if their string representation
        // is >=14 or >=17 digits, depending on PHP settings.
        // Can also throw E_RECOVERABLE_ERROR if $value is an object
        // without a __toString() method.
        // This is possible because OpenTelemetry\API\Trace\Span does not verify
        // setAttribute() $value input.
        return (string) $value;
    }

    private static function toAnnotation(EventInterface $event): GoogleAnnotation
    {
        $eventOptions = [
            'time' => $this->nanoEpochToZulu(
                $event->getEpochNanos(),
            ),
        ];

        foreach ($event->getAttributes() as $k => $v) {
            $eventOptions['attributes'][$k] = $this->sanitiseAttributeValueString($v);
        }

        return new GoogleAnnotation($event->getName(), $eventOptions);
    }

    private static function toLink(LinkInterface $link): GoogleLink
    {
        $attributes = [];

        foreach ($link->getAttributes() as $k => $v) {
            $attributes[$k] = $this->sanitiseAttributeValueString($v);
        }

        return new GoogleLink(
            $link->getSpanContext()->getTraceId(),
            $link->getSpanContext()->getSpanId(),
            [
                'attributes' => $attributes,
            ]
        );
    }
}
