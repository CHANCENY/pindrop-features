<?php

namespace Simp\Pindrop\Modules\languages\src\Services;

use Simp\Translate\lang\LanguageManager;
use Simp\Translate\translate\Translate;

class LanguageSupport
{
    public array $languages = [
            "ab" => "Abkhaz",
            "ace" => "Acehnese",
            "ach" => "Acholi",
            "af" => "Afrikaans",
            "sq" => "Albanian",
            "alz" => "Alur",
            "am" => "Amharic",
            "ar" => "Arabic",
            "hy" => "Armenian",
            "as" => "Assamese",
            "awa" => "Awadhi",
            "ay" => "Aymara",
            "az" => "Azerbaijani",
            "ban" => "Balinese",
            "bm" => "Bambara",
            "ba" => "Bashkir",
            "eu" => "Basque",
            "btx" => "Batak Karo",
            "bts" => "Batak Simalungun",
            "bbc" => "Batak Toba",
            "be" => "Belarusian",
            "bem" => "Bemba",
            "bn" => "Bengali",
            "bew" => "Betawi",
            "bho" => "Bhojpuri",
            "bik" => "Bikol",
            "bs" => "Bosnian",
            "br" => "Breton",
            "bg" => "Bulgarian",
            "bua" => "Buryat",
            "yue" => "Cantonese",
            "ca" => "Catalan",
            "ceb" => "Cebuano",
            "ny" => "Chichewa (Nyanja)",
            "zh" => "Chinese (Simplified)",
            "zh-TW" => "Chinese (Traditional)",
            "cv" => "Chuvash",
            "co" => "Corsican",
            "crh" => "Crimean Tatar",
            "hr" => "Croatian",
            "cs" => "Czech",
            "da" => "Danish",
            "din" => "Dinka",
            "dv" => "Divehi",
            "doi" => "Dogri",
            "dov" => "Dombe",
            "nl" => "Dutch",
            "dz" => "Dzongkha",
            "en" => "English",
            "eo" => "Esperanto",
            "et" => "Estonian",
            "ee" => "Ewe",
            "fj" => "Fijian",
            "fil" => "Filipino (Tagalog)",
            "fi" => "Finnish",
            "fr" => "French",
            "fy" => "Frisian",
            "ff" => "Fulfulde",
            "gaa" => "Ga",
            "gl" => "Galician",
            "lg" => "Ganda (Luganda)",
            "ka" => "Georgian",
            "de" => "German",
            "el" => "Greek",
            "gn" => "Guarani",
            "gu" => "Gujarati",
            "ht" => "Haitian Creole",
            "cnh" => "Hakha Chin",
            "ha" => "Hausa",
            "haw" => "Hawaiian",
            "he" => "Hebrew",
            "hil" => "Hiligaynon",
            "hi" => "Hindi",
            "hmn" => "Hmong",
            "hu" => "Hungarian",
            "hrx" => "Hunsrik",
            "is" => "Icelandic",
            "ig" => "Igbo",
            "ilo" => "Iloko",
            "id" => "Indonesian",
            "ga" => "Irish",
            "it" => "Italian",
            "ja" => "Japanese",
            "jv" => "Javanese",
            "kn" => "Kannada",
            "pam" => "Kapampangan",
            "kk" => "Kazakh",
            "km" => "Khmer",
            "cgg" => "Kiga",
            "rw" => "Kinyarwanda",
            "ktu" => "Kituba",
            "gom" => "Konkani",
            "ko" => "Korean",
            "kri" => "Krio",
            "ku" => "Kurdish (Kurmanji)",
            "ckb" => "Kurdish (Sorani)",
            "ky" => "Kyrgyz",
            "lo" => "Lao",
            "ltg" => "Latgalian",
            "la" => "Latin",
            "lv" => "Latvian",
            "lij" => "Ligurian",
            "li" => "Limburgan",
            "ln" => "Lingala",
            "lt" => "Lithuanian",
            "lmo" => "Lombard",
            "luo" => "Luo",
            "lb" => "Luxembourgish",
            "mk" => "Macedonian",
            "mai" => "Maithili",
            "mak" => "Makassar",
            "mg" => "Malagasy",
            "ms" => "Malay",
            "ms-Arab" => "Malay (Jawi Script)",
            "ml" => "Malayalam",
            "mt" => "Maltese",
            "mi" => "Maori",
            "mr" => "Marathi",
            "chm" => "Meadow Mari",
            "mni-Mtei" => "Meiteilon (Manipuri)",
            "min" => "Minang",
            "lus" => "Mizo",
            "mn" => "Mongolian",
            "my" => "Myanmar (Burmese)",
            "nr" => "Ndebele (South)",
            "new" => "Nepalbhasa (Newari)",
            "ne" => "Nepali",
            "nso" => "Northern Sotho (Sepedi)",
            "no" => "Norwegian",
            "nus" => "Nuer",
            "oc" => "Occitan",
            "or" => "Odia (Oriya)",
            "om" => "Oromo",
            "pag" => "Pangasinan",
            "pap" => "Papiamento",
            "ps" => "Pashto",
            "fa" => "Persian",
            "pl" => "Polish",
            "pt" => "Portuguese",
            "pt-PT" => "Portuguese (Portugal)",
            "pt-BR" => "Portuguese (Brazil)",
            "pa" => "Punjabi",
            "pa-Arab" => "Punjabi (Shahmukhi)",
            "qu" => "Quechua",
            "rom" => "Romani",
            "ro" => "Romanian",
            "rn" => "Rundi",
            "ru" => "Russian",
            "sm" => "Samoan",
            "sg" => "Sango",
            "sa" => "Sanskrit",
            "gd" => "Scots Gaelic",
            "sr" => "Serbian",
            "st" => "Sesotho",
            "crs" => "Seychellois Creole",
            "shn" => "Shan",
            "sn" => "Shona",
            "scn" => "Sicilian",
            "szl" => "Silesian",
            "sd" => "Sindhi",
            "si" => "Sinhala",
            "so" => "Somali",
            "es" => "Spanish",
            "su" => "Sundanese",
            "sw" => "Swahili",
            "ss" => "Swati",
            "sv" => "Swedish",
            "tg" => "Tajik",
            "ta" => "Tamil",
            "tt" => "Tatar",
            "te" => "Telugu",
            "tet" => "Tetum",
            "th" => "Thai",
            "ti" => "Tigrinya",
            "ts" => "Tsonga",
            "tn" => "Tswana",
            "tr" => "Turkish",
            "tk" => "Turkmen",
            "ak" => "Twi (Akan)",
            "uk" => "Ukrainian",
            "ur" => "Urdu",
            "ug" => "Uyghur",
            "uz" => "Uzbek",
            "vi" => "Vietnamese",
            "cy" => "Welsh",
            "xh" => "Xhosa",
            "yi" => "Yiddish",
            "yo" => "Yoruba",
            "yua" => "Yucatec Maya",
            "zu" => "Zulu",
        ];

    protected string $language = 'en-US';

    public function __construct()
    {
        ksort($this->languages);

        if (isset($_SESSION['session_lang'])) {
            $this->language = $_SESSION['session_lang'];
        }
    }

    public function getDefaultLanguage(): string
    {
        return $this->language;
    }

    public function setDefaultLanguage(string $language): LanguageSupport
    {
        if (isset($this->languages[$language])) {
            $_SESSION['session_lang'] = $language;
        }
        return $this;
    }

    public function getLanguage(string $language = 'en'): string
    {
        return $this->languages[$language] ?? "";
    }

    public function translate(string $html, string $from, string $to, int $maxLength = 4999): string
    {
        if (!class_exists(Translate::class)) {
            return $html;
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        $innerHTML = '';
        foreach ($body->childNodes as $child) {
            $innerHTML .= $dom->saveHTML($child);
        }

        $maxLength = 3000; // max per request
        $translated = '';

        // Split string into chunks of <= 5000 characters
        $chunks = str_split($innerHTML, $maxLength);
        foreach ($chunks as $chunk) {
            //dd($chunk, $from, $to, $maxLength);
            $translate = Translate::translate($chunk, from: $from, to: $to);
            $translate->process();
            $translated .= $translate->getTranslatedText();
        }

        return $translated;
    }

}