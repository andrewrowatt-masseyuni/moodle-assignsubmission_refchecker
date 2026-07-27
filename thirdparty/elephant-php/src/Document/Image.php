<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Image)

namespace EndlessCreativity\ElephantPhp\Document;

use Closure;

final class Image implements Node
{
    /**
     * @param  Closure(): string  $readBytes  Lazy reader returning the raw image
     *                                        bytes; invoked at render time so
     *                                        documents that never render their
     *                                        images don't pay the I/O cost.
     */
    public function __construct(
        public readonly Closure $readBytes,
        public readonly ?string $contentType = null,
        public readonly ?string $altText = null,
    ) {
    }
}
