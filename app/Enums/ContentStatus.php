<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Planned = 'planned';        // topic picked, nothing made yet
    case Producing = 'producing';    // research, script and render in progress
    case Ready = 'ready';            // video rendered, waiting for its post time
    case Publishing = 'publishing';
    case Published = 'published';    // posted (or, in dry run, written to the outbox)
    case Skipped = 'skipped';        // dropped on purpose: unsafe topic, too little to say
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::Producing, self::Ready, self::Publishing], true);
    }
}
