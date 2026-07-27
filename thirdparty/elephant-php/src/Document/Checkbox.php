<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (checkbox)

namespace EndlessCreativity\ElephantPhp\Document;

final class Checkbox implements Node
{
    public function __construct(public bool $checked = false)
    {
    }
}
