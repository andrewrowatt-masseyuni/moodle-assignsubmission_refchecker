<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/styles-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

final class Style
{
    public function __construct(
        public readonly string $styleId,
        public readonly ?string $name = null,
    ) {
    }
}
