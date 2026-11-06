<?php

namespace App\Posters\Data;

final readonly class FetchedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
