<?php

namespace App\Support;

class HinglishTransliterator
{
    /**
     * @var array<string, string>
     */
    private const VOWELS = [
        'अ' => 'a',
        'आ' => 'aa',
        'इ' => 'i',
        'ई' => 'ee',
        'उ' => 'u',
        'ऊ' => 'oo',
        'ऋ' => 'ri',
        'ए' => 'e',
        'ऐ' => 'ai',
        'ओ' => 'o',
        'औ' => 'au',
    ];

    /**
     * @var array<string, string>
     */
    private const STEMS = [
        'क' => 'k',
        'ख' => 'kh',
        'ग' => 'g',
        'घ' => 'gh',
        'ङ' => 'ng',
        'च' => 'ch',
        'छ' => 'chh',
        'ज' => 'j',
        'झ' => 'jh',
        'ञ' => 'ny',
        'ट' => 't',
        'ठ' => 'th',
        'ड' => 'd',
        'ढ' => 'dh',
        'ण' => 'n',
        'त' => 't',
        'थ' => 'th',
        'द' => 'd',
        'ध' => 'dh',
        'न' => 'n',
        'प' => 'p',
        'फ' => 'ph',
        'ब' => 'b',
        'भ' => 'bh',
        'म' => 'm',
        'य' => 'y',
        'र' => 'r',
        'ल' => 'l',
        'व' => 'v',
        'श' => 'sh',
        'ष' => 'sh',
        'स' => 's',
        'ह' => 'h',
    ];

    /**
     * @var array<string, string>
     */
    private const NUKTA = [
        'क' => 'q',
        'ख' => 'kh',
        'ग' => 'gh',
        'ज' => 'z',
        'ड' => 'r',
        'ढ' => 'rh',
        'फ' => 'f',
    ];

    /**
     * @var array<string, string>
     */
    private const MATRAS = [
        'ा' => 'aa',
        'ि' => 'i',
        'ी' => 'ee',
        'ु' => 'u',
        'ू' => 'oo',
        'ृ' => 'ri',
        'े' => 'e',
        'ै' => 'ai',
        'ो' => 'o',
        'ौ' => 'au',
    ];

    /**
     * @var array<string, string>
     */
    private const MATRA_WITH_N = [
        'े' => 'en',
        'ै' => 'ain',
        'ो' => 'on',
        'ू' => 'oon',
        'ु' => 'un',
        'ी' => 'een',
        'ि' => 'in',
        'ा' => 'aan',
    ];

    public static function convert(string $text): string
    {
        if (! preg_match('/\p{Devanagari}/u', $text)) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return $text;
        }

        $out = '';
        $total = count($chars);

        for ($i = 0; $i < $total; $i++) {
            $char = $chars[$i];

            if (isset(self::VOWELS[$char])) {
                $out .= self::VOWELS[$char];

                continue;
            }

            if (! isset(self::STEMS[$char])) {
                $out .= match ($char) {
                    '।', '॥' => '. ',
                    '०' => '0',
                    '१' => '1',
                    '२' => '2',
                    '३' => '3',
                    '४' => '4',
                    '५' => '5',
                    '६' => '6',
                    '७' => '7',
                    '८' => '8',
                    '९' => '9',
                    default => $char,
                };

                continue;
            }

            $stem = self::STEMS[$char];

            if (($chars[$i + 1] ?? '') === '़') {
                $stem = self::NUKTA[$char] ?? $stem;
                $i++;
            }

            if (($chars[$i + 1] ?? '') === '्') {
                $out .= $stem;
                $i++;

                continue;
            }

            $mark = $chars[$i + 1] ?? '';

            if (isset(self::MATRAS[$mark])) {
                $i++;
                $nasal = $chars[$i + 1] ?? '';

                if ($nasal === 'ं' || $nasal === 'ँ') {
                    $i++;
                    $out .= ($char === 'म' && $mark === 'े')
                        ? 'mein'
                        : $stem.(self::MATRA_WITH_N[$mark] ?? self::MATRAS[$mark].'n');

                    continue;
                }

                $out .= $stem.self::MATRAS[$mark];

                continue;
            }

            if ($mark === 'ं' || $mark === 'ँ') {
                $out .= $stem.'an';
                $i++;

                continue;
            }

            $next = $chars[$i + 1] ?? '';
            $wordEnded = $next === '' || preg_match('/\s/u', $next) === 1 || in_array($next, ['।', '॥', ',', '.', '?', '!'], true);
            $out .= $wordEnded ? $stem : $stem.'a';
        }

        $out = preg_replace('/\s+/u', ' ', trim($out)) ?? trim($out);

        return $out;
    }
}
