<?php

namespace App\Services\Content\Writers;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Writes scripts with Claude Code on this computer (`claude -p`), so it runs on the user's Claude subscription. */
class ClaudeCliWriter implements ScriptWriter
{
    public function write(ContentItem $item, array $research): array
    {
        $cfg = config('channels.writer');
        $result = Process::timeout($cfg['timeout'])
            ->path(sys_get_temp_dir())
            ->input(ScriptPrompt::build($item, $research))
            ->run([
                $cfg['claude_bin'], '-p',
                '--output-format', 'json',
                '--model', $cfg['model'],
                '--tools', '',
                '--setting-sources', '',
                '--no-session-persistence',
                '--json-schema', json_encode(ScriptPrompt::schema()),
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('Claude Code failed: '.trim($result->errorOutput() ?: $result->output()));
        }
        $out = json_decode($result->output(), true);
        if (! is_array($out) || ! empty($out['is_error'])) {
            throw new RuntimeException('Claude Code returned an error: '.mb_substr($result->output(), 0, 500));
        }
        $script = $out['structured_output'] ?? json_decode($out['result'] ?? '', true);
        if (! is_array($script)) {
            throw new RuntimeException('Claude Code returned no script');
        }

        return $script;
    }
}
