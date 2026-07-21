<?php

namespace Lithium\VideoLogs\Support;

/** Everything the client needs to push bytes to storage. */
final class UploadTarget implements \JsonSerializable
{
    /**
     * @param  array<int, array{part_number: int, url: string}>|null  $parts
     */
    public function __construct(
        public readonly string $method,        // 'PUT' | 'POST'
        public readonly string $url,           // where to send bytes (single-PUT); empty for multipart
        public readonly array $headers = [],  // required headers, if any
        public readonly ?array $parts = null,  // [{part_number, url}] for multipart
        public readonly ?string $uploadId = null,
        public readonly ?int $partSize = null, // bytes per part (multipart only)
        public readonly int $expiresIn = 3600,
    ) {}

    /**
     * @return array{
     *     method: string,
     *     url: string,
     *     headers: array,
     *     parts: array<int, array{part_number: int, url: string}>|null,
     *     uploadId: string|null,
     *     partSize: int|null,
     *     expiresIn: int
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $this->headers,
            'parts' => $this->parts,
            'uploadId' => $this->uploadId,
            'partSize' => $this->partSize,
            'expiresIn' => $this->expiresIn,
        ];
    }
}
