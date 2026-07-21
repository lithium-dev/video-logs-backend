<?php

namespace Lithium\VideoLogs\Support;

/** Framework-light wrapper around an inbound provider webhook. */
final class WebhookRequest
{
    public function __construct(
        public readonly array $payload,
        public readonly array $headers,
        public readonly string $rawBody,       // needed for signature verification
    ) {}
}
