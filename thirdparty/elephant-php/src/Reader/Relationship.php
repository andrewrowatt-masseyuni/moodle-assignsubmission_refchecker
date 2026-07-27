<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/relationships-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final class Relationship
{
    public function __construct(
        public readonly string $relationshipId,
        public readonly string $target,
        public readonly string $type,
    ) {
    }
}
