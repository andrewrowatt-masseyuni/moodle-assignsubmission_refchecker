<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js

namespace EndlessCreativity\ElephantPhp\Document;

final class Run implements HasChildren
{
    /**
     * @param  list<Node>  $children
     */
    public function __construct(
        public readonly array $children = [],
        public readonly ?string $styleId = null,
        public readonly ?string $styleName = null,
        public readonly bool $isBold = false,
        public readonly bool $isItalic = false,
        public readonly bool $isUnderline = false,
        public readonly bool $isStrikethrough = false,
        public readonly bool $isAllCaps = false,
        public readonly bool $isSmallCaps = false,
        public readonly bool $isHidden = false,
        public readonly VerticalAlignment $verticalAlignment = VerticalAlignment::Baseline,
        public readonly ?string $highlight = null,
        public readonly ?string $font = null,
        public readonly ?float $fontSize = null,
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
            styleId: $this->styleId,
            styleName: $this->styleName,
            isBold: $this->isBold,
            isItalic: $this->isItalic,
            isUnderline: $this->isUnderline,
            isStrikethrough: $this->isStrikethrough,
            isAllCaps: $this->isAllCaps,
            isSmallCaps: $this->isSmallCaps,
            isHidden: $this->isHidden,
            verticalAlignment: $this->verticalAlignment,
            highlight: $this->highlight,
            font: $this->font,
            fontSize: $this->fontSize,
        );
    }
}
