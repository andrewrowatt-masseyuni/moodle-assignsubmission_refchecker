<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Hyperlink)

namespace EndlessCreativity\ElephantPhp\Document;

final class Hyperlink implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public readonly array $children = [],
        public readonly ?string $href = null,
        public readonly ?string $anchor = null,
        public readonly ?string $targetFrame = null,
    ) {
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function withChildren(array $children): self
    {
        return new self(
            children: $children,
            href: $this->href,
            anchor: $this->anchor,
            targetFrame: $this->targetFrame,
        );
    }
}
