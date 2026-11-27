<?php

namespace App\Media;

final readonly class ImportPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public string $label,
        public array $items,
        public ?array $cursor,
        public ?string $message = null,
    ) {}
}
