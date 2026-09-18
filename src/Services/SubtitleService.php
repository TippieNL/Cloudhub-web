<?php
declare(strict_types=1);
namespace CloudHub\Services;

use RuntimeException;

/**
 * Subtitles that already sit beside a video, found and served as WebVTT.
 *
 * Nobody uploads a subtitle through a subtitle screen: the .srt arrives in the
 * same drag as the .mkv, named after it. So there is nothing to configure here
 * -- playing `Holiday.mp4` looks for `Holiday.srt`, `Holiday.en.srt`,
 * `Holiday.nl.forced.srt` and the `Subs/` folder that ripping tools leave
 * beside it, and offers what it finds.
 *
 * Two things stop that from being a one-liner:
 *
 *   SRT is not WebVTT. The timestamps use a comma, there are sequence numbers
 *   between the cues, and browsers reject the file outright. It is converted on
 *   the way out rather than on disk, because the file belongs to the user and
 *   this feature has no business rewriting it.
 *
 *   SRT has no encoding. A subtitle written on Windows is very often
 *   Windows-1252, and serving those bytes as UTF-8 turns every accented
 *   character into a replacement glyph -- which is exactly the half of a
 *   subtitle track a person notices. Anything that is not valid UTF-8 is
 *   converted before it is parsed.
 *
 * Everything here reads bytes and filenames chosen by whoever uploaded them,
 * so the walk is bounded (how many entries are looked at, how many tracks are
 * returned, how many bytes are read) and cue text is escaped and re-allowed
 * rather than passed through.
 */
final class SubtitleService
{
    /** Sidecar formats understood. Everything else is left alone. */
    public const EXTENSIONS = ['srt', 'vtt'];

    /** Videos a `Subs/` folder is allowed to belong to, when it holds just one. */
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'mov', 'm4v', 'avi', 'mkv',
        'mpeg', 'mpg', '3gp', '3g2', 'ts', 'm2ts', 'mts'];

    /** Folder names ripping tools use for subtitles beside a film. */
    private const SIDECAR_DIRS = ['subs', 'subtitles', 'sub', 'subtitle'];

    /** A subtitle file larger than this is not a subtitle file. */
    private const MAX_BYTES = 4194304;

    /** Directory entries looked at per folder, so a huge folder stays cheap. */
    private const MAX_SCANNED = 5000;

    /** Tracks offered for one video. Past this the menu is unusable anyway. */
    private const MAX_TRACKS = 24;

    /** Tag spellings that name a language, mapped to one code per language. */
    private const LANGUAGE_ALIASES = [
        'en' => 'en', 'eng' => 'en', 'english' => 'en',
        'nl' => 'nl', 'nld' => 'nl', 'dut' => 'nl', 'dutch' => 'nl', 'nederlands' => 'nl',
        'de' => 'de', 'deu' => 'de', 'ger' => 'de', 'german' => 'de', 'deutsch' => 'de',
        'fr' => 'fr', 'fra' => 'fr', 'fre' => 'fr', 'french' => 'fr',
        'es' => 'es', 'spa' => 'es', 'spanish' => 'es', 'espanol' => 'es',
        'it' => 'it', 'ita' => 'it', 'italian' => 'it',
        'pt' => 'pt', 'por' => 'pt', 'portuguese' => 'pt',
        'pt-br' => 'pt-BR', 'ptbr' => 'pt-BR', 'pob' => 'pt-BR', 'brazilian' => 'pt-BR',
        'sv' => 'sv', 'swe' => 'sv', 'swedish' => 'sv',
        'da' => 'da', 'dan' => 'da', 'danish' => 'da',
        'no' => 'no', 'nor' => 'no', 'nob' => 'no', 'norwegian' => 'no',
        'fi' => 'fi', 'fin' => 'fi', 'finnish' => 'fi',
        'is' => 'is', 'isl' => 'is', 'ice' => 'is',
        'pl' => 'pl', 'pol' => 'pl', 'polish' => 'pl',
        'ru' => 'ru', 'rus' => 'ru', 'russian' => 'ru',
        'uk' => 'uk', 'ukr' => 'uk', 'ukrainian' => 'uk',
        'cs' => 'cs', 'ces' => 'cs', 'cze' => 'cs', 'czech' => 'cs',
        'sk' => 'sk', 'slk' => 'sk', 'slo' => 'sk',
        'sl' => 'sl', 'slv' => 'sl',
        'hr' => 'hr', 'hrv' => 'hr', 'croatian' => 'hr',
        'sr' => 'sr', 'srp' => 'sr', 'serbian' => 'sr',
        'bg' => 'bg', 'bul' => 'bg',
        'ro' => 'ro', 'ron' => 'ro', 'rum' => 'ro', 'romanian' => 'ro',
        'hu' => 'hu', 'hun' => 'hu', 'hungarian' => 'hu',
        'el' => 'el', 'ell' => 'el', 'gre' => 'el', 'greek' => 'el',
        'tr' => 'tr', 'tur' => 'tr', 'turkish' => 'tr',
        'he' => 'he', 'heb' => 'he', 'hebrew' => 'he',
        'ar' => 'ar', 'ara' => 'ar', 'arabic' => 'ar',
        'fa' => 'fa', 'fas' => 'fa', 'per' => 'fa', 'persian' => 'fa',
        'hi' => 'hi', 'hin' => 'hi', 'hindi' => 'hi',
        'zh' => 'zh', 'chi' => 'zh', 'zho' => 'zh', 'chinese' => 'zh',
        'ja' => 'ja', 'jpn' => 'ja', 'japanese' => 'ja',
        'ko' => 'ko', 'kor' => 'ko', 'korean' => 'ko',
        'th' => 'th', 'tha' => 'th', 'thai' => 'th',
        'vi' => 'vi', 'vie' => 'vi', 'vietnamese' => 'vi',
        'id' => 'id', 'ind' => 'id', 'indonesian' => 'id',
        'ms' => 'ms', 'msa' => 'ms', 'may' => 'ms',
        'et' => 'et', 'est' => 'et', 'lv' => 'lv', 'lav' => 'lv', 'lt' => 'lt', 'lit' => 'lt',
        'ca' => 'ca', 'cat' => 'ca', 'gl' => 'gl', 'glg' => 'gl', 'eu' => 'eu', 'eus' => 'eu', 'baq' => 'eu',
    ];

    /** How each code is written in the menu. */
    private const LANGUAGE_NAMES = [
        'en' => 'English', 'nl' => 'Dutch', 'de' => 'German', 'fr' => 'French',
        'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'pt-BR' => 'Portuguese (Brazil)',
        'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
        'is' => 'Icelandic', 'pl' => 'Polish', 'ru' => 'Russian', 'uk' => 'Ukrainian',
        'cs' => 'Czech', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'hr' => 'Croatian',
        'sr' => 'Serbian', 'bg' => 'Bulgarian', 'ro' => 'Romanian', 'hu' => 'Hungarian',
        'el' => 'Greek', 'tr' => 'Turkish', 'he' => 'Hebrew', 'ar' => 'Arabic',
        'fa' => 'Persian', 'hi' => 'Hindi', 'zh' => 'Chinese', 'ja' => 'Japanese',
        'ko' => 'Korean', 'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian',
        'ms' => 'Malay', 'et' => 'Estonian', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
        'ca' => 'Catalan', 'gl' => 'Galician', 'eu' => 'Basque',
    ];

    /** Tags that describe the track rather than name its language. */
    private const FLAGS = [
        'forced' => 'forced', 'foreign' => 'forced',
        'sdh' => 'sdh', 'cc' => 'sdh', 'hi' => 'sdh', 'hearingimpaired' => 'sdh',
    ];

    public function __construct(private FileService $fs) {}

    /**
     * Every subtitle track that belongs to one video.
     *
     * @return list<array{id:string,path:string,name:string,label:string,language:string,forced:bool,hearingImpaired:bool,format:string,size:int}>
     */
    public function tracksFor(string $videoRelativePath): array
    {
        try {
            $video = $this->fs->existing($videoRelativePath);
        }catch(RuntimeException) {
            return [];
        }
        if (!is_file($video)) return [];

        $dir = dirname($video);
        $stem = (string)pathinfo($video, PATHINFO_FILENAME);
        $found = [];

        $siblings = $this->entries($dir);
        foreach ($siblings as $name) {
            if ($this->matchesStem($name, $stem)) $this->take($dir.'/'.$name, $stem, $found);
        }

        // A `Subs/` folder beside the film. Its files are named for the
        // language and not for the film ("English.srt"), so they are only
        // claimed when there is one film for them to belong to -- otherwise a
        // folder of episodes would show every episode the same tracks.
        $loneVideo = $this->loneVideo($siblings, $video);
        foreach ($siblings as $name) {
            if (!in_array(strtolower($name), self::SIDECAR_DIRS, true)) continue;
            $sub = $dir.'/'.$name;
            if (!is_dir($sub) || is_link($sub)) continue;

            foreach ($this->entries($sub) as $inner) {
                $full = $sub.'/'.$inner;
                if (is_dir($full)) {
                    // Subs/<film name>/English.srt
                    if (strcasecmp($inner, $stem) !== 0 || is_link($full)) continue;
                    foreach ($this->entries($full) as $leaf)$this->take($full.'/'.$leaf, $stem, $found);
                    continue;
                }
                if ($loneVideo || $this->matchesStem($inner, $stem))$this->take($full, $stem, $found);
            }
        }

        return $this->finish($found);
    }

    /**
     * One subtitle file as WebVTT, whatever it was written as.
     *
     * @throws RuntimeException with an HTTP status, as the API routes expect.
     */
    public function webVtt(string $subtitleRelativePath): string
    {
        $file = $this->fs->existing($subtitleRelativePath);
        if (!is_file($file))throw new RuntimeException('Subtitle file not found', 404);

        $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('That file is not a subtitle track', 415);
        }
        if ((int)(@filesize($file) ?: 0) > self::MAX_BYTES) {
            throw new RuntimeException('That subtitle file is too large to convert', 413);
        }

        $raw = @file_get_contents($file, false, null, 0, self::MAX_BYTES);
        if ($raw === false)throw new RuntimeException('Unable to read the subtitle file', 500);

        $text = self::toUtf8($raw);
        return $extension === 'vtt' ? self::normaliseVtt($text) : self::srtToVtt($text);
    }

    /* ---- discovery ------------------------------------------------------ */

    /** Names in one directory, bounded, with symlinks and dot files dropped. */
    private function entries(string $dir): array
    {
        $names = @scandir($dir);
        if ($names === false) return [];

        $out = []; $seen = 0;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) continue;
            if (++$seen > self::MAX_SCANNED) break;
            if (is_link($dir.'/'.$name)) continue;
            $out[] = $name;
        }
        return $out;
    }

    /** `Holiday.srt` and `Holiday.en.forced.srt`, but not `Holiday 2.srt`. */
    private function matchesStem(string $name, string $stem): bool
    {
        $base = (string)pathinfo($name, PATHINFO_FILENAME);
        return strcasecmp($base, $stem) === 0 || stripos($base, $stem.'.') === 0;
    }

    /** Whether this video is the only one in its folder. */
    private function loneVideo(array $siblings, string $video): bool
    {
        $count = 0;
        foreach ($siblings as $name) {
            $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, self::VIDEO_EXTENSIONS, true) && ++$count > 1) return false;
        }
        return $count === 1 && in_array(
            strtolower((string)pathinfo($video, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true
        );
    }

    /** Record one candidate file, if it is a subtitle worth offering. */
    private function take(string $full, string $stem, array &$found): void
    {
        if (count($found) >= self::MAX_TRACKS) return;
        if (!is_file($full) || is_link($full)) return;

        $extension = strtolower((string)pathinfo($full, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) return;

        $size = (int)(@filesize($full) ?: 0);
        if ($size <= 0 || $size > self::MAX_BYTES) return;

        try {
            $relative = $this->fs->relative($full);
        }catch(RuntimeException) {
            return;
        }
        if (isset($found[$relative])) return;

        $tags = self::tagsOf((string)pathinfo($full, PATHINFO_FILENAME), $stem);
        $found[$relative] = [
            // Stable across page loads, and says nothing about the filesystem:
            // the client only needs something to compare menu entries by.
            'id' => substr(hash('sha256', $relative), 0, 16),
            'path' => $relative,
            'name' => basename($full),
            'label' => $tags['label'],
            'language' => $tags['language'],
            'forced' => $tags['forced'],
            'hearingImpaired' => $tags['sdh'],
            'format' => $extension,
            'size' => $size,
        ];
    }

    /**
     * Read `en`, `forced` and `SDH` out of what is left of a filename once the
     * video's own name has been taken off the front.
     *
     * The language is claimed first, which is what keeps `Film.hi.srt` Hindi
     * while `Film.en.hi.srt` is English for the hearing impaired.
     *
     * @return array{label:string,language:string,forced:bool,sdh:bool}
     */
    private static function tagsOf(string $base, string $stem): array
    {
        $rest = stripos($base, $stem) === 0 ? substr($base, strlen($stem)) : $base;
        $parts = preg_split('/[._\-\s]+/u', trim($rest, "._- \t")) ?: [];

        $language = ''; $forced = false; $sdh = false; $extra = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || ctype_digit($part)) continue;

            $key = strtolower($part);
            if ($language === '' && isset(self::LANGUAGE_ALIASES[$key])) {
                $language = self::LANGUAGE_ALIASES[$key];
                continue;
            }
            if (isset(self::FLAGS[$key])) {
                if (self::FLAGS[$key] === 'forced') $forced = true; else $sdh = true;
                continue;
            }
            if (count($extra) < 3 && mb_strlen($part) <= 24) $extra[] = $part;
        }

        $label = $language !== ''
            ? (self::LANGUAGE_NAMES[$language] ?? strtoupper($language))
            : ($extra ? implode(' ', $extra) : 'Subtitles');
        if ($language !== '' && $extra) $label .= ' ('.implode(' ', $extra).')';
        if ($sdh) $label .= ' (SDH)';
        if ($forced) $label .= ' (Forced)';

        return ['label' => $label, 'language' => $language, 'forced' => $forced, 'sdh' => $sdh];
    }

    /** Order the tracks and make sure no two of them read the same. */
    private function finish(array $found): array
    {
        $tracks = array_values($found);
        usort($tracks, static function(array $a, array $b): int {
            // Named languages first and alphabetically, so the menu reads the
            // same on every video; a forced track sits under its language.
            $named = ($b['language'] !== '') <=> ($a['language'] !== '');
            if ($named !== 0) return $named;
            $byLanguage = strcasecmp($a['label'], $b['label']);
            return $byLanguage !== 0 ? $byLanguage : strcasecmp($a['name'], $b['name']);
        });

        $counts = [];
        foreach ($tracks as $track)$counts[$track['label']] = ($counts[$track['label']] ?? 0) + 1;
        foreach ($tracks as $index => $track) {
            if ($counts[$track['label']] > 1) {
                $tracks[$index]['label'] = $track['label'].' · '.$track['name'];
            }
        }

        return array_slice($tracks, 0, self::MAX_TRACKS);
    }

    /* ---- conversion ----------------------------------------------------- */

    /**
     * Bytes to UTF-8 text.
     *
     * A BOM is believed; otherwise anything that is not already valid UTF-8 is
     * read as Windows-1252, which is what a subtitle written on Windows almost
     * always is and which cannot fail -- every byte maps to something.
     */
    public static function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) return substr($raw, 3);

        foreach (["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'] as $bom => $encoding) {
            if (!str_starts_with($raw, $bom)) continue;
            if (!function_exists('mb_convert_encoding')) return substr($raw, 2);
            return (string)mb_convert_encoding(substr($raw, 2), 'UTF-8', $encoding);
        }

        if (!function_exists('mb_check_encoding') || mb_check_encoding($raw, 'UTF-8')) return $raw;
        return (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    /** A .vtt as served: real header, real timestamps, no stray BOM. */
    public static function normaliseVtt(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if (str_contains($line, '-->')) $lines[$index] = self::timingLine($line) ?? $line;
        }

        // A file named .vtt that is really an SRT is common enough to be worth
        // handling: without the header a browser rejects it outright.
        $body = implode("\n", $lines);
        if (!str_starts_with(ltrim($body), 'WEBVTT')) $body = "WEBVTT\n\n".ltrim($body, "\n");
        return rtrim($body, "\n")."\n";
    }

    /** SubRip to WebVTT: header, dotted timestamps, no sequence numbers. */
    public static function srtToVtt(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $out = [];
        $total = count($lines);

        for ($i = 0; $i < $total; $i++) {
            $line = $lines[$i];

            if (str_contains($line, '-->')) {
                $timing = self::timingLine($line);
                if ($timing === null) continue;   // not a timing line after all
                // The cue number ahead of it, which WebVTT has no use for.
                if ($out !== [] && ctype_digit(trim((string)end($out)))) array_pop($out);
                $out[] = $timing;
                continue;
            }

            $out[] = trim($line) === '' ? '' : self::cueText($line);
        }

        $body = trim(implode("\n", $out), "\n");
        return "WEBVTT\n\n".$body."\n";
    }

    /**
     * `00:01:02,500 --> 00:01:05,000  X1:100` as WebVTT, or null if the line
     * only looked like a timing line.
     */
    private static function timingLine(string $line): ?string
    {
        $stamp = '(?:\d{1,3}:)?\d{1,3}:\d{1,2}[,.]\d{1,3}';
        if (!preg_match('/^\s*('.$stamp.')\s*-->\s*('.$stamp.')\s*(.*)$/u', $line, $m)) return null;

        // SRT's coordinates (X1:.. Y1:..) mean nothing here; WebVTT's own cue
        // settings (align:, position:, line:, size:, vertical:, region:) do.
        $settings = trim($m[3]);
        if ($settings !== '' && !preg_match('/^(?:align|position|line|size|vertical|region):/u', $settings)) {
            $settings = '';
        }

        return self::timestamp($m[1]).' --> '.self::timestamp($m[2]).($settings !== '' ? ' '.$settings : '');
    }

    /** `1:02,5` or `00:01:02,500` as `00:01:02.500`. */
    private static function timestamp(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        $parts = explode(':', $value);
        $seconds = array_pop($parts) ?? '0';
        $minutes = array_pop($parts) ?? '0';
        $hours = array_pop($parts) ?? '0';

        [$whole, $fraction] = array_pad(explode('.', $seconds, 2), 2, '0');

        return sprintf('%02d:%02d:%02d.%03d',
            (int)$hours, (int)$minutes, (int)$whole, (int)str_pad(substr($fraction, 0, 3), 3, '0'));
    }

    /**
     * One line of cue text.
     *
     * Everything is escaped and then the handful of inline tags WebVTT shares
     * with SRT are put back, so markup that happens to be inside a subtitle
     * cannot arrive as markup. Positioning overrides from ASS-flavoured files
     * and SRT's `<font>` colours have no WebVTT equivalent and are dropped.
     */
    private static function cueText(string $line): string
    {
        $line = preg_replace('/\{\\\\[^}]*\}/u', '', $line) ?? $line;
        $line = preg_replace('#</?font[^>]*>#iu', '', $line) ?? $line;
        $line = htmlspecialchars($line, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return preg_replace('#&lt;(/?[ibu])&gt;#i', '<$1>', $line) ?? $line;
    }
}
