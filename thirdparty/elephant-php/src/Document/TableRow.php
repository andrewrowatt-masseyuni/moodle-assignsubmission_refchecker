<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (TableRow)

namespace EndlessCreativity\ElephantPhp\Document;

final class TableRow implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public readonly array $children = [],
        public readonly bool $isHeader = false,
    ) {
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function withChildren(array $children): self
    {
        return new self(children: $children, isHeader: $this->isHeader);
    }
}
