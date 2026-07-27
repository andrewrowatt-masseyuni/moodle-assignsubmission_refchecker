<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/html-paths.js (Element)

namespace EndlessCreativity\ElephantPhp\Style;

final class HtmlPathElement
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public readonly string $tagName,
        public readonly array $attributes = [],
        public readonly bool $fresh = false,
        // Text inserted by the simplifier between two adjacent matching
        // elements when they get merged. Mammoth's `:separator('text')`
        // path modifier; null means "no separator".
        public readonly ?string $separator = null,
    ) {
    }
}
