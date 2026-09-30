<?php

namespace App\Services\Content\Writers;

use App\Models\ContentItem;

interface ScriptWriter
{
    /**
     * Returns the raw script: decision, category, scenes, caption, hashtags and sources
     * (see ScriptPrompt::schema()). ScriptBuilder validates it before anything is rendered.
     */
    public function write(ContentItem $item, array $research): array;
}
