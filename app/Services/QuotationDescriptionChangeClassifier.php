<?php

namespace App\Services;

use Illuminate\Support\Str;

class QuotationDescriptionChangeClassifier
{
    /**
     * Normalize presentation-only differences while retaining values and
     * operators that can change a product's technical meaning.
     */
    public function canonicalize(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = strtr($text, [
            '≤' => '<=',
            '≥' => '>=',
            '≠' => '!=',
        ]);
        $text = preg_replace('/(<=|>=|!=|<>|==|=|<|>|≈|±)/u', ' $1 ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\s<>=!≈±.,+%\-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::lower(trim($text));
    }

    public function isMinorCorrection(mixed $previousValue, mixed $currentValue): bool
    {
        $previous = $this->canonicalize($previousValue);
        $current = $this->canonicalize($currentValue);

        if ($previous === $current) {
            return true;
        }

        if ($previous === '' || $current === '') {
            return false;
        }

        // A changed number or comparison operator is a specification change,
        // regardless of how small its edit distance happens to be.
        if ($this->numericTokens($previous) !== $this->numericTokens($current)
            || $this->comparisonOperators($previous) !== $this->comparisonOperators($current)) {
            return false;
        }

        $previousTokens = $this->wordTokens($previous);
        $currentTokens = $this->wordTokens($current);

        // Missing/added words are content changes. Genuine spelling fixes keep
        // the same word structure and modify only a small number of tokens.
        if (count($previousTokens) !== count($currentTokens)) {
            return false;
        }

        $changedTokens = [];

        foreach ($previousTokens as $index => $previousToken) {
            $currentToken = $currentTokens[$index];

            if ($previousToken !== $currentToken) {
                $changedTokens[] = [$previousToken, $currentToken];
            }
        }

        if ($changedTokens === [] || count($changedTokens) > 3) {
            return false;
        }

        foreach ($changedTokens as [$previousToken, $currentToken]) {
            if (! $this->isPlausibleSpellingCorrection($previousToken, $currentToken)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function numericTokens(string $value): array
    {
        preg_match_all('/[-+]?\d+(?:[.,]\d+)?/u', $value, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function comparisonOperators(string $value): array
    {
        preg_match_all('/<=|>=|!=|<>|==|=|<|>|≈|±/u', $value, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function wordTokens(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $value, $matches);

        return $matches[0] ?? [];
    }

    private function isPlausibleSpellingCorrection(string $previous, string $current): bool
    {
        if (preg_match('/\d/u', $previous.$current) === 1) {
            return false;
        }

        $previousLength = mb_strlen($previous);
        $currentLength = mb_strlen($current);
        $maxLength = max($previousLength, $currentLength);

        // Very short abbreviations (AC/DC, NO/NC, etc.) commonly represent
        // technical specifications rather than misspelled prose.
        if (min($previousLength, $currentLength) < 3) {
            return false;
        }

        if (abs($previousLength - $currentLength) > 1) {
            return false;
        }

        // Three-letter abbreviations frequently carry technical meaning
        // (RHS/LHS, BAR/PSI). Only an obvious transposition is accepted at
        // this length, preserving the supported RHS/RSH typo correction.
        if ($maxLength === 3) {
            return $this->isAdjacentTransposition($previous, $current);
        }

        if ($this->isAdjacentTransposition($previous, $current)) {
            return true;
        }

        $allowedDistance = $maxLength >= 8 ? 2 : 1;

        return levenshtein($previous, $current) <= $allowedDistance;
    }

    private function isAdjacentTransposition(string $previous, string $current): bool
    {
        $previousCharacters = preg_split('//u', $previous, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $currentCharacters = preg_split('//u', $current, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($previousCharacters) !== count($currentCharacters)) {
            return false;
        }

        $differentIndexes = [];

        foreach ($previousCharacters as $index => $character) {
            if ($character !== $currentCharacters[$index]) {
                $differentIndexes[] = $index;
            }
        }

        if (count($differentIndexes) !== 2 || $differentIndexes[1] !== $differentIndexes[0] + 1) {
            return false;
        }

        [$first, $second] = $differentIndexes;

        return $previousCharacters[$first] === $currentCharacters[$second]
            && $previousCharacters[$second] === $currentCharacters[$first];
    }
}
