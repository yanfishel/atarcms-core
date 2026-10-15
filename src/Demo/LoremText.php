<?php

declare(strict_types=1);

namespace AtarCms\Demo;

use Random\Randomizer;

/**
 * Filler text for the demo content, deterministic through the given randomizer: the classic Latin "lorem ipsum"
 * vocabulary (public domain) for English and every language without its own, words built from Cyrillic syllables for
 * Russian and from Hebrew letter pairs for Hebrew. It reads like text and means nothing.
 */
final class LoremText
{
    private const array LATIN = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod', 'tempor',
        'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam', 'quis',
        'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'ex', 'ea', 'commodo', 'consequat', 'duis',
        'aute', 'irure', 'in', 'reprehenderit', 'voluptate', 'velit', 'esse', 'cillum', 'fugiat', 'nulla', 'pariatur',
        'excepteur', 'sint', 'occaecat', 'cupidatat', 'non', 'proident', 'sunt', 'culpa', 'qui', 'officia', 'deserunt',
        'mollit', 'anim', 'id', 'est', 'laborum', 'integer', 'vitae', 'justo', 'eget', 'magna', 'fermentum', 'iaculis',
        'pellentesque', 'habitant', 'morbi', 'tristique', 'senectus', 'netus', 'malesuada', 'fames', 'ac', 'turpis',
    ];

    private const array CYRILLIC = [
        'ка', 'ло', 'ми', 'ре', 'то', 'на', 'ве', 'си', 'ра', 'до', 'лю', 'бе', 'за', 'по', 'ни', 'те', 'ру', 'мо', 'га',
        'ле', 'ви', 'со', 'ты', 'ша', 'че', 'жи', 'ду', 'ба', 'ко', 'ли', 'ста', 'про', 'вер', 'тан', 'мир', 'дол',
    ];

    private const array HEBREW = [
        'בה', 'לו', 'מי', 'רה', 'תו', 'נה', 'סי', 'רא', 'דו', 'בר', 'כו', 'לי', 'מו', 'גה', 'זה', 'חי', 'עו', 'פה', 'צי',
        'קו', 'רי', 'של', 'תמ', 'אל', 'ים', 'ות', 'הר', 'שמ', 'נו', 'ער',
    ];

    public function __construct(
        private readonly Randomizer $random,
        private readonly string $locale,
    ) {}

    public function word(): string
    {
        $syllables = match ($this->primary()) {
            'ru' => self::CYRILLIC,
            'he' => self::HEBREW,
            default => null,
        };
        if ($syllables === null) {
            return self::LATIN[$this->random->getInt(0, count(self::LATIN) - 1)];
        }
        $word = '';
        $parts = $this->random->getInt(1, 3);
        for ($i = 0; $i < $parts; $i++) {
            $word .= $syllables[$this->random->getInt(0, count($syllables) - 1)];
        }

        return $word;
    }

    /**
     * $min to $max words, the first capitalised (scripts with capitals), with a comma now and then and a final period.
     */
    public function sentence(int $min = 6, int $max = 14): string
    {
        $count = $this->random->getInt($min, $max);
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word() . ($i > 1 && $i < $count - 2 && $this->random->getInt(0, 6) === 0 ? ',' : '');
        }

        return mb_convert_case(mb_substr($words[0], 0, 1), MB_CASE_TITLE) . mb_substr(implode(' ', $words), 1) . '.';
    }

    /**
     * A title: $min to $max words without a final period.
     */
    public function title(int $min = 3, int $max = 6): string
    {
        return rtrim(str_replace(',', '', $this->sentence($min, $max)), '.');
    }

    public function paragraph(int $sentences = 4): string
    {
        $parts = [];
        for ($i = 0; $i < $sentences; $i++) {
            $parts[] = $this->sentence();
        }

        return implode(' ', $parts);
    }

    private function primary(): string
    {
        return strtolower(explode('-', str_replace('_', '-', $this->locale), 2)[0]);
    }
}
