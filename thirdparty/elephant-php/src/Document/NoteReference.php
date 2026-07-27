<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (NoteReference)

namespace EndlessCreativity\ElephantPhp\Document;

final class NoteReference implements Node
{
    public function __construct(
        public readonly NoteType $noteType,
        public readonly string $noteId,
    ) {
    }
}
