<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Table)

namespace EndlessCreativity\ElephantPhp\Document;

final class Table implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public readonly array $children = [],
        public readonly ?string $styleId = null,
        public readonly ?string $styleName = null,
    ) {
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function withChildren(array $children): self
    {
        return new self(children: $children, styleId: $this->styleId, styleName: $this->styleName);
    }
}
