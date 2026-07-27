<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (commentReference)

namespace EndlessCreativity\ElephantPhp\Document;

final class CommentReference implements Node
{
    public function __construct(public string $commentId)
    {
    }
}
