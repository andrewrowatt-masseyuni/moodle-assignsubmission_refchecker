<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (comment)

namespace EndlessCreativity\ElephantPhp\Document;

final class Comment
{
    /**
     * @param  list<Node>  $body
     */
    public function __construct(
        public readonly string $commentId,
        public readonly array $body,
        public readonly ?string $authorName = null,
        public readonly ?string $authorInitials = null,
    ) {
    }
}
