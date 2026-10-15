<?php

namespace Sellinnate\SalesCrm\Data;

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Contracts\TimelineProvider;

/**
 * One entry of the timeline of a deal, a company or a person: a note, an activity done, an email, a
 * change of the history, a document, or a read-only entry of a {@see TimelineProvider}.
 */
final class TimelineEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly CarbonImmutable $at,
        public readonly string $icon,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $meta = null,
        public readonly ?string $author = null,
        public readonly bool $pinned = false,
        public readonly ?int $id = null,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{key: string, kind: string, at: string, icon: string, title: string, body: string|null, meta: string|null, author: string|null, pinned: bool, id: int|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'at' => $this->at->toIso8601String(),
            'icon' => $this->icon,
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta,
            'author' => $this->author,
            'pinned' => $this->pinned,
            'id' => $this->id,
            'url' => $this->url,
        ];
    }
}
