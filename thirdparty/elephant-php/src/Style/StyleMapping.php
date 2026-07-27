<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/style-reader.js (styleRule shape)

namespace EndlessCreativity\ElephantPhp\Style;

final class StyleMapping
{
    public function __construct(
        public readonly Matcher $from,
        public readonly HtmlPath $to,
    ) {
    }
}
