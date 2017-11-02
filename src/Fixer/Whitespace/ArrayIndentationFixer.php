<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Fixer\Whitespace;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class ArrayIndentationFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Multiline arrays must be indented with configured indentation.',
            [
                new CodeSample("<?php\n\$foo = [\n   'bar' => [\n    'baz' => true,\n  ],\n];\n"),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isAnyTokenKindsFound([T_ARRAY, CT::T_ARRAY_SQUARE_BRACE_OPEN]);
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should run after BracesFixer
        return -30;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens)
    {
        foreach ($this->findArrays($tokens) as $array) {
            $previousLineBracesDelta = 1;
            $indentLevel = 0;
            $ignoredPositiveDelta = 0;
            $arrayIndent = $this->getLineIndentation($tokens, $array['start']);
            $previousLineInitialIndent = $arrayIndent;
            $previousLineNewIndent = $arrayIndent;

            foreach ($array['line_braces_deltas'] as $index => $lineBracesDelta) {
                $token = $tokens[$index];

                $extraIndentLevel = 0;

                if ($previousLineBracesDelta > 0) {
                    ++$indentLevel;

                    if ($previousLineBracesDelta > 1) {
                        $ignoredPositiveDelta += $previousLineBracesDelta - 1;
                    }
                }

                if ($lineBracesDelta < 0) {
                    $delta = $lineBracesDelta + $ignoredPositiveDelta;
                    $ignoredPositiveDelta = max(0, $ignoredPositiveDelta + $lineBracesDelta);
                    $indentLevel = max(0, $indentLevel + $delta);

                    if ($this->isClosingLineWithMeaningfulContent($tokens, $index)) {
                        $extraIndentLevel = -$lineBracesDelta;
                    }
                }

                if ($this->newlineIsInArrayScope($tokens, $index, $array)) {
                    $content = preg_replace(
                        '/(\R)[\t ]*$/',
                        '$1'.$arrayIndent.str_repeat($this->whitespacesConfig->getIndent(), $indentLevel + $extraIndentLevel),
                        $token->getContent()
                    );

                    $previousLineInitialIndent = $this->extractIndent($token->getContent());
                    $previousLineNewIndent = $this->extractIndent($content);
                } else {
                    $content = preg_replace(
                        '/(\R)'.preg_quote($previousLineInitialIndent, '/').'([\t ]*)$/',
                        '$1'.$previousLineNewIndent.'$2',
                        $token->getContent()
                    );
                }

                $previousLineBracesDelta = $lineBracesDelta;

                $tokens[$index] = new Token([T_WHITESPACE, $content]);
            }
        }
    }

    private function findArrays(Tokens $tokens)
    {
        $arrays = [];

        foreach ($this->findArrayTokenRanges($tokens, 0, count($tokens) - 1) as $arrayTokenRanges) {
            $array = [
                'start' => $arrayTokenRanges[0][0],
                'end' => $arrayTokenRanges[count($arrayTokenRanges) - 1][1],
                'token_ranges' => $arrayTokenRanges,
            ];

            $array['line_braces_deltas'] = $this->computeArrayLineBracesDeltas($tokens, $array);

            $arrays[] = $array;
        }

        return $arrays;
    }

    private function findArrayTokenRanges(Tokens $tokens, $from, $to)
    {
        $arrayTokenRanges = [];
        $currentArray = null;

        for ($index = $from; $index <= $to; ++$index) {
            $token = $tokens[$index];

            if (null !== $currentArray && $currentArray['end'] === $index) {
                $rangeIndexes = [$currentArray['start']];
                foreach ($currentArray['ignored_tokens_ranges'] as list($start, $end)) {
                    $rangeIndexes[] = $start - 1;
                    $rangeIndexes[] = $end + 1;
                }
                $rangeIndexes[] = $currentArray['end'];

                $arrayTokenRanges[] = array_chunk($rangeIndexes, 2);

                foreach ($currentArray['ignored_tokens_ranges'] as list($start, $end)) {
                    foreach ($this->findArrayTokenRanges($tokens, $start, $end) as $nestedArray) {
                        $arrayTokenRanges[] = $nestedArray;
                    }
                }

                $currentArray = null;

                continue;
            }

            if (null === $currentArray && $token->isGivenKind([T_ARRAY, CT::T_ARRAY_SQUARE_BRACE_OPEN])) {
                if ($token->isGivenKind(T_ARRAY)) {
                    $index = $tokens->getNextTokenOfKind($index, ['(']);
                }

                $currentArray = [
                    'start' => $index,
                    'end' => $tokens->findBlockEnd(
                        $tokens[$index]->equals('(') ? Tokens::BLOCK_TYPE_PARENTHESIS_BRACE : Tokens::BLOCK_TYPE_ARRAY_SQUARE_BRACE,
                        $index
                    ),
                    'ignored_tokens_ranges' => [],
                ];

                continue;
            }

            if (
                null !== $currentArray && (
                    ($token->equals('(') && !$tokens[$tokens->getPrevMeaningfulToken($index)]->isGivenKind(T_ARRAY))
                    || $token->equals('{')
                )
            ) {
                $endIndex = $tokens->findBlockEnd(
                    $token->equals('{') ? Tokens::BLOCK_TYPE_CURLY_BRACE : Tokens::BLOCK_TYPE_PARENTHESIS_BRACE,
                    $index
                );

                $currentArray['ignored_tokens_ranges'][] = [$index, $endIndex];

                $index = $endIndex;

                continue;
            }
        }

        return $arrayTokenRanges;
    }

    private function computeArrayLineBracesDeltas(Tokens $tokens, array $array)
    {
        $deltas = [];

        for ($index = $array['start']; $index <= $array['end']; ++$index) {
            $token = $tokens[$index];

            if (!$this->isNewLineWhitespace($token)) {
                continue;
            }

            $deltas[$index] = $this->getLineBracesDelta($tokens, $index, $array);
        }

        return $deltas;
    }

    private function getLineBracesDelta(Tokens $tokens, $index, array $array)
    {
        $lineBracesDelta = 0;

        for (++$index; $index <= $array['end']; ++$index) {
            $token = $tokens[$index];

            if ($this->isNewLineWhitespace($token)) {
                break;
            }

            if (!$this->indexIsInArrayTokenRanges($index, $array)) {
                continue;
            }

            if ($token->isGivenKind(T_ARRAY) || $token->equals([CT::T_ARRAY_SQUARE_BRACE_OPEN])) {
                ++$lineBracesDelta;

                continue;
            }

            if ($token->equals(')')) {
                $openBraceIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index, false);
                if (!$tokens[$tokens->getPrevMeaningfulToken($openBraceIndex)]->isGivenKind(T_ARRAY)) {
                    continue;
                }

                --$lineBracesDelta;
            }

            if ($token->isGivenKind(CT::T_ARRAY_SQUARE_BRACE_CLOSE)) {
                --$lineBracesDelta;
            }
        }

        return $lineBracesDelta;
    }

    private function isClosingLineWithMeaningfulContent(Tokens $tokens, $newLineIndex)
    {
        $nextMeaningfulIndex = $tokens->getNextMeaningfulToken($newLineIndex);

        return !$tokens[$nextMeaningfulIndex]->equalsAny([')', [CT::T_ARRAY_SQUARE_BRACE_CLOSE]]);
    }

    private function getLineIndentation(Tokens $tokens, $index)
    {
        $newlineTokenIndex = $this->getPreviousNewlineTokenIndex($tokens, $index);

        if (null === $newlineTokenIndex) {
            return '';
        }

        return $this->extractIndent($tokens[$newlineTokenIndex]->getContent());
    }

    private function extractIndent($content)
    {
        if (preg_match('/\R([\t ]*)$/', $content, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function getPreviousNewlineTokenIndex(Tokens $tokens, $index)
    {
        while ($index > 0) {
            $index = $tokens->getPrevTokenOfKind($index, [[T_WHITESPACE]]);

            if (null === $index) {
                break;
            }

            if ($this->isNewLineWhitespace($tokens[$index])) {
                return $index;
            }
        }

        return null;
    }

    private function newlineIsInArrayScope(Tokens $tokens, $index, array $array)
    {
        if ($tokens[$tokens->getPrevMeaningfulToken($index)]->equalsAny(['.', '?', ':'])) {
            return false;
        }

        $nextToken = $tokens[$tokens->getNextMeaningfulToken($index)];
        if ($nextToken->isGivenKind(T_OBJECT_OPERATOR) || $nextToken->equalsAny(['.', '?', ':'])) {
            return false;
        }

        return $this->indexIsInArrayTokenRanges($index, $array);
    }

    private function indexIsInArrayTokenRanges($index, array $array)
    {
        foreach ($array['token_ranges'] as list($start, $end)) {
            if ($index < $start) {
                return false;
            }

            if ($index <= $end) {
                return true;
            }
        }

        return false;
    }

    private function isNewLineWhitespace(Token $token)
    {
        return $token->isWhitespace() && preg_match('/\R/', $token->getContent());
    }
}
