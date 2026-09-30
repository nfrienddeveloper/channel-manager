<?php

namespace App\Services\Content;

/**
 * First, cheap line of defence: keeps topics a brand channel should not turn into upbeat
 * trend videos (deaths, crime, partisan politics, medical claims, adult content) out of the pipeline.
 * The script writer applies the same rules again with full context and can still skip a topic.
 */
class SafetyFilter
{
    public const CATEGORIES = [
        'tragedy' => 'Deaths, disasters with casualties, violence, war',
        'crime' => 'Crime, arrests, trials',
        'politics' => 'Elections, politicians, partisan issues',
        'health' => 'Medical news and health claims',
        'adult' => 'Sexual or adult content',
    ];

    private const KEYWORDS = [
        'tragedy' => ['dead', 'dies', 'died', 'death', 'deaths', 'deadly', 'killed', 'kills', 'killing', 'fatal', 'fatally', 'shooting', 'shooter', 'gunman', 'massacre',
            'murdered', 'drowned', 'funeral', 'obituary', 'passed away', 'suicide', 'bombing', 'explosion', 'war', 'airstrike', 'missile', 'hostage', 'terror',
            'terrorist', 'victims', 'casualties', 'injured', 'wounded', 'memorial', 'tribute', 'rip', 'mourns', 'mourning', 'genocide', 'invasion'],
        'crime' => ['murder', 'arrested', 'arrest', 'charged', 'indicted', 'indictment', 'sentenced', 'convicted', 'guilty', 'trial', 'stabbing', 'stabbed', 'assault',
            'rape', 'abuse', 'trafficking', 'kidnapped', 'kidnapping', 'robbery', 'police say', 'manhunt', 'suspect', 'felony', 'prison', 'jail'],
        'politics' => ['trump', 'biden', 'harris', 'vance', 'obama', 'election', 'senate', 'senator', 'congress', 'congressman', 'democrat', 'democrats', 'republican',
            'republicans', 'gop', 'maga', 'impeach', 'impeachment', 'supreme court', 'shutdown', 'ballot', 'white house', 'president', 'governor', 'parliament',
            'prime minister', 'midterm', 'midterms', 'primary', 'campaign trail', 'tariff', 'tariffs', 'immigration', 'ice raid', 'abortion'],
        'health' => ['vaccine', 'vaccines', 'cancer', 'covid', 'outbreak', 'virus', 'pandemic', 'diagnosed', 'diagnosis', 'disease', 'overdose', 'fda', 'cdc', 'measles',
            'bird flu', 'hospitalized', 'surgery'],
        'adult' => ['porn', 'onlyfans', 'nsfw', 'nude', 'nudes', 'sex tape', 'xxx', 'escort'],
    ];

    /**
     * @param  array<int, string>  $avoid  category keys to block
     * @return array<int, string> the blocked categories the text touches
     */
    public function flags(string $text, array $avoid): array
    {
        $text = ' '.mb_strtolower($text).' ';
        $hits = [];
        foreach ($avoid as $category) {
            foreach (self::KEYWORDS[$category] ?? [] as $word) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $text)) {
                    $hits[] = $category;
                    break;
                }
            }
        }

        return $hits;
    }
}
