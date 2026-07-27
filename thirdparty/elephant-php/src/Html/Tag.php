<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/styles/html-paths.js (Element)

namespace EndlessCreativity\ElephantPhp\Html;

final class Tag
{
    /**
     * @param  array<string, string>  $attributes
     * @param  list<string>  $matchAlternativeTagNames  Extra tag names this
     *                                                  tag is willing to merge
     *                                                  into during simplification
     *                                                  (mammoth's `ul|ol` form).
     */
    public function __construct(
        public readonly string $tagName,
        public readonly array $attributes = [],
        public readonly bool $fresh = true,
        public readonly ?string $separator = null,
        public readonly array $matchAlternativeTagNames = [],
    ) {
    }

    public function matches(self $other): bool
    {
        if ($this->attributes !== $other->attributes) {
            return false;
        }
        if ($this->tagName === $other->tagName) {
            return true;
        }

        return in_array($other->tagName, $this->matchAlternativeTagNames, true);
    }
}
