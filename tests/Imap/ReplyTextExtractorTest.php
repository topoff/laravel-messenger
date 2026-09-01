<?php

declare(strict_types=1);

use Topoff\Messenger\Services\Imap\ReplyTextExtractor;

function extractReply(string $text): string
{
    return (new ReplyTextExtractor)->extract($text);
}

it('keeps a plain answer untouched', function () {
    expect(extractReply("Ja, das passt so.\nBis Montag."))
        ->toBe("Ja, das passt so.\nBis Montag.");
});

it('drops the english quote header and everything below it', function () {
    $text = <<<'TXT'
    Yes, Tuesday works for me.

    On Mon, 1 Sep 2026 at 08:30, Support <support@example.com> wrote:

    > Could you confirm the appointment?
    > Kind regards
    TXT;

    expect(extractReply($text))->toBe('Yes, Tuesday works for me.');
});

it('drops the german "Am ... schrieb ...:" quote header', function () {
    $text = <<<'TXT'
    Guten Tag, der Termin passt.

    Am 01.09.2026 um 08:30 schrieb Support <support@example.com>:

    > Passt Ihnen der Termin?
    TXT;

    expect(extractReply($text))->toBe('Guten Tag, der Termin passt.');
});

it('drops the german "NAME <EMAIL> schrieb:" quote header', function () {
    $text = <<<'TXT'
    Danke, ich melde mich.

    Support <support@example.com> schrieb:

    > Haben Sie die Offerte erhalten?
    TXT;

    expect(extractReply($text))->toBe('Danke, ich melde mich.');
});

it('drops the german outlook separator block', function () {
    $text = <<<'TXT'
    Besten Dank, wir nehmen die Offerte an.

    Von: Support <support@example.com>
    Gesendet: Montag, 1. September 2026 08:30
    An: Kundin <kundin@example.com>
    Betreff: Ihre Offerte

    Guten Tag, anbei die Offerte.
    TXT;

    expect(extractReply($text))->toBe('Besten Dank, wir nehmen die Offerte an.');
});

it('drops a german outlook "Von:" line carrying an address', function () {
    $text = <<<'TXT'
    Passt, danke.

    Von: Support <support@example.com>
    Betreff: Ihre Offerte

    Guten Tag, anbei die Offerte.
    TXT;

    expect(extractReply($text))->toBe('Passt, danke.');
});

it('drops the french quote header and the french outlook separator', function () {
    $wrote = <<<'TXT'
    Merci, c'est parfait.

    Le 1 septembre 2026 à 08:30, Support <support@example.com> a écrit :

    > Est-ce que la date vous convient ?
    TXT;

    $outlook = <<<'TXT'
    Merci, c'est parfait.

    De : Support <support@example.com>
    Envoyé : lundi 1 septembre 2026 08:30
    À : Cliente <cliente@example.com>
    Objet : Votre offre

    Bonjour, voici votre offre.
    TXT;

    expect(extractReply($wrote))->toBe("Merci, c'est parfait.")
        ->and(extractReply($outlook))->toBe("Merci, c'est parfait.");
});

it('drops the italian quote header and the italian outlook separator', function () {
    $wrote = <<<'TXT'
    Grazie, va bene così.

    Il giorno 1 settembre 2026 08:30, Support <support@example.com> ha scritto:

    > La data le va bene?
    TXT;

    $outlook = <<<'TXT'
    Grazie, va bene così.

    Da: Support <support@example.com>
    Inviato: lunedì 1 settembre 2026 08:30
    A: Cliente <cliente@example.com>
    Oggetto: La sua offerta

    Buongiorno, in allegato l'offerta.
    TXT;

    expect(extractReply($wrote))->toBe('Grazie, va bene così.')
        ->and(extractReply($outlook))->toBe('Grazie, va bene così.');
});

it('drops a dash signature', function () {
    $text = <<<'TXT'
    Wir bestätigen den Termin.

    --
    Beatrice Muster
    Muster AG
    TXT;

    expect(extractReply($text))->toBe('Wir bestätigen den Termin.');
});

it('drops quoted lines even without a quote header', function () {
    $text = <<<'TXT'
    Einverstanden.

    > Passt Ihnen der Termin?
    TXT;

    expect(extractReply($text))->toBe('Einverstanden.');
});

it('returns an empty string when nothing but history remains', function () {
    $text = <<<'TXT'
    Am 01.09.2026 um 08:30 schrieb Support <support@example.com>:

    > Passt Ihnen der Termin?
    TXT;

    expect(extractReply($text))->toBe('');
});

it('normalizes windows line endings', function () {
    expect(extractReply("Zeile eins\r\nZeile zwei\r\n"))->toBe("Zeile eins\nZeile zwei");
});
