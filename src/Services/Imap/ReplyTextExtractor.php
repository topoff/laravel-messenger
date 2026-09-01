<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Extracts the visible answer from a reply email — everything the sender
 * actually wrote, without the quoted history and without the signature.
 *
 * Adapted from willdurand/email-reply-parser v2.10.0 (parser core + fragment
 * logic, condensed into one self-contained class):
 *
 *   The MIT License
 *   Copyright (c) GitHub, William Durand <william.durand1@gmail.com>
 *   Source: https://github.com/willdurand/EmailReplyParser
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy
 *   of this software and associated documentation files (the "Software"), to deal
 *   in the Software without restriction, including without limitation the rights
 *   to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *   copies of the Software, and to permit persons to whom the Software is
 *   furnished to do so, subject to the following conditions:
 *
 *   The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *   IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *   FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *   AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *   LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *   OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *   THE SOFTWARE.
 *
 * Additions over upstream, for the mail clients our senders actually use:
 * the German Outlook separator ("Von: … Gesendet: …" block, and a "Von:" line
 * carrying an address) plus the French ("De : … Envoyé :") and Italian
 * ("Da: … Inviato:") block equivalents.
 */
final class ReplyTextExtractor
{
    /**
     * Hard cap on the processed body length — see extract().
     */
    private const int MAX_INPUT_LENGTH = 100_000;

    private const string QUOTE_REGEX = '/^>+/s';

    /**
     * Upstream also treats "^-\w" (bare "-Name" sign-offs) as a signature start,
     * but that swallows real reply lines like "-1 Tag vorher" together with
     * everything after them. Losing genuine customer text is worse than keeping
     * the odd terse signature, so that alternative is deliberately dropped here.
     */
    private const string SIGNATURE_REGEX = '/(?:^\s*--|^\s*__|^-- $)|(?:^Sent from (my|Mail) (?:\s*\w+){1,4}$)|(?:^={30,}$)$/s';

    /**
     * Lines that introduce a quoted history. Everything from such a line on is
     * treated as quoted and dropped.
     *
     * @var list<string>
     */
    private const array QUOTE_HEADERS_REGEX = [
        '/^.{0,5}(On(?:(?!\bOn\b|\bwrote(\s|\xc2\xa0)?:).){0,1000}wrote(\s|\xc2\xa0)?:)$/ms',
        '/^.{0,5}(Le\b(?:(?!\bLe\b|\bécrit(\s|\xc2\xa0)?:).){0,1000}écrit(\s|\xc2\xa0)?:)$/ms',
        '/^.{0,5}(El(?:(?!\bEl\b|\bescribió\s?:).){0,1000}escribió\s?:)$/ms',
        '/^.{0,5}(El(?:(?!\bEl\b|\bha escrit\s?:).){0,1000}ha escrit\s?:)$/ms',
        '/^.{0,5}(Il(?:(?!\bIl\b|\bscritto(\s|\xc2\xa0)?:).){0,1000}scritto(\s|\xc2\xa0)?:)$/ms',
        '/^[\S\s]+ (написа(л|ла|в)+)+:$/msu',
        '/^\s*(Op\s.+?(schreef|geschreven).+:)$/ms',
        '/^\s*((W\sdniu|Dnia)\s.+?(pisze|napisał(\(a\))?):)$/msu',
        '/^\s*(Den\s.+\sskrev\s.+:)$/m',
        '/^\s*(Am\s.+\sum\s.+\sschrieb\s.+:)$/m',
        '/^(在.+写道：)$/ms',
        '/^(20[0-9]{2}\..+\s작성:)$/m',
        '/^(20[0-9]{2}\/.+のメッセージ:)$/m',
        '/^(.+\s<.+>\sschrieb:)$/m',
        '/^\s*(From\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^\s*(发件人\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^\s*(De\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^\s*(Van\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^\s*(Da\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^(20[0-9]{2}\-(?:0?[1-9]|1[012])\-(?:0?[0-9]|[1-2][0-9]|3[01]|[1-9])\s[0-2]?[0-9]:\d{2}\s.+?:)$/ms',
        '/^\s*([a-z]{3,4}\.\s.+\sskrev\s.+:)$/ms',

        // Additions: German Outlook, both as a "Von: NAME <EMAIL>" line and as the
        // "Von: … Gesendet: …" block, plus the French and Italian block forms.
        '/^\s*(Von\s?:.+\s?(\[|<).+(\]|>))/mu',
        '/^\s*(Von\s?:.+\s+Gesendet\s?:.+)$/mu',
        '/^\s*(De\s?:.+\s+Envoyé\s?:.+)$/mu',
        '/^\s*(Da\s?:.+\s+Inviato\s?:.+)$/mu',
    ];

    /**
     * Visible answer text: quoted history, signatures and empty blocks removed.
     */
    public function extract(string $textBody): string
    {
        // The body is attacker-supplied (anyone can mail the inbox) and the
        // quote-header regexes backtrack; a hard input cap keeps a crafted or
        // huge mail from burning CPU. Real replies are far below this.
        $fragments = $this->parse(mb_substr($textBody, 0, self::MAX_INPUT_LENGTH));

        $visible = array_filter($fragments, fn (array $fragment): bool => ! $fragment['isHidden']);

        return rtrim(implode("\n", array_map(
            fn (array $fragment): string => $fragment['content'],
            $visible,
        )));
    }

    /**
     * Split the text into fragments, walking it bottom-up: the quoted history and
     * the signature sit at the end, so a fragment is closed as soon as its first
     * line turns out to be a signature or a quote header.
     *
     * @return list<array{content: string, isHidden: bool, isSignature: bool, isQuoted: bool}>
     */
    private function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        foreach (self::QUOTE_HEADERS_REGEX as $regex) {
            if (preg_match($regex, $text, $matches) === 1) {
                $text = str_replace($matches[1], str_replace("\n", ' ', $matches[1]), $text);
            }
        }

        /** @var list<array{lines: list<string>, isHidden: bool, isSignature: bool, isQuoted: bool}> $fragments */
        $fragments = [];
        $fragment = null;
        $lines = explode("\n", $text);

        while (($line = array_pop($lines)) !== null) {
            $line = ltrim($line, "\n");

            if (! $this->isSignature($line)) {
                $line = rtrim($line);
            }

            if ($fragment !== null) {
                $first = $fragment['lines'][0];

                if ($this->isSignature($first)) {
                    $fragment['isSignature'] = true;
                    $this->addFragment($fragments, $fragment);
                    $fragment = null;
                } elseif ($line === '' && $this->isQuoteHeader($first)) {
                    $fragment['isQuoted'] = true;
                    $this->addFragment($fragments, $fragment);
                    $fragment = null;
                }
            }

            $isQuoted = $this->isQuote($line);

            if ($fragment === null || ! $this->isFragmentLine($fragment, $line, $isQuoted)) {
                if ($fragment !== null) {
                    $this->addFragment($fragments, $fragment);
                }

                $fragment = ['lines' => [], 'isHidden' => false, 'isSignature' => false, 'isQuoted' => $isQuoted];
            }

            array_unshift($fragment['lines'], $line);
        }

        $this->addFragment($fragments, $fragment);

        return array_map(fn (array $item): array => [
            'content' => (string) preg_replace("/^\n/", '', implode("\n", $item['lines'])),
            'isHidden' => $item['isHidden'],
            'isSignature' => $item['isSignature'],
            'isQuoted' => $item['isQuoted'],
        ], $fragments);
    }

    /**
     * @param  list<array{lines: list<string>, isHidden: bool, isSignature: bool, isQuoted: bool}>  $fragments
     * @param  array{lines: list<string>, isHidden: bool, isSignature: bool, isQuoted: bool}  $fragment
     */
    private function addFragment(array &$fragments, array $fragment): void
    {
        if ($fragment['isQuoted'] || $fragment['isSignature'] || implode('', $fragment['lines']) === '') {
            $fragment['isHidden'] = true;
        }

        array_unshift($fragments, $fragment);
    }

    /**
     * @param  array{lines: list<string>, isHidden: bool, isSignature: bool, isQuoted: bool}  $fragment
     */
    private function isFragmentLine(array $fragment, string $line, bool $isQuoted): bool
    {
        return $fragment['isQuoted'] === $isQuoted
            || ($fragment['isQuoted'] && ($this->isQuoteHeader($line) || $line === ''));
    }

    private function isQuoteHeader(string $line): bool
    {
        return array_any(self::QUOTE_HEADERS_REGEX, fn ($regex) => preg_match($regex, $line) === 1);
    }

    private function isSignature(string $line): bool
    {
        return preg_match(self::SIGNATURE_REGEX, $line) === 1;
    }

    private function isQuote(string $line): bool
    {
        return preg_match(self::QUOTE_REGEX, $line) === 1;
    }
}
