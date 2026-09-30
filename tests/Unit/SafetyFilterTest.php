<?php

namespace Tests\Unit;

use App\Services\Content\SafetyFilter;
use PHPUnit\Framework\TestCase;

class SafetyFilterTest extends TestCase
{
    public function test_it_flags_only_the_categories_the_channel_avoids(): void
    {
        $f = new SafetyFilter;
        $all = array_keys(SafetyFilter::CATEGORIES);

        $this->assertSame(['tragedy', 'crime'], $f->flags('Manhunt underway for suspect who shot 3 coworkers; 1 killed', $all));
        $this->assertSame(['politics'], $f->flags('Trump says the shutdown will end', $all));
        $this->assertSame([], $f->flags("D'Angelo Russell joins the Shanghai Sharks", $all));
        $this->assertSame([], $f->flags('Trump says the shutdown will end', ['tragedy']));
    }

    public function test_it_matches_whole_words_only(): void
    {
        $f = new SafetyFilter;

        $this->assertSame([], $f->flags('Warriors win the award for best trial run of a new drink', ['tragedy']), '"war" inside "Warriors" and "award" is not war');
        $this->assertSame(['tragedy'], $f->flags('The war in the region', ['tragedy']));
    }
}
