<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Note)

namespace EndlessCreativity\ElephantPhp\Document;

final class Note
{
    /**
     * @param  list<Node>  $body
     */
    public function __construct(
        public readonly NoteType $noteType,
        public readonly string $noteId,
        public readonly array $body,
    ) {
    }
}
