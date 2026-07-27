<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (BookmarkStart)

namespace EndlessCreativity\ElephantPhp\Document;

final class BookmarkStart implements Node
{
    public function __construct(public string $name)
    {
    }
}
