<?php

declare(strict_types=1);

namespace AtarCms\Search;

/**
 * A visitor's query: the text as typed (trimmed, at most MAX_LENGTH characters) and the stems it searches for. Stems
 * contain only letters and digits, so the boolean string built from them carries no operator, quote or wildcard of
 * the visitor's.
 */
final readonly class SearchQuery
{
    public const int MAX_LENGTH = 200;

    public const int MAX_STEMS = 10;

    /** InnoDB does not index shorter words (innodb_ft_min_token_size), so shorter stems could never match. */
    public const int MIN_STEM_LENGTH = 3;

    /**
     * @param list<string> $stems
     */
    private function __construct(
        public string $text,
        public array $stems,
    ) {}

    /**
     * Invalid UTF-8 counts as an empty query.
     */
    public static function parse(string $input, string $locale, TextNormalizer $normalizer): self
    {
        $text = mb_check_encoding($input, 'UTF-8') ? trim(mb_substr(trim($input), 0, self::MAX_LENGTH)) : '';
        $stems = array_filter(
            $normalizer->words($text, $locale),
            static fn(string $stem): bool => mb_strlen($stem) >= self::MIN_STEM_LENGTH,
        );

        return new self($text, array_slice(array_values(array_unique($stems)), 0, self::MAX_STEMS));
    }

    /**
     * Nothing to search for: nothing typed, or only words shorter than MIN_STEM_LENGTH.
     */
    public function isEmpty(): bool
    {
        return $this->stems === [];
    }

    /**
     * The MATCH … AGAINST string in boolean mode: every stem required, as a prefix ("+новост* +москв*").
     */
    public function boolean(): string
    {
        return implode(' ', array_map(static fn(string $stem): string => '+' . $stem . '*', $this->stems));
    }
}
