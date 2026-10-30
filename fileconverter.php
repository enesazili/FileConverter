<?php

declare(strict_types=1);

/*
 * File Converter — tek dosyalık, bağımsız sürüm.
 *
 * Bu dosya portfolyo koduna veya harici bir JS/CSS dosyasına ihtiyaç
 * duymaz; tüm PHP kodu bu dosyanın içindedir. Çalışması için PHP 8.1+ ve
 * kullanılan biçimlere göre GD, fileinfo, exif, PharData, zlib ve dom
 * eklentileri gerekir. Belge, ses, video ve PDF dönüşümleri yalnızca sunucuda
 * LibreOffice, FFmpeg/FFprobe, Poppler, Imagick, rsvg-convert veya 7-Zip
 * kuruluysa kullanılabilir.
 *
 * Bu sürümde de dönüşümler isteğe özel geçici dizinde yapılır. Geçici dizin
 * yanıt sonrasında silinir; veritabanı, oturum veya kalıcı indirme bağlantısı
 * oluşturulmaz.
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fc_capabilities')) {
/* ---- Bölüm: motor tespiti ve süreç çalıştırıcı (engine_runner) ---- */
/**
 * File Converter — isteğe bağlı dönüştürme motorlarının tespiti ve
 * kabuk kullanmayan, zaman aşımı/çıktı sınırı olan süreç çalıştırıcısı.
 *
 * Kullanıcı girdisi hiçbir zaman kabuk komutuna dönüştürülmez; komutlar
 * her zaman argv dizisi olarak `proc_open()` çağrısına verilir.
 */

/**
 * PATH üzerinde çalıştırılabilir bir dosya arar. Windows'ta yalnızca
 * `.exe` uzantısı denenir; `.bat`/`.cmd` kabuk üzerinden çalışabileceği
 * için kabul edilmez.
 *
 * @param string $name Yalnızca dosya adı (ör. "ffmpeg"), yol değil.
 * @param string $explicit Ortam değişkeniyle verilmiş açık yol.
 */
function fc_which(string $name, string $explicit = ''): ?string
{
    $name = trim($name);

    if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
        return null;
    }

    $explicit = trim($explicit);

    if ($explicit !== '') {
        return fc_executable_path($explicit);
    }

    $candidates = [];

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = $name . '.exe';
    } else {
        $candidates[] = $name;
    }

    $path = (string) getenv('PATH');

    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $directory = trim($directory);

        if ($directory === '') {
            continue;
        }

        foreach ($candidates as $candidate) {
            $full = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $candidate;
            $found = fc_executable_path($full);

            if ($found !== null) {
                return $found;
            }
        }
    }

    foreach (fc_engine_common_paths($name) as $full) {
        $found = fc_executable_path($full);

        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * Verilen yolun gerçek, çalıştırılabilir bir dosya olduğunu doğrular.
 */
function fc_executable_path(string $path): ?string
{
    $path = trim($path);

    if ($path === '' || !is_file($path)) {
        return null;
    }

    $real = realpath($path);

    if ($real === false || !is_file($real)) {
        return null;
    }

    if (DIRECTORY_SEPARATOR !== '\\' && !is_executable($real)) {
        return null;
    }

    return $real;
}

/**
 * Windows ve Linux'ta yaygın kurulum yolları. Bu yollar yalnızca varlık
 * denetimidir; kullanıcı girdisiyle birleştirilmez.
 *
 * @return list<string>
 */
function fc_engine_common_paths(string $name): array
{
    $paths = [];

    if (DIRECTORY_SEPARATOR === '\\') {
        if ($name === 'soffice' || $name === 'libreoffice') {
            $paths[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
            $paths[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe';
        }

        if ($name === '7z' || $name === '7za' || $name === '7zz') {
            $paths[] = 'C:\\Program Files\\7-Zip\\7z.exe';
            $paths[] = 'C:\\Program Files (x86)\\7-Zip\\7z.exe';
        }

        if ($name === 'ffmpeg' || $name === 'ffprobe') {
            $paths[] = 'C:\\ffmpeg\\bin\\' . $name . '.exe';
        }
    } else {
        $paths[] = '/usr/bin/' . $name;
        $paths[] = '/usr/local/bin/' . $name;
        $paths[] = '/opt/homebrew/bin/' . $name;
    }

    return $paths;
}

/**
 * Bir süreci argv dizisiyle çalıştırır; kabuk kullanmaz.
 *
 * @param list<string> $command
 * @return array{
 *     ok: bool,
 *     exit: int,
 *     stdout: string,
 *     stderr: string,
 *     timed_out: bool,
 *     truncated: bool
 * }
 */
function fc_run_process(
    array $command,
    string $cwd,
    int $timeoutSeconds,
    int $stdoutCap = 65536,
    int $stderrCap = 65536
): array {
    $empty = [
        'ok' => false,
        'exit' => -1,
        'stdout' => '',
        'stderr' => '',
        'timed_out' => false,
        'truncated' => false,
    ];

    if ($command === [] || !is_dir($cwd)) {
        return $empty;
    }

    foreach ($command as $part) {
        if (!is_string($part) || $part === '' || str_contains($part, "\0")) {
            return $empty;
        }
    }

    $stdoutPath = @tempnam(sys_get_temp_dir(), 'fc-out-');
    $stderrPath = @tempnam(sys_get_temp_dir(), 'fc-err-');

    if ($stdoutPath === false || $stderrPath === false) {
        if (is_string($stdoutPath)) {
            @unlink($stdoutPath);
        }

        return $empty;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ];

    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);

        return $empty;
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    $timedOut = false;
    $truncated = false;
    $exit = -1;
    $started = microtime(true);
    $timeoutSeconds = max(1, $timeoutSeconds);

    while (true) {
        $status = proc_get_status($process);
        clearstatcache(true, $stdoutPath);
        clearstatcache(true, $stderrPath);
        $stdoutSize = (int) @filesize($stdoutPath);
        $stderrSize = (int) @filesize($stderrPath);

        if ($stdoutSize > $stdoutCap || $stderrSize > $stderrCap) {
            $truncated = true;
            fc_terminate_process($process);
            break;
        }

        if (!$status['running']) {
            $exit = (int) ($status['exitcode'] ?? -1);
            break;
        }

        if (microtime(true) - $started > $timeoutSeconds) {
            $timedOut = true;
            fc_terminate_process($process);
            break;
        }

        usleep(20000);
    }

    $closed = proc_close($process);

    if ($exit < 0) {
        $exit = (int) $closed;
    }

    $stdout = (string) @file_get_contents($stdoutPath, false, null, 0, $stdoutCap);
    $stderr = (string) @file_get_contents($stderrPath, false, null, 0, $stderrCap);

    clearstatcache(true, $stdoutPath);
    clearstatcache(true, $stderrPath);

    if ((int) @filesize($stdoutPath) > $stdoutCap || (int) @filesize($stderrPath) > $stderrCap) {
        $truncated = true;
    }

    @unlink($stdoutPath);
    @unlink($stderrPath);

    return [
        'ok' => !$timedOut && !$truncated && $exit === 0,
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
        'truncated' => $truncated,
    ];
}

/**
 * Süreci ve mümkünse alt süreçlerini sonlandırır. PID değeri yalnızca
 * proc_get_status() tarafından üretilir; kullanıcı girdisi değildir.
 *
 * @param resource $process
 */
function fc_terminate_process($process): void
{
    $status = @proc_get_status($process);
    $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;

    @proc_terminate($process, 9);

    if ($pid > 0 && DIRECTORY_SEPARATOR === '\\') {
        $taskkill = fc_which('taskkill');

        if ($taskkill !== null) {
            $kill = @proc_open(
                [$taskkill, '/F', '/T', '/PID', (string) $pid],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $killPipes,
                null,
                null,
                ['bypass_shell' => true]
            );

            if (is_resource($kill)) {
                foreach ($killPipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                @proc_close($kill);
            }
        }
    }
}

/**
 * Bir aracın varlığını ve beklenen sürüm metnini denetler.
 *
 * @param list<string> $args
 * @return array{available: bool, version: string, path: string}
 */
function fc_probe_binary(string $path, array $args, string $needle, int $timeout = 3): array
{
    $result = fc_run_process(
        array_merge([$path], $args),
        sys_get_temp_dir(),
        $timeout,
        8192,
        8192
    );

    $output = trim($result['stdout'] . "\n" . $result['stderr']);

    if ($result['timed_out'] || $result['truncated'] || $output === '') {
        return ['available' => false, 'version' => '', 'path' => $path];
    }

    if ($needle !== '' && stripos($output, $needle) === false) {
        return ['available' => false, 'version' => '', 'path' => $path];
    }

    $version = '';

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $line = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $line) ?? '');

        if ($line === '') {
            continue;
        }

        if ($needle === '' || stripos($line, $needle) !== false) {
            $version = fc_truncate($line, 120);
            break;
        }
    }

    if ($version === '') {
        $version = fc_truncate((string) strtok($output, "\r\n"), 120);
    }

    return ['available' => true, 'version' => $version, 'path' => $path];
}

/**
 * Bu istek için motor durumunu (bir kez) tespit eder.
 *
 * @return array<string, mixed>
 */
function fc_engine_status(): array
{
    static $status = null;

    if (is_array($status)) {
        return $status;
    }

    $gdLoaded = extension_loaded('gd');
    $gdInfo = $gdLoaded && function_exists('gd_info') ? gd_info() : [];
    $gdFormats = [];
    $gdMap = [
        'jpeg' => ['JPEG Support', 'imagecreatefromjpeg', 'imagejpeg'],
        'png' => ['PNG Support', 'imagecreatefrompng', 'imagepng'],
        'webp' => ['WebP Support', 'imagecreatefromwebp', 'imagewebp'],
        'gif' => ['GIF Read Support', 'imagecreatefromgif', 'imagegif'],
        'bmp' => ['BMP Support', 'imagecreatefrombmp', 'imagebmp'],
        'avif' => ['AVIF Support', 'imagecreatefromavif', 'imageavif'],
    ];

    foreach ($gdMap as $format => $rule) {
        $supported = $gdLoaded
            && function_exists($rule[1])
            && function_exists($rule[2])
            && !empty($gdInfo[$rule[0]]);

        if ($supported) {
            $gdFormats[] = $format;
        }
    }

    $imagick = class_exists('Imagick');
    $imagickVersion = '';

    if ($imagick) {
        try {
            $version = Imagick::getVersion();
            $imagickVersion = fc_truncate((string) ($version['versionString'] ?? ''), 120);
        } catch (Throwable) {
            $imagick = false;
        }
    }

    $ffmpegPath = fc_which('ffmpeg', (string) (getenv('FC_FFMPEG_BIN') ?: ''));
    $ffprobePath = fc_which('ffprobe', (string) (getenv('FC_FFPROBE_BIN') ?: ''));
    $ffmpeg = $ffmpegPath !== null
        ? fc_probe_binary($ffmpegPath, ['-version'], 'ffmpeg version')
        : ['available' => false, 'version' => '', 'path' => ''];
    $ffprobe = $ffprobePath !== null
        ? fc_probe_binary($ffprobePath, ['-version'], 'ffprobe version')
        : ['available' => false, 'version' => '', 'path' => ''];

    if (!$ffmpeg['available'] || !$ffprobe['available']) {
        $ffmpeg['available'] = false;
    }

    $sofficePath = fc_which('soffice', (string) (getenv('FC_SOFFICE_BIN') ?: ''));

    if ($sofficePath === null) {
        $sofficePath = fc_which('libreoffice', (string) (getenv('FC_SOFFICE_BIN') ?: ''));
    }

    $soffice = $sofficePath !== null
        ? fc_probe_binary($sofficePath, ['--version'], 'LibreOffice')
        : ['available' => false, 'version' => '', 'path' => ''];

    $pdftoppmPath = fc_which('pdftoppm', (string) (getenv('FC_PDFTOPPM_BIN') ?: ''));
    $pdftotextPath = fc_which('pdftotext', (string) (getenv('FC_PDFTOTEXT_BIN') ?: ''));
    $pdftoppm = $pdftoppmPath !== null
        ? fc_probe_binary($pdftoppmPath, ['-v'], 'pdftoppm')
        : ['available' => false, 'version' => '', 'path' => ''];
    $pdftotext = $pdftotextPath !== null
        ? fc_probe_binary($pdftotextPath, ['-v'], 'pdftotext')
        : ['available' => false, 'version' => '', 'path' => ''];

    $sevenZipPath = fc_which('7z', (string) (getenv('FC_7Z_BIN') ?: ''));

    if ($sevenZipPath === null) {
        $sevenZipPath = fc_which('7za', (string) (getenv('FC_7Z_BIN') ?: ''));
    }

    if ($sevenZipPath === null) {
        $sevenZipPath = fc_which('7zz', (string) (getenv('FC_7Z_BIN') ?: ''));
    }

    $sevenZip = $sevenZipPath !== null
        ? fc_probe_binary($sevenZipPath, ['i'], '7-Zip')
        : ['available' => false, 'version' => '', 'path' => ''];

    $rsvgPath = fc_which('rsvg-convert', (string) (getenv('FC_RSVG_BIN') ?: ''));
    $rsvg = $rsvgPath !== null
        ? fc_probe_binary($rsvgPath, ['--version'], 'rsvg-convert')
        : ['available' => false, 'version' => '', 'path' => ''];

    $xzPath = fc_which('xz', (string) (getenv('FC_XZ_BIN') ?: ''));
    $xz = $xzPath !== null
        ? fc_probe_binary($xzPath, ['--version'], 'xz', 2)
        : ['available' => false, 'version' => '', 'path' => ''];

    $bzip2Path = fc_which('bzip2', (string) (getenv('FC_BZIP2_BIN') ?: ''));
    $bzip2 = $bzip2Path !== null
        ? fc_probe_binary($bzip2Path, ['--version'], 'bzip2', 2)
        : ['available' => false, 'version' => '', 'path' => ''];

    $status = [
        'core' => [
            'gd' => [
                'available' => $gdLoaded,
                'version' => (string) ($gdInfo['GD Version'] ?? ''),
                'formats' => $gdFormats,
            ],
            'fileinfo' => ['available' => class_exists('finfo')],
            'exif' => ['available' => function_exists('exif_read_data')],
            'zlib' => ['available' => extension_loaded('zlib')],
            'phar' => ['available' => class_exists('PharData')],
            'dom' => ['available' => class_exists('DOMDocument')],
            'json' => ['available' => function_exists('json_decode')],
            'mbstring' => ['available' => function_exists('mb_convert_encoding')],
            'iconv' => ['available' => function_exists('iconv')],
            'bz2' => ['available' => extension_loaded('bz2')],
        ],
        'optional' => [
            'imagick' => [
                'available' => $imagick,
                'version' => $imagickVersion,
                'path' => '',
            ],
            'ffmpeg' => $ffmpeg,
            'ffprobe' => $ffprobe,
            'libreoffice' => $soffice,
            'pdftoppm' => $pdftoppm,
            'pdftotext' => $pdftotext,
            'sevenzip' => $sevenZip,
            'rsvg' => $rsvg,
            'xz' => $xz,
            'bzip2' => $bzip2,
        ],
    ];

    return $status;
}

/**
 * @param array<string, mixed> $status
 */
function fc_engine_is_available(array $status, string $name): bool
{
    if (isset($status['core'][$name])) {
        return (bool) ($status['core'][$name]['available'] ?? false);
    }

    return (bool) ($status['optional'][$name]['available'] ?? false);
}

/**
 * FFmpeg'in bu sürümde sunduğu kodlayıcıları döndürür.
 *
 * @return array<string, true>
 */
function fc_ffmpeg_encoders(): array
{
    static $encoders = null;

    if (is_array($encoders)) {
        return $encoders;
    }

    $encoders = [];
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'ffmpeg')) {
        return $encoders;
    }

    $result = fc_run_process(
        [(string) $status['optional']['ffmpeg']['path'], '-hide_banner', '-encoders'],
        sys_get_temp_dir(),
        5,
        262144,
        16384
    );

    if (!$result['ok']) {
        return $encoders;
    }

    foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
        if (preg_match('/^\s*[VAS][A-Z.]{5}\s+(\S+)/', $line, $match) === 1) {
            $encoders[$match[1]] = true;
        }
    }

    return $encoders;
}

/**
 * Arayüze gönderilebilecek, sunucu yolu içermeyen motor özeti.
 *
 * @return array{available: array<string, string>, missing: array<string, string>}
 */
function fc_engine_public_summary(): array
{
    $status = fc_engine_status();
    $labels = [
        'gd' => 'GD (görseller)',
        'phar' => 'PharData (ZIP/TAR)',
        'zlib' => 'zlib (GZ)',
        'imagick' => 'Imagick (TIFF)',
        'rsvg' => 'rsvg-convert (SVG)',
        'ffmpeg' => 'FFmpeg (ses/video)',
        'libreoffice' => 'LibreOffice (ofis/PDF)',
        'pdftoppm' => 'Poppler pdftoppm (PDF→görsel)',
        'pdftotext' => 'Poppler pdftotext (PDF→metin)',
        'sevenzip' => '7-Zip (BZ2/XZ/7Z/RAR)',
        'xz' => 'xz (XZ)',
        'bzip2' => 'bzip2 (BZ2)',
    ];

    $available = [];
    $missing = [];

    foreach ($labels as $key => $label) {
        if (fc_engine_is_available($status, $key)) {
            $available[$key] = $label;
        } else {
            $missing[$key] = $label;
        }
    }

    return ['available' => $available, 'missing' => $missing];
}

/* ---- Bölüm: metin ve veri dönüşümleri (text_converter) ---- */
/**
 * File Converter — metin ve yapılandırılmış veri dönüşümleri.
 *
 * Yalnızca gerçekten anlamlı dönüşümler sunulur: JSON/YAML/TOML/XML/CSV
 * arasında yapısal serileştirme, Markdown→güvenli HTML, HTML/RTF→metin ve
 * düz metin→HTML. Uzantı değiştirmek dönüşüm sayılmaz.
 */

/**
 * @return array{ok: false, code: string, message: string}
 */
function fc_text_error(string $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'message' => $message];
}

/**
 * Metni UTF-8'e normalize eder; BOM'u kaldırır. Geçersiz baytları
 * Windows-1254/ISO-8859-9 ve Windows-1252 üzerinden kurtarmayı dener.
 *
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_normalize(string $bytes, int $maxBytes): array
{
    if (strlen($bytes) > $maxBytes) {
        return fc_text_error('file_too_large', 'Metin dosyası izin verilen boyuttan büyük.');
    }

    if (str_contains($bytes, "\0")) {
        return fc_text_error('binary_text', 'Metin dosyası ikili (binary) veri içeriyor.');
    }

    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = substr($bytes, 3);
    }

    if (mb_check_encoding($bytes, 'UTF-8')) {
        return ['ok' => true, 'text' => $bytes];
    }

    foreach (['Windows-1254', 'ISO-8859-9', 'Windows-1252'] as $encoding) {
        $converted = @mb_convert_encoding($bytes, 'UTF-8', $encoding);

        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return ['ok' => true, 'text' => $converted];
        }
    }

    return fc_text_error('invalid_encoding', 'Metin UTF-8 olarak okunamadı.');
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, text: string, format: string}|array{ok: false, code: string, message: string}
 */
function fc_text_read(string $path, string $format, array $fc): array
{
    $maxBytes = (int) ($fc['max_text_bytes'] ?? 8 * 1024 * 1024);
    $size = @filesize($path);

    if ($size === false || $size < 1) {
        return fc_text_error('empty_file', 'Dosya boş veya okunamadı.');
    }

    if ($size > $maxBytes) {
        return fc_text_error('file_too_large', 'Metin dosyası izin verilen boyuttan büyük.');
    }

    $bytes = @file_get_contents($path);

    if ($bytes === false) {
        return fc_text_error('read_failed', 'Dosya okunamadı.');
    }

    $normalized = fc_text_normalize($bytes, $maxBytes);

    if (!($normalized['ok'] ?? false)) {
        return $normalized;
    }

    return ['ok' => true, 'text' => $normalized['text'], 'format' => $format];
}

/**
 * @return array{ok: true, data: mixed}|array{ok: false, code: string, message: string}
 */
function fc_text_parse_json(string $text): array
{
    try {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('file-converter json parse: ' . $exception->getMessage());
        return fc_text_error('invalid_json', 'JSON geçersiz veya desteklenmeyen derinlikte.');
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * Basit ama güvenli bir YAML alt kümesi ayrıştırıcısı.
 *
 * Desteklenen: blok eşlemeleri/dizileri, tırnaklı ve düz skalerler,
 * satır içi `[...]` / `{...}`, yorumlar. Çapa/alias, çok satırlı blok ve
 * etiketler sessizce yanlış yorumlanmaz; açık hata döner.
 *
 * @return array{ok: true, data: mixed}|array{ok: false, code: string, message: string}
 */
function fc_text_parse_yaml(string $text): array
{
    $lines = [];
    $rawLines = preg_split('/\R/', $text) ?: [];

    foreach ($rawLines as $raw) {
        if (str_contains($raw, "\t")) {
            return fc_text_error('invalid_yaml', 'YAML satırlarında sekme kullanılamaz.');
        }

        $trimmed = trim($raw);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $indent = strlen($raw) - strlen(ltrim($raw, ' '));
        $content = fc_yaml_strip_comment(ltrim($raw, ' '));

        if (trim($content) === '') {
            continue;
        }

        $lines[] = ['indent' => $indent, 'text' => rtrim($content)];
    }

    if ($lines === []) {
        return ['ok' => true, 'data' => null];
    }

    $index = 0;
    $data = fc_yaml_parse_block($lines, $index, $lines[0]['indent']);

    if (!is_array($data) && $index < count($lines)) {
        return fc_text_error('invalid_yaml', 'YAML yapısı çözümlenemedi.');
    }

    if ($index !== count($lines)) {
        return fc_text_error('invalid_yaml', 'YAML girintisi veya yapısı desteklenmiyor.');
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * @param list<array{indent: int, text: string}> $lines
 */
function fc_yaml_parse_block(array $lines, int &$index, int $indent): mixed
{
    if ($index >= count($lines)) {
        return null;
    }

    $first = $lines[$index];

    if ($first['indent'] < $indent) {
        return null;
    }

    $text = ltrim($first['text']);

    if ($text === '-' || str_starts_with($text, '- ')) {
        return fc_yaml_parse_sequence($lines, $index, $first['indent']);
    }

    return fc_yaml_parse_mapping($lines, $index, $first['indent']);
}

/**
 * @param list<array{indent: int, text: string}> $lines
 * @return array<string, mixed>
 */
function fc_yaml_parse_mapping(array $lines, int &$index, int $indent): array
{
    $map = [];

    while ($index < count($lines)) {
        $line = $lines[$index];

        if ($line['indent'] < $indent) {
            break;
        }

        if ($line['indent'] > $indent) {
            throw new RuntimeException('YAML girintisi beklenmedik.');
        }

        $text = ltrim($line['text']);

        if ($text === '-' || str_starts_with($text, '- ')) {
            break;
        }

        $split = fc_yaml_split_key($text);

        if ($split === null) {
            throw new RuntimeException('YAML anahtarı çözümlenemedi.');
        }

        [$key, $rest] = $split;

        if (array_key_exists($key, $map)) {
            throw new RuntimeException('YAML anahtarı yineleniyor.');
        }

        $index++;

        if (trim($rest) !== '') {
            $map[$key] = fc_yaml_scalar(trim($rest));
            continue;
        }

        if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
            $map[$key] = fc_yaml_parse_block($lines, $index, $lines[$index]['indent']);
        } else {
            $map[$key] = null;
        }
    }

    return $map;
}

/**
 * @param list<array{indent: int, text: string}> $lines
 * @return list<mixed>
 */
function fc_yaml_parse_sequence(array $lines, int &$index, int $indent): array
{
    $list = [];

    while ($index < count($lines)) {
        $line = $lines[$index];

        if ($line['indent'] !== $indent) {
            break;
        }

        $text = ltrim($line['text']);

        if ($text !== '-' && !str_starts_with($text, '- ')) {
            break;
        }

        $rest = trim(substr($text, 1));
        $index++;

        if ($rest === '') {
            if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
                $list[] = fc_yaml_parse_block($lines, $index, $lines[$index]['indent']);
            } else {
                $list[] = null;
            }

            continue;
        }

        $split = fc_yaml_split_key($rest);

        if ($split !== null && !fc_yaml_is_quoted_scalar($rest)) {
            [$key, $value] = $split;
            $item = [];
            $item[$key] = trim($value) !== ''
                ? fc_yaml_scalar(trim($value))
                : null;

            if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
                $nested = fc_yaml_parse_block($lines, $index, $lines[$index]['indent']);

                if (is_array($nested)) {
                    foreach ($nested as $nestedKey => $nestedValue) {
                        if (array_key_exists($nestedKey, $item)) {
                            throw new RuntimeException('YAML anahtarı yineleniyor.');
                        }

                        $item[$nestedKey] = $nestedValue;
                    }
                }
            }

            $list[] = $item;
            continue;
        }

        $list[] = fc_yaml_scalar($rest);

        if ($index < count($lines) && $lines[$index]['indent'] > $indent) {
            throw new RuntimeException('Skaler YAML öğesinin altında blok olamaz.');
        }
    }

    return $list;
}

/**
 * Tırnak dışındaki ilk iki noktayı anahtar/değer ayırıcısı sayar.
 *
 * @return array{0: string, 1: string}|null
 */
function fc_yaml_split_key(string $text): ?array
{
    $length = strlen($text);
    $quote = '';
    $depth = 0;

    for ($index = 0; $index < $length; $index++) {
        $char = $text[$index];

        if ($quote !== '') {
            if ($char === '\\' && $quote === '"') {
                $index++;
                continue;
            }

            if ($char === $quote) {
                $quote = '';
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            continue;
        }

        if ($char === '[' || $char === '{') {
            $depth++;
            continue;
        }

        if ($char === ']' || $char === '}') {
            $depth--;
            continue;
        }

        if ($char === ':' && $depth === 0) {
            $key = trim(substr($text, 0, $index));
            $rest = substr($text, $index + 1);

            if ($key === '') {
                return null;
            }

            if (($key[0] === '"' && str_ends_with($key, '"')) || ($key[0] === "'" && str_ends_with($key, "'"))) {
                $key = fc_yaml_unquote($key);
            }

            return [$key, $rest];
        }
    }

    return null;
}

function fc_yaml_is_quoted_scalar(string $text): bool
{
    $text = trim($text);

    return ($text !== '' && $text[0] === '"' && str_ends_with($text, '"'))
        || ($text !== '' && $text[0] === "'" && str_ends_with($text, "'"));
}

function fc_yaml_unquote(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    if ($text[0] === "'" && str_ends_with($text, "'")) {
        return str_replace("''", "'", substr($text, 1, -1));
    }

    if ($text[0] === '"' && str_ends_with($text, '"')) {
        $decoded = json_decode($text, true);

        if (is_string($decoded)) {
            return $decoded;
        }

        return stripcslashes(substr($text, 1, -1));
    }

    return $text;
}

function fc_yaml_strip_comment(string $text): string
{
    $length = strlen($text);
    $quote = '';

    for ($index = 0; $index < $length; $index++) {
        $char = $text[$index];

        if ($quote !== '') {
            if ($char === '\\' && $quote === '"') {
                $index++;
                continue;
            }

            if ($char === $quote) {
                $quote = '';
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            continue;
        }

        if ($char === '#' && ($index === 0 || ctype_space($text[$index - 1]))) {
            return rtrim(substr($text, 0, $index));
        }
    }

    return rtrim($text);
}

function fc_yaml_scalar(string $text): mixed
{
    $text = trim($text);

    if ($text === '' || $text === '~' || strcasecmp($text, 'null') === 0) {
        return null;
    }

    if (strcasecmp($text, 'true') === 0) {
        return true;
    }

    if (strcasecmp($text, 'false') === 0) {
        return false;
    }

    if ($text[0] === '[' && str_ends_with($text, ']')) {
        $inner = trim(substr($text, 1, -1));

        if ($inner === '') {
            return [];
        }

        return array_map('fc_yaml_scalar', fc_text_split_inline($inner));
    }

    if ($text[0] === '{' && str_ends_with($text, '}')) {
        $inner = trim(substr($text, 1, -1));
        $map = [];

        if ($inner === '') {
            return $map;
        }

        foreach (fc_text_split_inline($inner) as $pair) {
            $split = fc_yaml_split_key($pair);

            if ($split === null) {
                throw new RuntimeException('YAML satır içi eşleme çözümlenemedi.');
            }

            $map[$split[0]] = fc_yaml_scalar(trim($split[1]));
        }

        return $map;
    }

    if ($text[0] === '|' || $text[0] === '>' || $text[0] === '&' || $text[0] === '*' || $text[0] === '!') {
        throw new RuntimeException('YAML blok/çapa/etiket sözdizimi desteklenmiyor.');
    }

    if (fc_yaml_is_quoted_scalar($text)) {
        return fc_yaml_unquote($text);
    }

    if (preg_match('/^-?\d+$/', $text) === 1) {
        return (int) $text;
    }

    if (preg_match('/^-?\d+\.\d+$/', $text) === 1) {
        return (float) $text;
    }

    return $text;
}

/**
 * Virgülle ayrılmış satır içi listeyi tırnak/parantez farkındalığıyla böler.
 *
 * @return list<string>
 */
function fc_text_split_inline(string $inner): array
{
    $parts = [];
    $current = '';
    $quote = '';
    $depth = 0;
    $length = strlen($inner);

    for ($index = 0; $index < $length; $index++) {
        $char = $inner[$index];

        if ($quote !== '') {
            $current .= $char;

            if ($char === '\\' && $quote === '"') {
                if ($index + 1 < $length) {
                    $current .= $inner[++$index];
                }
                continue;
            }

            if ($char === $quote) {
                $quote = '';
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $current .= $char;
            continue;
        }

        if ($char === '[' || $char === '{') {
            $depth++;
            $current .= $char;
            continue;
        }

        if ($char === ']' || $char === '}') {
            $depth--;
            $current .= $char;
            continue;
        }

        if ($char === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $parts[] = trim($current);
    }

    return $parts;
}

/**
 * Basit TOML alt kümesi: [tablo], anahtar = skaler/dizi.
 *
 * @return array{ok: true, data: mixed}|array{ok: false, code: string, message: string}
 */
function fc_text_parse_toml(string $text): array
{
    $data = [];
    $current = &$data;
    $rawLines = preg_split('/\R/', $text) ?: [];

    foreach ($rawLines as $raw) {
        $line = trim(fc_yaml_strip_comment($raw));

        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '[[')) {
            return fc_text_error('invalid_toml', 'TOML tablo dizileri desteklenmiyor.');
        }

        if ($line[0] === '[' && str_ends_with($line, ']')) {
            $path = trim(substr($line, 1, -1));

            if ($path === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $path) !== 1) {
                return fc_text_error('invalid_toml', 'TOML tablo adı geçersiz.');
            }

            $current = &$data;

            foreach (explode('.', $path) as $segment) {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }

                $current = &$current[$segment];
            }

            continue;
        }

        $position = fc_text_find_unquoted($line, '=');

        if ($position === null) {
            return fc_text_error('invalid_toml', 'TOML anahtar/değer satırı geçersiz.');
        }

        $key = trim(substr($line, 0, $position));
        $value = trim(substr($line, $position + 1));

        if ($key === '' || $value === '') {
            return fc_text_error('invalid_toml', 'TOML anahtar veya değeri boş.');
        }

        if (($key[0] === '"' && str_ends_with($key, '"')) || ($key[0] === "'" && str_ends_with($key, "'"))) {
            $key = fc_yaml_unquote($key);
        } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            return fc_text_error('invalid_toml', 'TOML anahtarı geçersiz.');
        }

        if (array_key_exists($key, $current)) {
            return fc_text_error('invalid_toml', 'TOML anahtarı yineleniyor.');
        }

        try {
            $current[$key] = fc_toml_value($value);
        } catch (Throwable $exception) {
            error_log('file-converter toml parse: ' . $exception->getMessage());
            return fc_text_error('invalid_toml', 'TOML değeri desteklenmiyor veya geçersiz.');
        }
    }

    return ['ok' => true, 'data' => $data];
}

function fc_text_find_unquoted(string $text, string $needle): ?int
{
    $quote = '';
    $length = strlen($text);

    for ($index = 0; $index < $length; $index++) {
        $char = $text[$index];

        if ($quote !== '') {
            if ($char === '\\' && $quote === '"') {
                $index++;
                continue;
            }

            if ($char === $quote) {
                $quote = '';
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            continue;
        }

        if ($char === $needle) {
            return $index;
        }
    }

    return null;
}

function fc_toml_value(string $value): mixed
{
    $value = trim($value);

    if ($value === '') {
        throw new RuntimeException('Boş TOML değeri.');
    }

    if ($value[0] === '[') {
        if (!str_ends_with($value, ']')) {
            throw new RuntimeException('TOML dizisi kapatılmamış.');
        }

        $inner = trim(substr($value, 1, -1));

        if ($inner === '') {
            return [];
        }

        return array_map('fc_toml_value', fc_text_split_inline($inner));
    }

    if ($value[0] === '{') {
        throw new RuntimeException('TOML satır içi tablosu desteklenmiyor.');
    }

    if ($value[0] === '"' || $value[0] === "'") {
        if (fc_yaml_is_quoted_scalar($value)) {
            return fc_yaml_unquote($value);
        }

        throw new RuntimeException('TOML metni kapatılmamış.');
    }

    if (strcasecmp($value, 'true') === 0) {
        return true;
    }

    if (strcasecmp($value, 'false') === 0) {
        return false;
    }

    if (preg_match('/^[+-]?\d+$/', $value) === 1) {
        return (int) $value;
    }

    if (preg_match('/^[+-]?\d+\.\d+$/', $value) === 1) {
        return (float) $value;
    }

    throw new RuntimeException('TOML çıplak değeri desteklenmiyor.');
}

/**
 * XML'i güvenli biçimde (DTD/ENTITY olmadan) diziye çevirir.
 *
 * @return array{ok: true, data: mixed}|array{ok: false, code: string, message: string}
 */
function fc_text_parse_xml(string $text): array
{
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $text) === 1) {
        return fc_text_error('unsafe_xml', 'DTD/ENTITY içeren XML güvenlik nedeniyle reddedildi.');
    }

    if (!class_exists('DOMDocument')) {
        return fc_text_error('xml_unavailable', 'XML desteği bu sunucuda yok.');
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();

    try {
        $loaded = $document->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS);
    } catch (Throwable $exception) {
        $loaded = false;
    }

    if (!$loaded || $document->documentElement === null) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return fc_text_error('invalid_xml', 'XML geçersiz veya güvenli biçimde çözümlenemedi.');
    }

    $root = $document->documentElement;
    $data = [fc_xml_name($root) => fc_xml_element_value($root)];

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return ['ok' => true, 'data' => $data];
}

function fc_xml_name(DOMElement $element): string
{
    $name = $element->localName !== '' ? $element->localName : $element->nodeName;

    return $name !== '' ? $name : 'item';
}

/**
 * @return mixed
 */
function fc_xml_element_value(DOMElement $element): mixed
{
    $attributes = [];
    $children = [];
    $text = '';

    foreach ($element->attributes ?? [] as $attribute) {
        $attributes[$attribute->nodeName] = $attribute->nodeValue;
    }

    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $name = fc_xml_name($child);
            $value = fc_xml_element_value($child);

            if (array_key_exists($name, $children)) {
                if (!is_array($children[$name]) || !array_is_list($children[$name])) {
                    $children[$name] = [$children[$name]];
                }

                $children[$name][] = $value;
            } else {
                $children[$name] = $value;
            }
        } elseif ($child instanceof DOMText) {
            $text .= $child->nodeValue;
        }
    }

    $text = trim($text);
    $hasChildren = $children !== [];

    if (!$hasChildren && $attributes === []) {
        return $text;
    }

    $result = [];

    if ($attributes !== []) {
        $result['@attributes'] = $attributes;
    }

    if ($hasChildren) {
        $result = array_merge($result, $children);
    }

    if ($text !== '') {
        $result['@text'] = $text;
    }

    return $result;
}

/**
 * RFC 4180'e yakın CSV ayrıştırıcısı.
 *
 * @return array{ok: true, rows: list<list<string>>}|array{ok: false, code: string, message: string}
 */
function fc_text_parse_csv(string $text, int $maxRows = 20000, int $maxColumns = 256): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $length = strlen($text);
    $rows = [];
    $row = [];
    $field = '';
    $inQuotes = false;
    $index = 0;

    while ($index < $length) {
        $char = $text[$index];

        if ($inQuotes) {
            if ($char === '"') {
                if ($index + 1 < $length && $text[$index + 1] === '"') {
                    $field .= '"';
                    $index += 2;
                    continue;
                }

                $inQuotes = false;
                $index++;
                continue;
            }

            $field .= $char;
            $index++;
            continue;
        }

        if ($char === '"' && $field === '') {
            $inQuotes = true;
            $index++;
            continue;
        }

        if ($char === ',') {
            $row[] = $field;
            $field = '';

            if (count($row) > $maxColumns) {
                return fc_text_error('csv_too_wide', 'CSV satırı izin verilen sütun sayısını aşıyor.');
            }

            $index++;
            continue;
        }

        if ($char === "\n") {
            $row[] = $field;
            $rows[] = $row;
            $row = [];
            $field = '';

            if (count($rows) > $maxRows) {
                return fc_text_error('csv_too_many_rows', 'CSV izin verilen satır sayısını aşıyor.');
            }

            $index++;
            continue;
        }

        $field .= $char;
        $index++;
    }

    if ($inQuotes) {
        return fc_text_error('invalid_csv', 'CSV tırnaklı alanı kapatılmamış.');
    }

    if ($field !== '' || $row !== []) {
        $row[] = $field;
        $rows[] = $row;
    }

    if ($rows === []) {
        return fc_text_error('empty_csv', 'CSV dosyası boş.');
    }

    return ['ok' => true, 'rows' => $rows];
}

/**
 * CSV satırlarını başlık varsa nesne listesine, yoksa satır listesine çevirir.
 *
 * @param list<list<string>> $rows
 * @return list<array<string, string>>|list<list<string>>
 */
function fc_csv_rows_to_data(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $headers = array_map('trim', $rows[0]);
    $unique = [];
    $valid = $headers !== [];

    foreach ($headers as $header) {
        if ($header === '' || isset($unique[$header])) {
            $valid = false;
            break;
        }

        $unique[$header] = true;
    }

    if (!$valid) {
        return $rows;
    }

    $data = [];

    foreach (array_slice($rows, 1) as $row) {
        $item = [];

        foreach ($headers as $index => $header) {
            $item[$header] = $row[$index] ?? '';
        }

        $data[] = $item;
    }

    return $data;
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_json(mixed $data): array
{
    try {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        error_log('file-converter json emit: ' . $exception->getMessage());
        return fc_text_error('json_encode_failed', 'Veri JSON olarak üretilemedi.');
    }

    return ['ok' => true, 'text' => $json . "\n"];
}

function fc_yaml_emit_scalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value)) {
        return (string) $value;
    }

    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }

    $text = (string) $value;

    if ($text === '') {
        return '""';
    }

    if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/-]*$/', $text) === 1
        && !in_array(strtolower($text), ['true', 'false', 'null', '~'], true)
        && preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1
    ) {
        return $text;
    }

    return (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fc_yaml_emit(mixed $data, int $indent = 0): string
{
    $prefix = str_repeat(' ', $indent);

    if (!is_array($data)) {
        return $prefix . fc_yaml_emit_scalar($data) . "\n";
    }

    if ($data === []) {
        return $prefix . "[]\n";
    }

    $lines = [];
    $isList = array_is_list($data);

    foreach ($data as $key => $value) {
        if ($isList) {
            if (is_array($value) && $value !== []) {
                $lines[] = $prefix . "-\n" . fc_yaml_emit($value, $indent + 2);
            } else {
                $lines[] = $prefix . '- ' . fc_yaml_emit_scalar($value) . "\n";
            }

            continue;
        }

        $keyText = fc_yaml_emit_scalar((string) $key);

        if (is_array($value) && $value !== []) {
            $lines[] = $prefix . $keyText . ":\n" . fc_yaml_emit($value, $indent + 2);
        } elseif (is_array($value)) {
            $lines[] = $prefix . $keyText . ": []\n";
        } else {
            $lines[] = $prefix . $keyText . ': ' . fc_yaml_emit_scalar($value) . "\n";
        }
    }

    return implode('', $lines);
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_yaml(mixed $data): array
{
    try {
        $yaml = fc_yaml_emit($data);
    } catch (Throwable $exception) {
        error_log('file-converter yaml emit: ' . $exception->getMessage());
        return fc_text_error('yaml_encode_failed', 'Veri YAML olarak üretilemedi.');
    }

    return ['ok' => true, 'text' => $yaml];
}

function fc_toml_emit_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value)) {
        return (string) $value;
    }

    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }

    if (is_string($value)) {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (is_array($value) && array_is_list($value)) {
        $items = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                throw new RuntimeException('TOML iç içe dizi desteklenmiyor.');
            }

            $items[] = fc_toml_emit_value($item);
        }

        return '[' . implode(', ', $items) . ']';
    }

    throw new RuntimeException('TOML değeri desteklenmiyor.');
}

function fc_toml_emit_table(array $data, string $path = ''): string
{
    $scalars = [];
    $tables = [];

    foreach ($data as $key => $value) {
        if (is_array($value) && !array_is_list($value)) {
            $tables[$key] = $value;
            continue;
        }

        $scalars[$key] = $value;
    }

    $output = '';

    if ($path !== '' && ($scalars !== [] || $tables === [])) {
        $output .= '[' . $path . "]\n";
    }

    foreach ($scalars as $key => $value) {
        $safeKey = preg_match('/^[A-Za-z0-9_-]+$/', (string) $key) === 1
            ? (string) $key
            : (string) json_encode((string) $key, JSON_UNESCAPED_UNICODE);

        $output .= $safeKey . ' = ' . fc_toml_emit_value($value) . "\n";
    }

    foreach ($tables as $key => $value) {
        $childPath = $path === '' ? (string) $key : $path . '.' . $key;
        $output .= ($output !== '' ? "\n" : '') . fc_toml_emit_table($value, $childPath);
    }

    return $output;
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_toml(mixed $data): array
{
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        return fc_text_error('toml_shape', 'TOML yalnızca nesne/eşleme verisinden üretilebilir.');
    }

    try {
        $toml = fc_toml_emit_table($data);
    } catch (Throwable $exception) {
        error_log('file-converter toml emit: ' . $exception->getMessage());
        return fc_text_error('toml_encode_failed', 'Veri TOML olarak üretilemedi.');
    }

    return ['ok' => true, 'text' => rtrim($toml) . "\n"];
}

function fc_xml_tag(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? 'item';

    if ($name === '' || preg_match('/^[A-Za-z_]/', $name) !== 1) {
        $name = 'item_' . $name;
    }

    return $name;
}

function fc_xml_emit_value(mixed $value, string $tag, int $depth = 0): string
{
    $safeTag = fc_xml_tag($tag);
    $indent = str_repeat('  ', $depth);

    if (is_array($value)) {
        if ($value === []) {
            return $indent . '<' . $safeTag . "/>\n";
        }

        $attributes = '';
        $content = '';
        $text = '';

        if (isset($value['@attributes']) && is_array($value['@attributes'])) {
            foreach ($value['@attributes'] as $name => $attributeValue) {
                if (is_scalar($attributeValue)) {
                    $attributes .= ' ' . fc_xml_tag((string) $name) . '="'
                        . htmlspecialchars((string) $attributeValue, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
                }
            }
        }

        if (isset($value['@text']) && is_scalar($value['@text'])) {
            $text = htmlspecialchars((string) $value['@text'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if ($key === '@attributes' || $key === '@text') {
                continue;
            }

            if ($isList) {
                $content .= fc_xml_emit_value($item, 'item', $depth + 1);
            } else {
                $content .= fc_xml_emit_value($item, (string) $key, $depth + 1);
            }
        }

        if ($content === '' && $text === '') {
            return $indent . '<' . $safeTag . $attributes . "/>\n";
        }

        return $indent . '<' . $safeTag . $attributes . '>'
            . ($text !== '' ? $text : '')
            . ($content !== '' ? "\n" . $content . $indent : '')
            . '</' . $safeTag . ">\n";
    }

    if ($value === null) {
        return $indent . '<' . $safeTag . "/>\n";
    }

    $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    return $indent . '<' . $safeTag . '>' . $text . '</' . $safeTag . ">\n";
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_xml(mixed $data): array
{
    $root = 'root';

    if (is_array($data) && !array_is_list($data) && count($data) === 1) {
        $key = array_key_first($data);

        if (is_string($key) && $key !== '' && !str_starts_with($key, '@')) {
            $root = $key;
            $data = $data[$key];
        }
    }

    try {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . fc_xml_emit_value($data, $root);
    } catch (Throwable $exception) {
        error_log('file-converter xml emit: ' . $exception->getMessage());
        return fc_text_error('xml_encode_failed', 'Veri XML olarak üretilemedi.');
    }

    return ['ok' => true, 'text' => $xml];
}

/**
 * Veriyi tablo satırlarına çevirir (CSV/HTML için).
 *
 * @return array{ok: true, headers: list<string>, rows: list<list<string>>}|array{ok: false, code: string, message: string}
 */
function fc_text_data_to_rows(mixed $data): array
{
    if (!is_array($data) || $data === []) {
        return fc_text_error('tabular_required', 'Bu dönüşüm için boş olmayan bir dizi/nesne gerekir.');
    }

    if (!array_is_list($data)) {
        $headers = [];
        $row = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                return fc_text_error('tabular_required', 'İç içe veri CSV/HTML tablosuna dönüştürülemez.');
            }

            $headers[] = (string) $key;
            $row[] = (string) $value;
        }

        return ['ok' => true, 'headers' => $headers, 'rows' => [$row]];
    }

    $headers = [];
    $rows = [];
    $assoc = true;

    foreach ($data as $item) {
        if (!is_array($item) || array_is_list($item)) {
            $assoc = false;
            break;
        }
    }

    if ($assoc) {
        foreach ($data as $item) {
            foreach (array_keys($item) as $key) {
                if (!in_array((string) $key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }

        foreach ($data as $item) {
            $row = [];

            foreach ($headers as $header) {
                $value = $item[$header] ?? '';

                if (is_array($value)) {
                    return fc_text_error('tabular_required', 'İç içe veri CSV/HTML tablosuna dönüştürülemez.');
                }

                $row[] = (string) $value;
            }

            $rows[] = $row;
        }

        return ['ok' => true, 'headers' => $headers, 'rows' => $rows];
    }

    foreach ($data as $item) {
        if (!is_array($item) || !array_is_list($item)) {
            return fc_text_error('tabular_required', 'CSV/HTML için dizi listesi gerekir.');
        }

        $row = [];

        foreach ($item as $value) {
            if (is_array($value)) {
                return fc_text_error('tabular_required', 'İç içe veri CSV/HTML tablosuna dönüştürülemez.');
            }

            $row[] = (string) $value;
        }

        $rows[] = $row;
    }

    return ['ok' => true, 'headers' => [], 'rows' => $rows];
}

function fc_csv_escape(string $value): string
{
    if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    return $value;
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_csv(mixed $data): array
{
    $rows = fc_text_data_to_rows($data);

    if (!($rows['ok'] ?? false)) {
        return $rows;
    }

    $lines = [];

    if ($rows['headers'] !== []) {
        $lines[] = implode(',', array_map('fc_csv_escape', $rows['headers']));
    }

    foreach ($rows['rows'] as $row) {
        $lines[] = implode(',', array_map('fc_csv_escape', $row));
    }

    return ['ok' => true, 'text' => implode("\n", $lines) . "\n"];
}

/**
 * @param mixed $data
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_emit_html_table(mixed $data): array
{
    $rows = fc_text_data_to_rows($data);

    if (!($rows['ok'] ?? false)) {
        return $rows;
    }

    $html = "<!doctype html>\n<html lang=\"tr\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>Tablo</title>\n</head>\n<body>\n<table>\n";

    if ($rows['headers'] !== []) {
        $html .= "<thead><tr>";

        foreach ($rows['headers'] as $header) {
            $html .= '<th>' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
        }

        $html .= "</tr></thead>\n";
    }

    $html .= "<tbody>\n";

    foreach ($rows['rows'] as $row) {
        $html .= '<tr>';

        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }

        $html .= "</tr>\n";
    }

    $html .= "</tbody>\n</table>\n</body>\n</html>\n";

    return ['ok' => true, 'text' => $html];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_structured_parse(string $format, string $text, array $fc): array
{
    if ($format === 'json') {
        return fc_text_parse_json($text);
    }

    if ($format === 'yaml') {
        try {
            return fc_text_parse_yaml($text);
        } catch (Throwable $exception) {
            error_log('file-converter yaml parse: ' . $exception->getMessage());
            return fc_text_error('invalid_yaml', 'YAML yapısı desteklenmiyor veya geçersiz.');
        }
    }

    if ($format === 'toml') {
        return fc_text_parse_toml($text);
    }

    if ($format === 'xml') {
        return fc_text_parse_xml($text);
    }

    if ($format === 'csv') {
        $parsed = fc_text_parse_csv($text);

        if (!($parsed['ok'] ?? false)) {
            return $parsed;
        }

        return ['ok' => true, 'data' => fc_csv_rows_to_data($parsed['rows'])];
    }

    return fc_text_error('unsupported_type', 'Bu veri biçimi desteklenmiyor.');
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_structured_emit(string $format, mixed $data, array $fc): array
{
    return match ($format) {
        'json' => fc_text_emit_json($data),
        'yaml' => fc_text_emit_yaml($data),
        'toml' => fc_text_emit_toml($data),
        'xml' => fc_text_emit_xml($data),
        'csv' => fc_text_emit_csv($data),
        'html' => fc_text_emit_html_table($data),
        default => fc_text_error('unsupported_target', 'Bu hedef veri biçimi desteklenmiyor.'),
    };
}

/**
 * Markdown'u güvenli HTML'e çevirir. Ham HTML her zaman kaçırılır.
 */
function fc_markdown_to_html(string $markdown): string
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $codeBlocks = [];
    $markdown = preg_replace_callback(
        '/```([A-Za-z0-9_-]*)\n(.*?)```/s',
        static function (array $match) use (&$codeBlocks): string {
            $placeholder = "\x00CODE" . count($codeBlocks) . "\x00";
            $codeBlocks[$placeholder] = '<pre><code'
                . ($match[1] !== '' ? ' class="language-' . htmlspecialchars($match[1], ENT_QUOTES, 'UTF-8') . '"' : '')
                . '>' . htmlspecialchars($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
            return $placeholder;
        },
        $markdown
    ) ?? $markdown;

    $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = explode("\n", $escaped);
    $html = [];
    $paragraph = [];
    $listType = '';
    $quote = [];
    $title = 'Belge';

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph === []) {
            return;
        }

        $html[] = '<p>' . implode('<br>', $paragraph) . '</p>';
        $paragraph = [];
    };

    $closeList = static function () use (&$listType, &$html): void {
        if ($listType === '') {
            return;
        }

        $html[] = '</' . $listType . '>';
        $listType = '';
    };

    $flushQuote = static function () use (&$quote, &$html): void {
        if ($quote === []) {
            return;
        }

        $html[] = '<blockquote><p>' . implode('<br>', $quote) . '</p></blockquote>';
        $quote = [];
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();
            $closeList();
            $flushQuote();
            $level = strlen($match[1]);
            $text = fc_markdown_inline($match[2]);

            if ($title === 'Belge') {
                $title = trim(strip_tags($text));
            }

            $html[] = '<h' . $level . '>' . $text . '</h' . $level . '>';
            continue;
        }

        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            $flushQuote();
            continue;
        }

        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
            $flushParagraph();
            $closeList();
            $flushQuote();
            $html[] = '<hr>';
            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $trimmed, $match) === 1) {
            $flushParagraph();
            $closeList();
            $quote[] = fc_markdown_inline($match[1]);
            continue;
        }

        $flushQuote();

        if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();

            if ($listType !== 'ul') {
                $closeList();
                $html[] = '<ul>';
                $listType = 'ul';
            }

            $html[] = '<li>' . fc_markdown_inline($match[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $match) === 1) {
            $flushParagraph();

            if ($listType !== 'ol') {
                $closeList();
                $html[] = '<ol>';
                $listType = 'ol';
            }

            $html[] = '<li>' . fc_markdown_inline($match[1]) . '</li>';
            continue;
        }

        $closeList();
        $paragraph[] = fc_markdown_inline($trimmed);
    }

    $flushParagraph();
    $closeList();
    $flushQuote();

    $body = implode("\n", $html);

    foreach ($codeBlocks as $placeholder => $block) {
        $body = str_replace($placeholder, $block, $body);
    }

    $safeTitle = htmlspecialchars($title !== '' ? $title : 'Belge', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return "<!doctype html>\n<html lang=\"tr\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . $safeTitle . "</title>\n</head>\n<body>\n" . $body . "\n</body>\n</html>\n";
}

function fc_markdown_inline(string $text): string
{
    $text = preg_replace_callback('/`([^`]+)`/', static function (array $match): string {
        return '<code>' . $match[1] . '</code>';
    }, $text) ?? $text;

    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
        static function (array $match): string {
            $label = $match[1];
            $url = fc_safe_text_url(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));

            if ($url === null) {
                return $label;
            }

            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $external = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

            return '<a href="' . $safeUrl . '"'
                . ($external ? ' rel="noopener noreferrer"' : '')
                . '>' . $label . '</a>';
        },
        $text
    ) ?? $text;

    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;

    return $text;
}

function fc_safe_text_url(string $url): ?string
{
    $url = trim($url);

    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return null;
    }

    if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
        return null;
    }

    return $url;
}

function fc_html_to_text(string $html): string
{
    if (!class_exists('DOMDocument')) {
        $text = strip_tags($html);
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();

    try {
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
    } catch (Throwable $exception) {
        $loaded = false;
    }

    if (!$loaded) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    foreach (['script', 'style', 'noscript', 'template', 'svg'] as $tag) {
        $nodes = $document->getElementsByTagName($tag);

        for ($index = $nodes->length - 1; $index >= 0; $index--) {
            $node = $nodes->item($index);

            if ($node !== null && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    $text = $document->documentElement !== null
        ? fc_dom_text($document->documentElement)
        : '';

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

function fc_dom_text(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return $node->nodeValue;
    }

    $name = strtolower($node->nodeName);

    if ($name === 'br') {
        return "\n";
    }

    $text = '';

    foreach ($node->childNodes as $child) {
        $text .= fc_dom_text($child);
    }

    if (in_array($name, ['p', 'div', 'section', 'article', 'header', 'footer', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'], true)) {
        $text = "\n" . $text . "\n";
    } elseif (in_array($name, ['td', 'th'], true)) {
        $text .= "\t";
    }

    return $text;
}

/**
 * RTF hedef gruplarını (font tablosu, renk tablosu, resim, nesne, üst bilgi)
 * ve yıldızlı isteğe bağlı grupları dengeli parantez taramasıyla kaldırır.
 */
function fc_rtf_strip_destinations(string $rtf): string
{
    $destinations = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object',
        'header', 'footer', 'headerl', 'headerr', 'footerl', 'footerr',
        'themedata', 'colorschememapping', 'datastore', 'latentstyles',
        'listtable', 'listoverridetable', 'rsidtbl', 'generator',
    ];
    $length = strlen($rtf);
    $output = '';
    $index = 0;

    while ($index < $length) {
        if ($rtf[$index] !== '{') {
            $output .= $rtf[$index];
            $index++;
            continue;
        }

        $cursor = $index + 1;
        $isOptional = false;

        if ($cursor < $length && $rtf[$cursor] === '\\' && ($cursor + 1) < $length && $rtf[$cursor + 1] === '*') {
            $isOptional = true;
            $cursor += 2;
        } elseif ($cursor < $length && $rtf[$cursor] === '*') {
            $isOptional = true;
            $cursor++;
        }

        $isDestination = $isOptional;

        if (!$isDestination && $cursor < $length && $rtf[$cursor] === '\\') {
            $start = $cursor + 1;
            $scan = $start;

            while ($scan < $length && ctype_alpha($rtf[$scan])) {
                $scan++;
            }

            $word = strtolower(substr($rtf, $start, $scan - $start));

            if ($word !== '' && in_array($word, $destinations, true)) {
                $isDestination = true;
            }
        }

        if (!$isDestination) {
            $output .= '{';
            $index++;
            continue;
        }

        $depth = 0;
        $scan = $index;

        while ($scan < $length) {
            $char = $rtf[$scan];

            if ($char === '\\') {
                $scan += 2;
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $scan++;
                    break;
                }
            }

            $scan++;
        }

        $index = $scan;
    }

    return $output;
}

/**
 * RTF'den düz metin çıkarır. Makro/nesne/resim grupları yürütülmez,
 * yalnızca metin okunur.
 */
function fc_rtf_to_text(string $rtf): string
{
    $rtf = fc_rtf_strip_destinations($rtf);


    $length = strlen($rtf);
    $output = '';
    $index = 0;

    while ($index < $length) {
        $char = $rtf[$index];

        if ($char === '{' || $char === '}') {
            $index++;
            continue;
        }

        if ($char !== '\\') {
            $output .= $char;
            $index++;
            continue;
        }

        $index++;

        if ($index >= $length) {
            break;
        }

        $next = $rtf[$index];

        if ($next === '\\' || $next === '{' || $next === '}') {
            $output .= $next;
            $index++;
            continue;
        }

        if ($next === "'") {
            $hex = substr($rtf, $index + 1, 2);

            if (preg_match('/^[0-9A-Fa-f]{2}$/', $hex) === 1) {
                $output .= chr((int) hexdec($hex));
                $index += 3;
                continue;
            }

            $index++;
            continue;
        }

        if ($next === '~') {
            $output .= ' ';
            $index++;
            continue;
        }

        if ($next === '-') {
            $index++;
            continue;
        }

        if (!ctype_alpha($next)) {
            $index++;
            continue;
        }

        $start = $index;

        while ($index < $length && ctype_alpha($rtf[$index])) {
            $index++;
        }

        $word = substr($rtf, $start, $index - $start);
        $parameter = '';

        if ($index < $length && $rtf[$index] === '-') {
            $parameter .= '-';
            $index++;
        }

        while ($index < $length && ctype_digit($rtf[$index])) {
            $parameter .= $rtf[$index];
            $index++;
        }

        if ($index < $length && $rtf[$index] === ' ') {
            $index++;
        }

        if ($word === 'par' || $word === 'line') {
            $output .= "\n";
            continue;
        }

        if ($word === 'tab') {
            $output .= "\t";
            continue;
        }

        if ($word === 'u' && $parameter !== '') {
            $code = (int) $parameter;

            if ($code < 0) {
                $code += 65536;
            }

            $output .= mb_chr($code, 'UTF-8');
            continue;
        }
    }

    if (!mb_check_encoding($output, 'UTF-8')) {
        $converted = @mb_convert_encoding($output, 'UTF-8', 'Windows-1254');

        if (is_string($converted)) {
            $output = $converted;
        }
    }

    $output = str_replace(["\r\n", "\r"], "\n", $output);
    $output = preg_replace('/[ \t]+/', ' ', $output) ?? $output;
    $output = preg_replace('/\n{3,}/', "\n\n", $output) ?? $output;

    return trim($output);
}

function fc_text_to_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $paragraphs = preg_split('/\n{2,}/', trim($text)) ?: [];
    $body = [];

    foreach ($paragraphs as $paragraph) {
        if ($paragraph === '') {
            continue;
        }

        $body[] = '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
    }

    return "<!doctype html>\n<html lang=\"tr\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>Metin</title>\n</head>\n<body>\n" . implode("\n", $body) . "\n</body>\n</html>\n";
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_text_convert(string $text, string $sourceFormat, string $targetFormat, array $fc): array
{
    $structured = ['json', 'yaml', 'toml', 'xml', 'csv'];

    if (in_array($sourceFormat, $structured, true)) {
        $parsed = fc_text_structured_parse($sourceFormat, $text, $fc);

        if (!($parsed['ok'] ?? false)) {
            return $parsed;
        }

        return fc_text_structured_emit($targetFormat, $parsed['data'], $fc);
    }

    if ($sourceFormat === 'md') {
        if ($targetFormat === 'html') {
            return ['ok' => true, 'text' => fc_markdown_to_html($text)];
        }

        if ($targetFormat === 'txt') {
            return ['ok' => true, 'text' => fc_html_to_text(fc_markdown_to_html($text))];
        }
    }

    if ($sourceFormat === 'html' && $targetFormat === 'txt') {
        return ['ok' => true, 'text' => fc_html_to_text($text)];
    }

    if ($sourceFormat === 'rtf' && $targetFormat === 'txt') {
        return ['ok' => true, 'text' => fc_rtf_to_text($text)];
    }

    if ($sourceFormat === 'txt' && $targetFormat === 'html') {
        return ['ok' => true, 'text' => fc_text_to_html($text)];
    }

    return fc_text_error('unsupported_target', 'Bu metin dönüşümü desteklenmiyor.');
}

/* ---- Bölüm: görsel dönüşümleri (image_converter) ---- */
/**
 * File Converter — GD tabanlı görsel doğrulama, ICO çözme/üretme ve
 * görselden PDF üretimi.
 *
 * Uzantıya güvenilmez: MIME, dosya imzası, gerçek çözme ve piksel sınırı
 * birlikte doğrulanır. Animasyonlu GIF/WebP/APNG/AVIF sessizce tek kareye
 * indirgenmez; açık hata ile reddedilir.
 */

/**
 * @return array<string, array{
 *     label: string,
 *     extension: string,
 *     extensions: list<string>,
 *     image_type: int,
 *     decode: string,
 *     encode: string,
 *     quality: int,
 *     gd_key: string
 * }>
 */
function fc_image_formats(): array
{
    $avifType = defined('IMAGETYPE_AVIF') ? (int) constant('IMAGETYPE_AVIF') : 19;

    return [
        'jpg' => [
            'label' => 'JPEG',
            'extension' => 'jpg',
            'extensions' => ['jpg', 'jpeg'],
            'image_type' => IMAGETYPE_JPEG,
            'decode' => 'imagecreatefromjpeg',
            'encode' => 'imagejpeg',
            'quality' => 88,
            'gd_key' => 'JPEG Support',
        ],
        'png' => [
            'label' => 'PNG',
            'extension' => 'png',
            'extensions' => ['png'],
            'image_type' => IMAGETYPE_PNG,
            'decode' => 'imagecreatefrompng',
            'encode' => 'imagepng',
            'quality' => 6,
            'gd_key' => 'PNG Support',
        ],
        'webp' => [
            'label' => 'WebP',
            'extension' => 'webp',
            'extensions' => ['webp'],
            'image_type' => IMAGETYPE_WEBP,
            'decode' => 'imagecreatefromwebp',
            'encode' => 'imagewebp',
            'quality' => 85,
            'gd_key' => 'WebP Support',
        ],
        'gif' => [
            'label' => 'GIF',
            'extension' => 'gif',
            'extensions' => ['gif'],
            'image_type' => IMAGETYPE_GIF,
            'decode' => 'imagecreatefromgif',
            'encode' => 'imagegif',
            'quality' => 0,
            'gd_key' => 'GIF Read Support',
        ],
        'bmp' => [
            'label' => 'BMP',
            'extension' => 'bmp',
            'extensions' => ['bmp'],
            'image_type' => IMAGETYPE_BMP,
            'decode' => 'imagecreatefrombmp',
            'encode' => 'imagebmp',
            'quality' => 0,
            'gd_key' => 'BMP Support',
        ],
        'avif' => [
            'label' => 'AVIF',
            'extension' => 'avif',
            'extensions' => ['avif'],
            'image_type' => $avifType,
            'decode' => 'imagecreatefromavif',
            'encode' => 'imageavif',
            'quality' => 60,
            'gd_key' => 'AVIF Support',
        ],
    ];
}

/**
 * Sunucunun GD ile gerçekten yapabildiği görsel biçimleri.
 *
 * @return array{
 *     available: bool,
 *     gd: bool,
 *     fileinfo: bool,
 *     source: list<string>,
 *     target: list<string>,
 *     missing: list<string>
 * }
 */
function fc_image_capabilities(): array
{
    $gdLoaded = extension_loaded('gd');
    $fileinfo = class_exists('finfo');
    $gdInfo = $gdLoaded && function_exists('gd_info') ? gd_info() : [];
    $source = [];
    $target = [];
    $missing = [];

    if (!$gdLoaded) {
        $missing[] = 'gd';
    }

    if (!$fileinfo) {
        $missing[] = 'fileinfo';
    }

    foreach (fc_image_formats() as $key => $format) {
        $supported = $gdLoaded
            && function_exists($format['decode'])
            && function_exists($format['encode'])
            && ($format['gd_key'] === '' || !empty($gdInfo[$format['gd_key']]));

        if ($supported) {
            $source[] = $key;
            $target[] = $key;
        }
    }

    // ICO üretimi GD PNG çıktısıyla, çözümü kendi ICO okuyucumuzla yapılır.
    if ($gdLoaded && function_exists('imagecreatefromstring') && function_exists('imagepng')) {
        $source[] = 'ico';
        $target[] = 'ico';
    }

    return [
        'available' => $gdLoaded && $fileinfo && $source !== [],
        'gd' => $gdLoaded,
        'fileinfo' => $fileinfo,
        'source' => array_values(array_unique($source)),
        'target' => array_values(array_unique($target)),
        'missing' => $missing,
    ];
}

function fc_image_label(string $key): string
{
    $formats = fc_image_formats();

    if ($key === 'ico') {
        return 'ICO';
    }

    return $formats[$key]['label'] ?? 'Bilinmeyen';
}

function fc_image_extension(string $key): string
{
    if ($key === 'ico') {
        return 'ico';
    }

    return fc_image_formats()[$key]['extension'] ?? 'bin';
}

/**
 * @return array{ok: false, code: string, message: string}
 */
function fc_image_error(string $code, string $message): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
    ];
}

/**
 * Bilinen görsel imzalarını doğrular.
 */
function fc_image_signature_ok(string $key, string $bytes): bool
{
    if ($key === 'jpg') {
        return str_starts_with($bytes, "\xFF\xD8\xFF");
    }

    if ($key === 'png') {
        return str_starts_with($bytes, "\x89PNG\r\n\x1A\n");
    }

    if ($key === 'webp') {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    if ($key === 'gif') {
        return str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a');
    }

    if ($key === 'bmp') {
        return str_starts_with($bytes, 'BM');
    }

    if ($key === 'avif') {
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }

        $brands = substr($bytes, 8, 32);

        return str_contains($brands, 'avif') || str_contains($brands, 'avis');
    }

    if ($key === 'ico') {
        return strlen($bytes) >= 6
            && substr($bytes, 0, 4) === "\x00\x00\x01\x00";
    }

    return false;
}

/**
 * GIF bloklarını tarayarak birden fazla görüntü çerçevesi olup olmadığını
 * belirler. Animasyonlu GIF tek kareye indirgenmez.
 */
function fc_gif_is_animated(string $bytes): bool
{
    $length = strlen($bytes);

    if ($length < 13 || (!str_starts_with($bytes, 'GIF87a') && !str_starts_with($bytes, 'GIF89a'))) {
        return false;
    }

    $packed = ord($bytes[10]);
    $position = 13;

    if (($packed & 0x80) !== 0) {
        $position += 3 * (1 << (($packed & 0x07) + 1));
    }

    $images = 0;
    $guard = 0;

    while ($position < $length && $guard++ < 10000) {
        $block = ord($bytes[$position]);
        $position++;

        if ($block === 0x3B) {
            break;
        }

        if ($block === 0x21) {
            $position++; // extension label

            while ($position < $length) {
                $size = ord($bytes[$position]);
                $position++;

                if ($size === 0) {
                    break;
                }

                $position += $size;
            }

            continue;
        }

        if ($block === 0x2C) {
            $images++;

            if ($images > 1) {
                return true;
            }

            if ($position + 9 > $length) {
                return false;
            }

            $imagePacked = ord($bytes[$position + 8]);
            $position += 9;

            if (($imagePacked & 0x80) !== 0) {
                $position += 3 * (1 << (($imagePacked & 0x07) + 1));
            }

            $position++; // LZW minimum code size

            while ($position < $length) {
                $size = ord($bytes[$position]);
                $position++;

                if ($size === 0) {
                    break;
                }

                $position += $size;
            }

            continue;
        }

        break;
    }

    return false;
}

/**
 * RIFF parçalarını tarayarak animasyonlu WebP'yi tespit eder.
 */
function fc_webp_has_animation(string $bytes): bool
{
    $length = strlen($bytes);

    if ($length < 16 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        return false;
    }

    $position = 12;

    while ($position + 8 <= $length) {
        $type = substr($bytes, $position, 4);
        $size = unpack('V', substr($bytes, $position + 4, 4))[1] ?? 0;

        if ($type === 'ANIM' || $type === 'ANMF') {
            return true;
        }

        $position += 8 + $size + ($size % 2);
    }

    return false;
}

/**
 * PNG `acTL` parçası APNG'yi gösterir.
 */
function fc_png_has_animation(string $bytes): bool
{
    $length = strlen($bytes);

    if ($length < 16 || !str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
        return false;
    }

    $position = 8;

    while ($position + 12 <= $length) {
        $size = unpack('N', substr($bytes, $position, 4))[1] ?? 0;
        $type = substr($bytes, $position + 4, 4);

        if ($type === 'acTL') {
            return true;
        }

        if ($type === 'IDAT' || $type === 'IEND') {
            break;
        }

        $position += 12 + $size;
    }

    return false;
}

/**
 * AVIF `avis` markası animasyon dizisini gösterir.
 */
function fc_avif_is_animated(string $bytes): bool
{
    return str_contains(substr($bytes, 8, 32), 'avis');
}

/**
 * JPEG EXIF orientation değerini okur; okunamazsa 1 döner.
 */
function fc_exif_orientation(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }

    $exif = @exif_read_data($path);

    if (!is_array($exif) || !isset($exif['Orientation'])) {
        return 1;
    }

    $orientation = (int) $exif['Orientation'];

    return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
}

/**
 * EXIF orientation değerini piksel verisine uygular.
 */
function fc_apply_orientation(GdImage $image, int $orientation): GdImage
{
    if ($orientation < 2 || $orientation > 8) {
        return $image;
    }

    if ($orientation === 2) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        return $image;
    }

    if ($orientation === 4) {
        imageflip($image, IMG_FLIP_VERTICAL);
        return $image;
    }

    $angle = match ($orientation) {
        3 => 180,
        5 => 270,
        6 => -90,
        7 => 90,
        8 => 90,
        default => 0,
    };

    if ($angle === 0) {
        return $image;
    }

    $rotated = @imagerotate($image, $angle, 0);

    if (!$rotated instanceof GdImage) {
        return $image;
    }

    if ($orientation === 5 || $orientation === 7) {
        imageflip($rotated, IMG_FLIP_HORIZONTAL);
    }

    imagedestroy($image);

    return $rotated;
}

/**
 * Güvenli bir şekilde görseli serbest bırakır.
 */
function fc_destroy_image(mixed $image): void
{
    if ($image instanceof GdImage) {
        imagedestroy($image);
    }
}

/**
 * ICO dizinini ayrıştırır ve en büyük girişi seçer.
 *
 * @return array{ok: true, width: int, height: int, bit_count: int, png: bool, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_ico_best_entry(string $bytes): array
{
    $length = strlen($bytes);

    if ($length < 6 || substr($bytes, 0, 4) !== "\x00\x00\x01\x00") {
        return fc_image_error('invalid_signature', 'ICO dosya imzası geçersiz.');
    }

    $count = unpack('v', substr($bytes, 4, 2))[1] ?? 0;

    if ($count < 1 || $count > 64) {
        return fc_image_error('malformed_image', 'ICO giriş sayısı geçersiz.');
    }

    $best = null;

    for ($index = 0; $index < $count; $index++) {
        $offset = 6 + ($index * 16);

        if ($offset + 16 > $length) {
            break;
        }

        $entry = unpack(
            'Cwidth/Cheight/Ccolors/Creserved/vplanes/vbitcount/Vsize/Voffset',
            substr($bytes, $offset, 16)
        );

        if (!is_array($entry)) {
            continue;
        }

        $width = ((int) $entry['width']) === 0 ? 256 : (int) $entry['width'];
        $height = ((int) $entry['height']) === 0 ? 256 : (int) $entry['height'];
        $size = (int) $entry['size'];
        $dataOffset = (int) $entry['offset'];

        if ($size < 1 || $dataOffset < 0 || $dataOffset + $size > $length) {
            continue;
        }

        $data = substr($bytes, $dataOffset, $size);
        $isPng = str_starts_with($data, "\x89PNG\r\n\x1A\n");
        $bitCount = (int) $entry['bitcount'];
        $score = $width * $height * 10 + ($isPng ? 5 : 0) + min(32, $bitCount);
        $candidate = [
            'width' => $width,
            'height' => $height,
            'bit_count' => $bitCount,
            'png' => $isPng,
            'bytes' => $data,
            'score' => $score,
        ];

        if ($best === null || $candidate['score'] > $best['score']) {
            $best = $candidate;
        }
    }

    if ($best === null) {
        return fc_image_error('malformed_image', 'ICO içinde okunabilir görüntü bulunamadı.');
    }

    unset($best['score']);

    return ['ok' => true] + $best;
}

/**
 * ICO/SVG dışındaki GD görsellerini çözer.
 *
 * @param array<string, mixed> $fc
 * @return GdImage|array{ok: false, code: string, message: string}
 */
function fc_image_decode(string $path, string $key, array $fc): GdImage|array
{
    if ($key === 'ico') {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return fc_image_error('malformed_image', 'ICO dosyası okunamadı.');
        }

        $entry = fc_ico_best_entry($bytes);

        if (!($entry['ok'] ?? false)) {
            return $entry;
        }

        $image = fc_ico_decode_entry($entry, $fc);

        if (!($image['ok'] ?? false)) {
            return $image;
        }

        return $image['image'];
    }

    $formats = fc_image_formats();

    if (!isset($formats[$key]) || !function_exists($formats[$key]['decode'])) {
        return fc_image_error('unsupported_type', 'Bu görsel biçimi bu sunucuda çözülemiyor.');
    }

    if (in_array($key, ['gif', 'webp', 'png', 'avif'], true)) {
        $bytes = @file_get_contents($path);

        if (is_string($bytes) && $bytes !== '') {
            $animated = match ($key) {
                'gif' => fc_gif_is_animated($bytes),
                'webp' => fc_webp_has_animation($bytes),
                'png' => fc_png_has_animation($bytes),
                'avif' => fc_avif_is_animated($bytes),
                default => false,
            };

            if ($animated) {
                return fc_image_error('animated_image', 'Animasyonlu görseller reddedilir; tek kareye indirgenmez.');
            }
        }
    }

    $image = @$formats[$key]['decode']($path);

    if (!$image instanceof GdImage) {
        return fc_image_error('malformed_image', 'Görsel çözülemedi; dosya bozuk olabilir.');
    }

    $maxPixels = (int) ($fc['max_image_pixels'] ?? 24_000_000);
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width < 1 || $height < 1 || $width * $height > $maxPixels) {
        imagedestroy($image);
        return fc_image_error('too_many_pixels', 'Görsel çözünürlüğü izin verilen sınırı aşıyor.');
    }

    if ($key === 'jpg') {
        $orientation = fc_exif_orientation($path);
        $image = fc_apply_orientation($image, $orientation);
    }

    return $image;
}

/**
 * ICO DIB veya PNG girişini GD görseline çevirir.
 *
 * @param array{width: int, height: int, bit_count: int, png: bool, bytes: string} $entry
 * @param array<string, mixed> $fc
 * @return array{ok: true, image: GdImage}|array{ok: false, code: string, message: string}
 */
function fc_ico_decode_entry(array $entry, array $fc): array
{
    $bytes = $entry['bytes'];
    $maxPixels = (int) ($fc['max_image_pixels'] ?? 24_000_000);

    if ($entry['png']) {
        $image = @imagecreatefromstring($bytes);

        if (!$image instanceof GdImage) {
            return fc_image_error('malformed_image', 'ICO içindeki PNG çözülemedi.');
        }

        if (imagesx($image) * imagesy($image) > $maxPixels) {
            imagedestroy($image);
            return fc_image_error('too_many_pixels', 'ICO görseli çözünürlük sınırını aşıyor.');
        }

        return ['ok' => true, 'image' => $image];
    }

    if (strlen($bytes) < 40) {
        return fc_image_error('malformed_image', 'ICO bitmap başlığı eksik.');
    }

    $header = unpack(
        'Vsize/Vwidth/lheight/vplanes/vbitcount/Vcompression/VsizeImage/VxPels/VyPels/VclrUsed/VclrImportant',
        substr($bytes, 0, 40)
    );

    if (!is_array($header)) {
        return fc_image_error('malformed_image', 'ICO bitmap başlığı okunamadı.');
    }

    $width = (int) $header['width'];
    $rawHeight = (int) $header['height'];
    $bitCount = (int) $header['bitcount'];
    $compression = (int) $header['compression'];
    $colorsUsed = (int) $header['clrUsed'];

    if ($width < 1 || $width > 256 || $rawHeight === 0 || abs($rawHeight) > 512) {
        return fc_image_error('malformed_image', 'ICO bitmap boyutları geçersiz.');
    }

    if ($width * max(1, intdiv(abs($rawHeight), 2)) > $maxPixels) {
        return fc_image_error('too_many_pixels', 'ICO görseli çözünürlük sınırını aşıyor.');
    }

    if ($compression !== 0 || !in_array($bitCount, [1, 4, 8, 24, 32], true)) {
        return fc_image_error('unsupported_type', 'Bu ICO bitmap biçimi (sıkıştırma/bit derinliği) desteklenmiyor.');
    }

    $topDown = $rawHeight < 0;
    $height = intdiv(abs($rawHeight), 2);

    if ($height < 1) {
        $height = abs($rawHeight);
    }

    $paletteCount = 0;
    $palette = [];

    if ($bitCount <= 8) {
        $paletteCount = $colorsUsed > 0 ? $colorsUsed : (1 << $bitCount);
        $paletteCount = min($paletteCount, 256);
        $paletteOffset = 40;

        if ($paletteOffset + ($paletteCount * 4) > strlen($bytes)) {
            return fc_image_error('malformed_image', 'ICO renk paleti eksik.');
        }

        for ($index = 0; $index < $paletteCount; $index++) {
            $offset = $paletteOffset + ($index * 4);
            $palette[$index] = [
                'b' => ord($bytes[$offset]),
                'g' => ord($bytes[$offset + 1]),
                'r' => ord($bytes[$offset + 2]),
            ];
        }
    }

    $xorOffset = 40 + ($paletteCount * 4);
    $rowStride = intdiv(($bitCount * $width) + 31, 32) * 4;
    $xorSize = $rowStride * $height;
    $andOffset = $xorOffset + $xorSize;
    $andStride = intdiv($width + 31, 32) * 4;
    $andSize = $andStride * $height;

    if ($xorOffset + $xorSize > strlen($bytes)) {
        return fc_image_error('malformed_image', 'ICO piksel verisi eksik.');
    }

    $hasMask = $andOffset + $andSize <= strlen($bytes);
    $image = imagecreatetruecolor($width, $height);

    if (!$image instanceof GdImage) {
        return fc_image_error('conversion_failed', 'ICO için bellek ayrılamadı.');
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    $cache = [];

    for ($y = 0; $y < $height; $y++) {
        $sourceRow = $topDown ? $y : ($height - 1 - $y);
        $rowStart = $xorOffset + ($sourceRow * $rowStride);
        $maskStart = $andOffset + ($sourceRow * $andStride);

        for ($x = 0; $x < $width; $x++) {
            $r = 0;
            $g = 0;
            $b = 0;
            $a = 0;

            if ($bitCount === 32) {
                $offset = $rowStart + ($x * 4);

                if ($offset + 3 >= strlen($bytes)) {
                    break 2;
                }

                $b = ord($bytes[$offset]);
                $g = ord($bytes[$offset + 1]);
                $r = ord($bytes[$offset + 2]);
                $alphaByte = ord($bytes[$offset + 3]);
                $a = $alphaByte > 0 ? 127 - (int) round($alphaByte / 2) : 127;
            } elseif ($bitCount === 24) {
                $offset = $rowStart + ($x * 3);

                if ($offset + 2 >= strlen($bytes)) {
                    break 2;
                }

                $b = ord($bytes[$offset]);
                $g = ord($bytes[$offset + 1]);
                $r = ord($bytes[$offset + 2]);
            } else {
                $bitOffset = $x * $bitCount;
                $byteOffset = $rowStart + intdiv($bitOffset, 8);

                if ($byteOffset >= strlen($bytes)) {
                    break 2;
                }

                $byte = ord($bytes[$byteOffset]);
                $shift = 8 - $bitCount - ($bitOffset % 8);
                $paletteIndex = ($byte >> $shift) & ((1 << $bitCount) - 1);

                if (!isset($palette[$paletteIndex])) {
                    continue;
                }

                $r = $palette[$paletteIndex]['r'];
                $g = $palette[$paletteIndex]['g'];
                $b = $palette[$paletteIndex]['b'];
            }

            if ($bitCount !== 32 && $hasMask) {
                $maskByte = ord($bytes[$maskStart + intdiv($x, 8)]);

                if (($maskByte & (0x80 >> ($x % 8))) !== 0) {
                    $a = 127;
                }
            }

            $colorKey = ($a << 24) | ($r << 16) | ($g << 8) | $b;

            if (!isset($cache[$colorKey])) {
                $cache[$colorKey] = imagecolorallocatealpha($image, $r, $g, $b, $a);
            }

            imagesetpixel($image, $x, $y, $cache[$colorKey]);
        }
    }

    return ['ok' => true, 'image' => $image];
}

/**
 * GD görselini ICO dosyasına yazar. Tek girişli, PNG sıkıştırmalı ICO üretir.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, width: int, height: int, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_ico_encode(GdImage $image, string $destination, array $fc): array
{
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width < 1 || $height < 1) {
        return fc_image_error('malformed_image', 'Görsel boyutları geçersiz.');
    }

    $scale = min(1.0, 256 / max($width, $height));
    $targetWidth = max(1, min(256, (int) round($width * $scale)));
    $targetHeight = max(1, min(256, (int) round($height * $scale)));

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

    if (!$canvas instanceof GdImage) {
        return fc_image_error('conversion_failed', 'ICO için bellek ayrılamadı.');
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagealphablending($canvas, true);
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $temporary = @tempnam(dirname($destination), 'ico');

    if ($temporary === false) {
        imagedestroy($canvas);
        return fc_image_error('storage_unavailable', 'ICO çıktısı için geçici alan açılamadı.');
    }

    $encoded = @imagepng($canvas, $temporary);
    imagedestroy($canvas);

    if (!$encoded) {
        @unlink($temporary);
        return fc_image_error('conversion_failed', 'ICO PNG verisi üretilemedi.');
    }

    $png = @file_get_contents($temporary);
    @unlink($temporary);

    if ($png === false || $png === '') {
        return fc_image_error('conversion_failed', 'ICO PNG verisi okunamadı.');
    }

    $entryWidth = $targetWidth >= 256 ? 0 : $targetWidth;
    $entryHeight = $targetHeight >= 256 ? 0 : $targetHeight;
    $header = pack('vvv', 0, 1, 1);
    $entry = pack(
        'CCCCvvVV',
        $entryWidth,
        $entryHeight,
        0,
        0,
        1,
        32,
        strlen($png),
        22
    );
    $ico = $header . $entry . $png;

    if (@file_put_contents($destination, $ico) !== strlen($ico)) {
        return fc_image_error('storage_unavailable', 'ICO çıktısı yazılamadı.');
    }

    return [
        'ok' => true,
        'width' => $targetWidth,
        'height' => $targetHeight,
        'bytes' => strlen($ico),
    ];
}

/**
 * Görseli hedef biçime gerçekten yeniden kodlar.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, width: int, height: int, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_convert_image(GdImage $image, string $targetKey, string $destination, array $fc): array
{
    $capabilities = fc_image_capabilities();

    if (!in_array($targetKey, $capabilities['target'], true)) {
        return fc_image_error('unsupported_target', 'Seçilen hedef biçim bu sunucuda desteklenmiyor.');
    }

    if ($targetKey === 'ico') {
        return fc_ico_encode($image, $destination, $fc);
    }

    $formats = fc_image_formats();

    if (!isset($formats[$targetKey])) {
        return fc_image_error('unsupported_target', 'Seçilen hedef biçim bu sunucuda desteklenmiyor.');
    }

    $format = $formats[$targetKey];
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width < 1 || $height < 1) {
        return fc_image_error('malformed_image', 'Görsel boyutları geçersiz.');
    }

    $output = imagecreatetruecolor($width, $height);

    if (!$output instanceof GdImage) {
        return fc_image_error('conversion_failed', 'Dönüşüm için bellek ayrılamadı.');
    }

    if (in_array($targetKey, ['jpg', 'bmp'], true)) {
        $white = imagecolorallocate($output, 255, 255, 255);
        imagefilledrectangle($output, 0, 0, $width, $height, $white);
        imagecopy($output, $image, 0, 0, 0, 0, $width, $height);
    } else {
        imagealphablending($output, false);
        imagesavealpha($output, true);
        $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
        imagefilledrectangle($output, 0, 0, $width, $height, $transparent);
        imagealphablending($output, true);
        imagecopy($output, $image, 0, 0, 0, 0, $width, $height);
    }

    if ($targetKey === 'gif') {
        // GIF 256 renge ve sınırlı saydamlığa sahiptir; palete indirgenir.
        imagetruecolortopalette($output, true, 256);
    }

    $temporary = @tempnam(dirname($destination), 'conv');

    if ($temporary === false) {
        imagedestroy($output);
        return fc_image_error('storage_unavailable', 'Dönüştürülen dosya için geçici alan açılamadı.');
    }

    $encoded = false;

    if ($targetKey === 'jpg') {
        $encoded = @imagejpeg($output, $temporary, $format['quality']);
    } elseif ($targetKey === 'png') {
        $encoded = @imagepng($output, $temporary, $format['quality']);
    } elseif ($targetKey === 'webp') {
        $encoded = @imagewebp($output, $temporary, $format['quality']);
    } elseif ($targetKey === 'gif') {
        $encoded = @imagegif($output, $temporary);
    } elseif ($targetKey === 'bmp') {
        $encoded = @imagebmp($output, $temporary);
    } elseif ($targetKey === 'avif') {
        $encoded = @imageavif($output, $temporary, $format['quality']);
    }

    imagedestroy($output);

    if (!$encoded) {
        @unlink($temporary);
        return fc_image_error('conversion_failed', 'Görsel seçilen biçime dönüştürülemedi.');
    }

    $size = @filesize($temporary);

    if ($size === false || $size < 1) {
        @unlink($temporary);
        return fc_image_error('conversion_failed', 'Dönüşüm çıktısı boş.');
    }

    if (!@rename($temporary, $destination)) {
        @unlink($temporary);
        return fc_image_error('storage_unavailable', 'Dönüştürülen dosya geçici alana yazılamadı.');
    }

    return [
        'ok' => true,
        'width' => $width,
        'height' => $height,
        'bytes' => (int) $size,
    ];
}

/**
 * Görseli A4 sayfaya sığdıran, JPEG akışı gömen geçerli bir PDF üretir.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int, width: int, height: int}|array{ok: false, code: string, message: string}
 */
function fc_image_to_pdf(GdImage $image, string $destination, array $fc): array
{
    $width = imagesx($image);
    $height = imagesy($image);

    if ($width < 1 || $height < 1) {
        return fc_image_error('malformed_image', 'Görsel boyutları geçersiz.');
    }

    $flat = imagecreatetruecolor($width, $height);

    if (!$flat instanceof GdImage) {
        return fc_image_error('conversion_failed', 'PDF için bellek ayrılamadı.');
    }

    $white = imagecolorallocate($flat, 255, 255, 255);
    imagefilledrectangle($flat, 0, 0, $width, $height, $white);
    imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

    $temporary = @tempnam(dirname($destination), 'pdfimg');

    if ($temporary === false) {
        imagedestroy($flat);
        return fc_image_error('storage_unavailable', 'PDF için geçici alan açılamadı.');
    }

    $encoded = @imagejpeg($flat, $temporary, 90);
    imagedestroy($flat);

    if (!$encoded) {
        @unlink($temporary);
        return fc_image_error('conversion_failed', 'PDF görsel akışı üretilemedi.');
    }

    $jpeg = @file_get_contents($temporary);
    @unlink($temporary);

    if ($jpeg === false || $jpeg === '') {
        return fc_image_error('conversion_failed', 'PDF görsel akışı okunamadı.');
    }

    $pageWidth = $width > $height ? 841.89 : 595.28;
    $pageHeight = $width > $height ? 595.28 : 841.89;
    $margin = 36.0;
    $availableWidth = $pageWidth - ($margin * 2);
    $availableHeight = $pageHeight - ($margin * 2);
    $scale = min($availableWidth / $width, $availableHeight / $height);
    $drawWidth = $width * $scale;
    $drawHeight = $height * $scale;
    $drawX = ($pageWidth - $drawWidth) / 2;
    $drawY = ($pageHeight - $drawHeight) / 2;

    $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

    $content = "q\n"
        . $number($drawWidth) . " 0 0 " . $number($drawHeight) . " "
        . $number($drawX) . " " . $number($drawY) . " cm\n"
        . "/Im0 Do\nQ\n";

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
            . $number($pageWidth) . ' ' . $number($pageHeight) . ']'
            . ' /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
        4 => '<< /Type /XObject /Subtype /Image /Width ' . $width
            . ' /Height ' . $height
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode'
            . ' /Length ' . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream",
        5 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
    ];

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    foreach ($objects as $objectNumber => $body) {
        $offsets[$objectNumber] = strlen($pdf);
        $pdf .= $objectNumber . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $size = count($objects) + 1;
    $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";

    for ($index = 1; $index < $size; $index++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$index]) . "\n";
    }

    $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n"
        . $xrefOffset . "\n%%EOF\n";

    if (@file_put_contents($destination, $pdf) !== strlen($pdf)) {
        return fc_image_error('storage_unavailable', 'PDF çıktısı yazılamadı.');
    }

    return [
        'ok' => true,
        'bytes' => strlen($pdf),
        'width' => $width,
        'height' => $height,
    ];
}

/* ---- Bölüm: arşiv dönüşümleri (archive_converter) ---- */
/**
 * File Converter — arşiv oluşturma, dönüştürme ve güvenli tek dosya çıkarma.
 *
 * ZIP/TAR PharData ile, GZ zlib ile yapılır. Arşivler asla dosya adlarına
 * göre diske açılmaz; yalnızca sınırlı boyutta belleğe okunur. Zip bomb,
 * aşırı dosya sayısı, sembolik bağlantı ve yol aşımı reddedilir.
 */

/**
 * @return array{ok: false, code: string, message: string}
 */
function fc_archive_error(string $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'message' => $message];
}

function fc_archive_signature_ok(string $key, string $bytes): bool
{
    return match ($key) {
        'zip' => str_starts_with($bytes, "PK\x03\x04")
            || str_starts_with($bytes, "PK\x05\x06")
            || str_starts_with($bytes, "PK\x07\x08"),
        'tar' => strlen($bytes) > 262 && substr($bytes, 257, 5) === 'ustar',
        'gz' => str_starts_with($bytes, "\x1F\x8B"),
        'bz2' => str_starts_with($bytes, 'BZh'),
        'xz' => str_starts_with($bytes, "\xFD7zXZ\x00"),
        '7z' => str_starts_with($bytes, "7z\xBC\xAF\x27\x1C"),
        'rar' => str_starts_with($bytes, "Rar!\x1A\x07"),
        default => false,
    };
}

/**
 * GZ akışını bellek sınırıyla açar.
 *
 * @return array{ok: true, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_gz_decode_limited(string $bytes, int $maxBytes): array
{
    if (!function_exists('inflate_init')) {
        return fc_archive_error('engine_missing', 'GZ açma desteği (zlib) bu sunucuda yok.');
    }

    $context = @inflate_init(ZLIB_ENCODING_GZIP);

    if ($context === false) {
        return fc_archive_error('invalid_archive', 'GZ akışı başlatılamadı.');
    }

    $output = '';
    $length = strlen($bytes);

    for ($offset = 0; $offset < $length; $offset += 8192) {
        $chunk = substr($bytes, $offset, 8192);
        $decoded = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);

        if ($decoded === false) {
            return fc_archive_error('invalid_archive', 'GZ verisi bozuk veya desteklenmiyor.');
        }

        $output .= $decoded;

        if (strlen($output) > $maxBytes) {
            return fc_archive_error('archive_too_large', 'Arşiv açıldığında izin verilen boyutu aşıyor.');
        }
    }

    return ['ok' => true, 'bytes' => $output];
}

function fc_gz_encode(string $bytes): string
{
    if (!function_exists('gzencode')) {
        return '';
    }

    $encoded = @gzencode($bytes, 9);

    return is_string($encoded) ? $encoded : '';
}

/**
 * PharData içindeki bir girdinin arşive göreli adını üretir.
 */
function fc_phar_relative_name(PharFileInfo $file, string $path): string
{
    $pathname = str_replace('\\', '/', $file->getPathname());
    $prefix = 'phar://' . str_replace('\\', '/', (string) realpath($path)) . '/';

    if (str_starts_with($pathname, $prefix)) {
        return substr($pathname, strlen($prefix));
    }

    $position = strpos($pathname, '.zip/');

    if ($position !== false) {
        return substr($pathname, $position + 5);
    }

    $position = strpos($pathname, '.tar/');

    if ($position !== false) {
        return substr($pathname, $position + 5);
    }

    return basename($pathname);
}

/**
 * Arşiv adını güvenli, göreli bir ada çevirir. `../`, sürücü harfi,
 * mutlak yol ve denetim karakterleri etkisizleştirilir.
 */
function fc_safe_archive_name(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = preg_replace('#^[A-Za-z]:#', '', $name) ?? $name;
    $name = ltrim($name, '/');
    $parts = explode('/', $name);
    $safe = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($safe);
            continue;
        }

        $part = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '_', $part) ?? '_';
        $part = preg_replace('/\s+/u', ' ', $part) ?? $part;
        $part = trim($part, ". \t");

        if ($part === '') {
            continue;
        }

        $safe[] = $part;
    }

    $name = implode('/', $safe);

    if ($name === '') {
        $name = 'dosya';
    }

    if (strlen($name) > 180) {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $limit = 180 - (strlen($extension) > 0 ? strlen($extension) + 1 : 0);
        $name = substr($base, 0, max(1, $limit)) . ($extension !== '' ? '.' . $extension : '');
    }

    return $name;
}

/**
 * @param array<string, true> $used
 */
function fc_unique_archive_name(string $name, array &$used): string
{
    $name = fc_safe_archive_name($name);

    if (!isset($used[$name])) {
        $used[$name] = true;
        return $name;
    }

    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $suffix = 2;

    do {
        $candidate = $base . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
        $suffix++;
    } while (isset($used[$candidate]));

    $used[$candidate] = true;

    return $candidate;
}

/**
 * TAR başlıklarını ham baytlar üzerinden tarar; sembolik bağlantı, sabit
 * bağlantı veya aygıt girişi varsa adını döndürür.
 *
 * PharData TAR sembolik bağlantılarını `isLink()` ile bildirmediği için bu
 * tarama zorunludur; aksi halde bağlantı hedefi arşive kopyalanabilir.
 */
function fc_tar_unsafe_entry(string $path): ?string
{
    $size = @filesize($path);

    if ($size === false || $size < 512) {
        return null;
    }

    $handle = @fopen($path, 'rb');

    if (!is_resource($handle)) {
        return null;
    }

    $unsafeTypes = ['1', '2', '3', '4', '5', '6'];
    $position = 0;
    $guard = 0;

    while ($position + 512 <= $size && $guard++ < 10000) {
        if (fseek($handle, $position) !== 0) {
            break;
        }

        $header = fread($handle, 512);

        if (!is_string($header) || strlen($header) !== 512) {
            break;
        }

        if (trim($header, "\0") === '') {
            break;
        }

        $type = $header[156] ?? '0';
        $name = rtrim(substr($header, 0, 100), "\0 ");
        $prefix = rtrim(substr($header, 345, 155), "\0 ");
        $full = $prefix !== '' ? $prefix . '/' . $name : $name;

        if (in_array($type, $unsafeTypes, true)) {
            fclose($handle);

            return $full !== '' ? $full : '(adsız giriş)';
        }

        $rawSize = trim(substr($header, 124, 12), "\0 ");
        $entrySize = $rawSize !== '' && preg_match('/^[0-7]+$/', $rawSize) === 1
            ? (int) octdec($rawSize)
            : 0;

        $position += 512 + (int) (ceil($entrySize / 512) * 512);
    }

    fclose($handle);

    return null;
}

/**
 * PharData arşivini açar ve sınırları doğrular.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, entries: list<array{name: string, size: int, compressed: int, nested: bool, pathname: string}>, total: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_entries(string $path, array $fc): array
{
    $maxEntries = (int) ($fc['max_archive_entries'] ?? 200);
    $maxTotal = (int) ($fc['max_archive_uncompressed_bytes'] ?? 64 * 1024 * 1024);
    $maxEntry = (int) ($fc['max_archive_entry_bytes'] ?? 32 * 1024 * 1024);
    $maxRatio = (int) ($fc['max_archive_ratio'] ?? 200);
    $head = @file_get_contents($path, false, null, 0, 265);

    if (is_string($head) && strlen($head) > 262 && substr($head, 257, 5) === 'ustar') {
        $unsafe = fc_tar_unsafe_entry($path);

        if ($unsafe !== null) {
            return fc_archive_error(
                'unsafe_archive',
                'Sembolik bağlantı veya aygıt girişi içeren arşivler reddedilir: ' . fc_safe_archive_name($unsafe)
            );
        }
    }

    try {
        $phar = new PharData($path);
    } catch (Throwable $exception) {
        error_log('file-converter archive open: ' . get_class($exception));
        return fc_archive_error('invalid_archive', 'Arşiv açılamadı; dosya bozuk veya desteklenmiyor.');
    }

    $entries = [];
    $total = 0;
    $count = 0;

    try {
        $iterator = new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if (!$file instanceof PharFileInfo) {
                continue;
            }

            $count++;

            if ($count > $maxEntries + 50) {
                return fc_archive_error('archive_too_many_entries', 'Arşiv izin verilen dosya sayısını aşıyor.');
            }

            if ($file->isDir()) {
                continue;
            }

            if ($file->isLink()) {
                return fc_archive_error('unsafe_archive', 'Sembolik bağlantı içeren arşivler reddedilir.');
            }

            $name = fc_phar_relative_name($file, $path);
            $size = (int) $file->getSize();
            $compressed = (int) $file->getCompressedSize();

            if ($size < 0 || $size > $maxEntry) {
                return fc_archive_error('archive_entry_too_large', 'Arşivdeki bir dosya izin verilen boyutu aşıyor.');
            }

            if ($compressed > 0 && $size > 1024 * 1024 && ($size / $compressed) > $maxRatio) {
                return fc_archive_error('archive_bomb', 'Arşiv olağandışı sıkıştırma oranı gösteriyor; reddedildi.');
            }

            $total += $size;

            if ($total > $maxTotal) {
                return fc_archive_error('archive_too_large', 'Arşiv açıldığında izin verilen toplam boyutu aşıyor.');
            }

            if (count($entries) >= $maxEntries) {
                return fc_archive_error('archive_too_many_entries', 'Arşiv izin verilen dosya sayısını aşıyor.');
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $entries[] = [
                'name' => $name,
                'size' => $size,
                'compressed' => $compressed,
                'nested' => in_array($extension, ['zip', 'tar', 'gz', 'bz2', 'xz', '7z', 'rar'], true),
                'pathname' => $file->getPathname(),
            ];
        }
    } catch (Throwable $exception) {
        error_log('file-converter archive iterate: ' . get_class($exception));
        return fc_archive_error('invalid_archive', 'Arşiv içeriği okunamadı.');
    }

    unset($phar);

    return ['ok' => true, 'entries' => $entries, 'total' => $total];
}

/**
 * Arşivdeki tek bir dosyayı sınırlı boyutta okur.
 *
 * @return array{ok: true, name: string, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_archive_read_single(string $path, array $fc): array
{
    $listing = fc_archive_entries($path, $fc);

    if (!($listing['ok'] ?? false)) {
        return $listing;
    }

    if (count($listing['entries']) !== 1) {
        return fc_archive_error('extract_requires_single_file', 'Arşivden tek dosya çıkarmak için arşivde tam olarak bir dosya olmalı.');
    }

    return fc_archive_read_entry(
        $path,
        $listing['entries'][0]['name'],
        (int) $listing['entries'][0]['size'],
        $fc,
        (string) ($listing['entries'][0]['pathname'] ?? '')
    );
}

/**
 * @return array{ok: true, name: string, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_archive_read_entry(string $path, string $name, int $size, array $fc, string $pathname = ''): array
{
    $maxEntry = (int) ($fc['max_archive_entry_bytes'] ?? 32 * 1024 * 1024);

    if ($size < 0 || $size > $maxEntry) {
        return fc_archive_error('archive_entry_too_large', 'Arşivdeki dosya izin verilen boyutu aşıyor.');
    }

    $full = $pathname !== ''
        ? $pathname
        : 'phar://' . str_replace('\\', '/', (string) realpath($path)) . '/' . $name;
    $bytes = @file_get_contents($full);

    if ($bytes === false || strlen($bytes) !== $size) {
        return fc_archive_error('invalid_archive', 'Arşivdeki dosya okunamadı.');
    }

    return ['ok' => true, 'name' => $name, 'bytes' => $bytes];
}

/**
 * Arşivdeki tüm dosyaları (sınırlar içinde) belleğe okur.
 *
 * @return array{ok: true, files: list<array{name: string, bytes: string}>}|array{ok: false, code: string, message: string}
 */
function fc_archive_read_all(string $path, array $fc): array
{
    $listing = fc_archive_entries($path, $fc);

    if (!($listing['ok'] ?? false)) {
        return $listing;
    }

    $files = [];
    $used = [];

    foreach ($listing['entries'] as $entry) {
        $read = fc_archive_read_entry(
            $path,
            $entry['name'],
            (int) $entry['size'],
            $fc,
            (string) ($entry['pathname'] ?? '')
        );

        if (!($read['ok'] ?? false)) {
            return $read;
        }

        $files[] = [
            'name' => fc_unique_archive_name($entry['name'], $used),
            'bytes' => $read['bytes'],
        ];
    }

    return ['ok' => true, 'files' => $files];
}

/**
 * Dosya listesini ZIP/TAR/TAR.GZ/GZ olarak yazar.
 *
 * @param list<array{name: string, bytes: string}> $files
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_write(array $files, string $format, string $destination, string $workspace, array $fc): array
{
    if ($files === []) {
        return fc_archive_error('empty_archive', 'Arşiv oluşturmak için en az bir dosya gerekir.');
    }

    if ($format === 'gz') {
        if (count($files) !== 1) {
            return fc_archive_error('gz_requires_single_file', 'GZ yalnızca tek dosya sıkıştırabilir.');
        }

        $encoded = fc_gz_encode($files[0]['bytes']);

        if ($encoded === '') {
            return fc_archive_error('conversion_failed', 'GZ çıktısı üretilemedi.');
        }

        if (@file_put_contents($destination, $encoded) !== strlen($encoded)) {
            return fc_archive_error('storage_unavailable', 'GZ çıktısı yazılamadı.');
        }

        return ['ok' => true, 'bytes' => strlen($encoded)];
    }

    if (!in_array($format, ['zip', 'tar', 'tar.gz'], true)) {
        return fc_archive_error('unsupported_target', 'Bu arşiv biçimi yerleşik olarak desteklenmiyor.');
    }

    if (!class_exists('PharData')) {
        return fc_archive_error('engine_missing', 'ZIP/TAR desteği (PharData) bu sunucuda yok.');
    }

    $working = $workspace . '/archive-' . bin2hex(random_bytes(6))
        . ($format === 'zip' ? '.zip' : '.tar');

    if (file_exists($working)) {
        @unlink($working);
    }

    try {
        $phar = new PharData($working);
        $used = [];

        foreach ($files as $file) {
            $phar->addFromString(fc_unique_archive_name($file['name'], $used), $file['bytes']);
        }

        unset($phar);
    } catch (Throwable $exception) {
        error_log('file-converter archive write: ' . get_class($exception));
        @unlink($working);
        return fc_archive_error('conversion_failed', 'Arşiv oluşturulamadı.');
    }

    if (!is_file($working) || filesize($working) < 1) {
        @unlink($working);
        return fc_archive_error('conversion_failed', 'Arşiv çıktısı boş.');
    }

    if ($format === 'tar.gz') {
        $tar = @file_get_contents($working);
        @unlink($working);

        if ($tar === false || $tar === '') {
            return fc_archive_error('conversion_failed', 'TAR çıktısı okunamadı.');
        }

        $encoded = fc_gz_encode($tar);

        if ($encoded === '' || @file_put_contents($destination, $encoded) !== strlen($encoded)) {
            return fc_archive_error('conversion_failed', 'TAR.GZ çıktısı yazılamadı.');
        }

        return ['ok' => true, 'bytes' => strlen($encoded)];
    }

    if (!@rename($working, $destination)) {
        $copy = @copy($working, $destination);
        @unlink($working);

        if (!$copy) {
            return fc_archive_error('storage_unavailable', 'Arşiv çıktısı yazılamadı.');
        }
    }

    $size = @filesize($destination);

    if ($size === false || $size < 1) {
        return fc_archive_error('conversion_failed', 'Arşiv çıktısı boş.');
    }

    return ['ok' => true, 'bytes' => (int) $size];
}

/**
 * ZIP/TAR arşivini başka bir arşive dönüştürür.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_repack(string $sourcePath, string $targetFormat, string $destination, string $workspace, array $fc): array
{
    $read = fc_archive_read_all($sourcePath, $fc);

    if (!($read['ok'] ?? false)) {
        return $read;
    }

    return fc_archive_write($read['files'], $targetFormat, $destination, $workspace, $fc);
}

/**
 * GZ içeriğini (gerekirse TAR) açar.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: string, is_tar: bool}|array{ok: false, code: string, message: string}
 */
function fc_gz_inspect(string $bytes, array $fc): array
{
    $max = (int) ($fc['max_archive_uncompressed_bytes'] ?? 64 * 1024 * 1024);
    $decoded = fc_gz_decode_limited($bytes, $max);

    if (!($decoded['ok'] ?? false)) {
        return $decoded;
    }

    $isTar = strlen($decoded['bytes']) > 262 && substr($decoded['bytes'], 257, 5) === 'ustar';

    return ['ok' => true, 'bytes' => $decoded['bytes'], 'is_tar' => $isTar];
}

/**
 * Kaynak arşivi hedef arşive dönüştürür (zip/tar/gz/tar.gz).
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_convert(
    string $sourcePath,
    string $sourceKey,
    string $targetFormat,
    string $destination,
    string $workspace,
    array $fc
): array {
    if ($sourceKey === 'zip' || $sourceKey === 'tar') {
        return fc_archive_repack($sourcePath, $targetFormat, $destination, $workspace, $fc);
    }

    if ($sourceKey === 'gz') {
        $bytes = @file_get_contents($sourcePath);

        if ($bytes === false || $bytes === '') {
            return fc_archive_error('invalid_archive', 'GZ dosyası okunamadı.');
        }

        $inspect = fc_gz_inspect($bytes, $fc);

        if (!($inspect['ok'] ?? false)) {
            return $inspect;
        }

        if ($inspect['is_tar']) {
            $inner = $workspace . '/inner-' . bin2hex(random_bytes(6)) . '.tar';

            if (@file_put_contents($inner, $inspect['bytes']) !== strlen($inspect['bytes'])) {
                return fc_archive_error('storage_unavailable', 'Geçici TAR yazılamadı.');
            }

            return fc_archive_repack($inner, $targetFormat, $destination, $workspace, $fc);
        }

        $name = fc_safe_archive_name(pathinfo($sourcePath, PATHINFO_FILENAME));

        return fc_archive_write(
            [['name' => $name, 'bytes' => $inspect['bytes']]],
            $targetFormat,
            $destination,
            $workspace,
            $fc
        );
    }

    if (in_array($sourceKey, ['bz2', 'xz', '7z', 'rar'], true)) {
        return fc_archive_convert_with_engine($sourcePath, $sourceKey, $targetFormat, $destination, $workspace, $fc);
    }

    return fc_archive_error('unsupported_target', 'Bu arşiv kaynağı desteklenmiyor.');
}

/**
 * Arşivden tek dosya çıkarır.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, name: string, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_archive_extract(string $sourcePath, string $sourceKey, string $workspace, array $fc): array
{
    if ($sourceKey === 'zip' || $sourceKey === 'tar') {
        return fc_archive_read_single($sourcePath, $fc);
    }

    if ($sourceKey === 'gz') {
        $bytes = @file_get_contents($sourcePath);

        if ($bytes === false || $bytes === '') {
            return fc_archive_error('invalid_archive', 'GZ dosyası okunamadı.');
        }

        $inspect = fc_gz_inspect($bytes, $fc);

        if (!($inspect['ok'] ?? false)) {
            return $inspect;
        }

        if ($inspect['is_tar']) {
            $inner = $workspace . '/inner-' . bin2hex(random_bytes(6)) . '.tar';

            if (@file_put_contents($inner, $inspect['bytes']) !== strlen($inspect['bytes'])) {
                return fc_archive_error('storage_unavailable', 'Geçici TAR yazılamadı.');
            }

            return fc_archive_read_single($inner, $fc);
        }

        $name = pathinfo($sourcePath, PATHINFO_FILENAME);

        return ['ok' => true, 'name' => $name, 'bytes' => $inspect['bytes']];
    }

    if (in_array($sourceKey, ['bz2', 'xz', '7z', 'rar'], true)) {
        return fc_archive_extract_with_engine($sourcePath, $sourceKey, $workspace, $fc);
    }

    return fc_archive_error('unsupported_target', 'Bu arşiv kaynağı desteklenmiyor.');
}

/* ---- Bölüm: harici motor bağdaştırıcıları (engine_adapters) ---- */
/**
 * File Converter — isteğe bağlı harici motor adaptörleri.
 *
 * Bu ortamda FFmpeg, LibreOffice, Poppler, 7-Zip ve Imagick kurulu
 * olmayabilir. Adaptörler yalnızca motor gerçekten doğrulandığında
 * kullanılır; eksiklik diğer dönüşümleri etkilemez.
 */

/**
 * @return array{ok: false, code: string, message: string}
 */
function fc_engine_error(string $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'message' => $message];
}

/**
 * FFmpeg hedef profilleri. Kodlayıcı adları `ffmpeg -encoders` ile
 * doğrulanır; eksik kodlayıcı hedefi arayüzde gösterilmez.
 *
 * @return array<string, array{
 *     kind: string,
 *     extension: string,
 *     mime: string,
 *     video: string,
 *     audio: string,
 *     args: list<string>
 * }>
 */
function fc_ffmpeg_profiles(): array
{
    return [
        'mp3' => ['kind' => 'audio', 'extension' => 'mp3', 'mime' => 'audio/mpeg', 'video' => '', 'audio' => 'libmp3lame', 'args' => ['-b:a', '192k']],
        'wav' => ['kind' => 'audio', 'extension' => 'wav', 'mime' => 'audio/wav', 'video' => '', 'audio' => 'pcm_s16le', 'args' => []],
        'ogg' => ['kind' => 'audio', 'extension' => 'ogg', 'mime' => 'audio/ogg', 'video' => '', 'audio' => 'libvorbis', 'args' => ['-q:a', '5']],
        'opus' => ['kind' => 'audio', 'extension' => 'opus', 'mime' => 'audio/ogg', 'video' => '', 'audio' => 'libopus', 'args' => ['-b:a', '128k']],
        'aac' => ['kind' => 'audio', 'extension' => 'aac', 'mime' => 'audio/aac', 'video' => '', 'audio' => 'aac', 'args' => ['-b:a', '192k']],
        'm4a' => ['kind' => 'audio', 'extension' => 'm4a', 'mime' => 'audio/mp4', 'video' => '', 'audio' => 'aac', 'args' => ['-b:a', '192k']],
        'flac' => ['kind' => 'audio', 'extension' => 'flac', 'mime' => 'audio/flac', 'video' => '', 'audio' => 'flac', 'args' => []],
        'mp4' => ['kind' => 'video', 'extension' => 'mp4', 'mime' => 'video/mp4', 'video' => 'libx264', 'audio' => 'aac', 'args' => ['-pix_fmt', 'yuv420p', '-movflags', '+faststart']],
        'm4v' => ['kind' => 'video', 'extension' => 'm4v', 'mime' => 'video/x-m4v', 'video' => 'libx264', 'audio' => 'aac', 'args' => ['-pix_fmt', 'yuv420p', '-movflags', '+faststart']],
        'webm' => ['kind' => 'video', 'extension' => 'webm', 'mime' => 'video/webm', 'video' => 'libvpx-vp9', 'audio' => 'libopus', 'args' => ['-b:v', '0', '-crf', '32']],
        'mkv' => ['kind' => 'video', 'extension' => 'mkv', 'mime' => 'video/x-matroska', 'video' => 'libx264', 'audio' => 'aac', 'args' => ['-pix_fmt', 'yuv420p']],
        'mov' => ['kind' => 'video', 'extension' => 'mov', 'mime' => 'video/quicktime', 'video' => 'libx264', 'audio' => 'aac', 'args' => ['-pix_fmt', 'yuv420p']],
        'avi' => ['kind' => 'video', 'extension' => 'avi', 'mime' => 'video/x-msvideo', 'video' => 'mpeg4', 'audio' => 'libmp3lame', 'args' => []],
        'mpeg' => ['kind' => 'video', 'extension' => 'mpeg', 'mime' => 'video/mpeg', 'video' => 'mpeg2video', 'audio' => 'mp2', 'args' => []],
    ];
}

/**
 * FFmpeg profili bu kurulumda kullanılabilir mi?
 *
 * @param array<string, true> $encoders
 */
function fc_ffmpeg_profile_available(string $targetKey, array $encoders): bool
{
    $profiles = fc_ffmpeg_profiles();

    if (!isset($profiles[$targetKey])) {
        return false;
    }

    $profile = $profiles[$targetKey];

    if ($profile['kind'] === 'audio') {
        return isset($encoders[$profile['audio']]);
    }

    if (!isset($encoders[$profile['video']])) {
        return false;
    }

    return $profile['audio'] === '' || isset($encoders[$profile['audio']]);
}

/**
 * FFprobe ile akış türlerini döndürür.
 *
 * @return array{ok: true, audio: bool, video: bool}|array{ok: false, code: string, message: string}
 */
function fc_ffmpeg_streams(string $path): array
{
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'ffprobe')) {
        return fc_engine_error('engine_missing', 'FFprobe bulunamadı.');
    }

    $result = fc_run_process(
        [
            (string) $status['optional']['ffprobe']['path'],
            '-v', 'error',
            '-show_entries', 'stream=codec_type',
            '-of', 'csv=p=0',
            $path,
        ],
        dirname($path),
        10,
        16384,
        16384
    );

    if (!$result['ok']) {
        return fc_engine_error('invalid_media', 'Medya akışları okunamadı.');
    }

    $audio = false;
    $video = false;

    foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
        $line = trim($line);

        if ($line === 'audio') {
            $audio = true;
        } elseif ($line === 'video') {
            $video = true;
        }
    }

    return ['ok' => true, 'audio' => $audio, 'video' => $video];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_convert_with_ffmpeg(
    string $sourcePath,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'ffmpeg') || !fc_engine_is_available($status, 'ffprobe')) {
        return fc_engine_error('engine_missing', 'FFmpeg/FFprobe bulunmadığı için ses/video dönüşümü yapılamıyor.');
    }

    $profiles = fc_ffmpeg_profiles();

    if (!isset($profiles[$targetKey])) {
        return fc_engine_error('unsupported_target', 'Bu ses/video hedefi desteklenmiyor.');
    }

    if (!fc_ffmpeg_profile_available($targetKey, fc_ffmpeg_encoders())) {
        return fc_engine_error('engine_missing', 'Gerekli FFmpeg kodlayıcısı bu kurulumda yok.');
    }

    $streams = fc_ffmpeg_streams($sourcePath);

    if (!($streams['ok'] ?? false)) {
        return $streams;
    }

    $profile = $profiles[$targetKey];

    if ($profile['kind'] === 'audio' && !$streams['audio']) {
        return fc_engine_error('no_audio_stream', 'Kaynak dosyada ses akışı yok.');
    }

    if ($profile['kind'] === 'video' && !$streams['video']) {
        return fc_engine_error('no_video_stream', 'Kaynak dosyada video akışı yok.');
    }

    $command = [
        (string) $status['optional']['ffmpeg']['path'],
        '-hide_banner',
        '-nostdin',
        '-y',
        '-loglevel', 'error',
        '-i', $sourcePath,
    ];

    if ($profile['kind'] === 'audio') {
        $command[] = '-map';
        $command[] = '0:a:0';
        $command[] = '-vn';
        $command[] = '-c:a';
        $command[] = $profile['audio'];
    } else {
        $command[] = '-map';
        $command[] = '0:v:0';
        $command[] = '-map';
        $command[] = '0:a?';
        $command[] = '-c:v';
        $command[] = $profile['video'];

        if ($profile['audio'] !== '') {
            $command[] = '-c:a';
            $command[] = $profile['audio'];
        } else {
            $command[] = '-an';
        }
    }

    foreach ($profile['args'] as $argument) {
        $command[] = $argument;
    }

    $command[] = $destination;

    $timeout = max(20, (int) ($fc['process_timeout_seconds'] ?? 30));
    $result = fc_run_process($command, $workspace, $timeout, 16384, 65536);

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'Dönüşüm zaman aşımına uğradı.');
    }

    if (!$result['ok']) {
        return fc_engine_error('conversion_failed', 'FFmpeg dönüşümü başarısız oldu.');
    }

    $size = @filesize($destination);

    if ($size === false || $size < 1) {
        return fc_engine_error('conversion_failed', 'FFmpeg çıktısı boş.');
    }

    return ['ok' => true, 'bytes' => (int) $size];
}

/**
 * LibreOffice hedef filtreleri.
 *
 * @return array<string, string>
 */
function fc_libreoffice_filters(): array
{
    return [
        'pdf' => 'pdf',
        'txt' => 'txt:Text',
        'csv' => 'csv:Text - txt - csv (StarCalc)',
        'html' => 'html:HTML (StarWriter)',
        'rtf' => 'rtf',
        'docx' => 'docx:Office Open XML Text',
        'odt' => 'odt',
        'pptx' => 'pptx:Impress MS PowerPoint 2007 XML',
        'odp' => 'odp',
        'xlsx' => 'xlsx:Calc MS Excel 2007 XML',
        'ods' => 'ods',
    ];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_convert_with_libreoffice(
    string $sourcePath,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'libreoffice')) {
        return fc_engine_error('engine_missing', 'LibreOffice bulunmadığı için belge dönüşümü yapılamıyor.');
    }

    $filters = fc_libreoffice_filters();

    if (!isset($filters[$targetKey])) {
        return fc_engine_error('unsupported_target', 'Bu belge hedefi LibreOffice ile desteklenmiyor.');
    }

    $outputDirectory = $workspace . '/lo-out';
    $profileDirectory = $workspace . '/lo-profile';

    foreach ([$outputDirectory, $profileDirectory] as $directory) {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return fc_engine_error('storage_unavailable', 'LibreOffice çalışma dizini oluşturulamadı.');
        }
    }

    $profileUri = 'file:///' . ltrim(str_replace('\\', '/', $profileDirectory), '/');
    $command = [
        (string) $status['optional']['libreoffice']['path'],
        '--headless',
        '--norestore',
        '--invisible',
        '--nodefault',
        '--nologo',
        '--nolockcheck',
        '-env:UserInstallation=' . $profileUri,
        '--convert-to',
        $filters[$targetKey],
        '--outdir',
        $outputDirectory,
        $sourcePath,
    ];

    $timeout = max(60, (int) ($fc['office_timeout_seconds'] ?? 90));
    $result = fc_run_process($command, $workspace, $timeout, 32768, 65536);

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'Belge dönüşümü zaman aşımına uğradı.');
    }

    if (!$result['ok']) {
        return fc_engine_error('conversion_failed', 'LibreOffice dönüşümü başarısız oldu.');
    }

    $expected = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    $output = null;

    foreach (scandir($outputDirectory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = $outputDirectory . '/' . $name;

        if (!is_file($full)) {
            continue;
        }

        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $expected) {
            $output = $full;
            break;
        }

        if ($output === null) {
            $output = $full;
        }
    }

    if ($output === null) {
        return fc_engine_error('conversion_failed', 'LibreOffice çıktı dosyası üretmedi.');
    }

    $size = @filesize($output);

    if ($size === false || $size < 1) {
        return fc_engine_error('conversion_failed', 'LibreOffice çıktısı boş.');
    }

    if (!@rename($output, $destination)) {
        if (!@copy($output, $destination)) {
            return fc_engine_error('storage_unavailable', 'LibreOffice çıktısı taşınamadı.');
        }
    }

    return ['ok' => true, 'bytes' => (int) $size];
}

/**
 * PDF'in ilk sayfasını PNG'ye çevirir.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, path: string}|array{ok: false, code: string, message: string}
 */
function fc_pdf_render_first_page(string $sourcePath, string $workspace, array $fc): array
{
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'pdftoppm')) {
        return fc_engine_error('engine_missing', 'Poppler pdftoppm bulunmadığı için PDF görsele çevrilemiyor.');
    }

    $prefix = $workspace . '/pdfpage-' . bin2hex(random_bytes(6));
    $dpi = max(72, min(300, (int) ($fc['pdf_dpi'] ?? 150)));
    $command = [
        (string) $status['optional']['pdftoppm']['path'],
        '-f', '1',
        '-l', '1',
        '-singlefile',
        '-png',
        '-r', (string) $dpi,
        $sourcePath,
        $prefix,
    ];

    $result = fc_run_process($command, $workspace, max(20, (int) ($fc['process_timeout_seconds'] ?? 30)), 16384, 32768);

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'PDF görüntüleme zaman aşımına uğradı.');
    }

    $path = $prefix . '.png';

    if (!$result['ok'] || !is_file($path) || filesize($path) < 1) {
        return fc_engine_error('conversion_failed', 'PDF sayfası görüntüye çevrilemedi.');
    }

    return ['ok' => true, 'path' => $path];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_convert_pdf_to_image(
    string $sourcePath,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    $rendered = fc_pdf_render_first_page($sourcePath, $workspace, $fc);

    if (!($rendered['ok'] ?? false)) {
        return $rendered;
    }

    if ($targetKey === 'png') {
        if (!@rename($rendered['path'], $destination)) {
            if (!@copy($rendered['path'], $destination)) {
                return fc_engine_error('storage_unavailable', 'PDF sayfası kaydedilemedi.');
            }
        }

        $size = @filesize($destination);

        return ['ok' => true, 'bytes' => $size !== false ? (int) $size : 0];
    }

    $image = @imagecreatefrompng($rendered['path']);

    if (!$image instanceof GdImage) {
        return fc_engine_error('conversion_failed', 'PDF sayfası çözülemedi.');
    }

    $converted = fc_convert_image($image, $targetKey, $destination, $fc);
    imagedestroy($image);

    if (!($converted['ok'] ?? false)) {
        return $converted;
    }

    return ['ok' => true, 'bytes' => (int) $converted['bytes']];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, text: string}|array{ok: false, code: string, message: string}
 */
function fc_convert_pdf_to_text(string $sourcePath, string $workspace, array $fc): array
{
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'pdftotext')) {
        return fc_engine_error('engine_missing', 'Poppler pdftotext bulunmadığı için PDF metne çevrilemiyor.');
    }

    $output = $workspace . '/pdftext-' . bin2hex(random_bytes(6)) . '.txt';
    $result = fc_run_process(
        [
            (string) $status['optional']['pdftotext']['path'],
            '-layout',
            $sourcePath,
            $output,
        ],
        $workspace,
        max(20, (int) ($fc['process_timeout_seconds'] ?? 30)),
        16384,
        32768
    );

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'PDF metin çıkarma zaman aşımına uğradı.');
    }

    if (!$result['ok'] || !is_file($output)) {
        return fc_engine_error('conversion_failed', 'PDF metni çıkarılamadı.');
    }

    $bytes = @file_get_contents($output);

    if ($bytes === false || trim($bytes) === '') {
        return fc_engine_error('empty_output', 'PDF içinde çıkarılabilir metin bulunamadı.');
    }

    if (strlen($bytes) > (int) ($fc['max_text_bytes'] ?? 8 * 1024 * 1024)) {
        return fc_engine_error('file_too_large', 'Çıkarılan metin izin verilen boyutu aşıyor.');
    }

    return ['ok' => true, 'text' => $bytes];
}

/**
 * Imagick ile TIFF/çoklu görüntü kaynağını tek GD görseline çevirir.
 * Çok sayfalı/animasyonlu kaynak sessizce tek kareye indirgenmez.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, image: GdImage}|array{ok: false, code: string, message: string}
 */
function fc_imagick_to_gd(string $sourcePath, array $fc): array
{
    if (!class_exists('Imagick')) {
        return fc_engine_error('engine_missing', 'Imagick bulunmadığı için bu görsel biçimi çözülemiyor.');
    }

    try {
        $image = new Imagick();
        $image->readImage($sourcePath);

        if ($image->getNumberImages() > 1) {
            $image->clear();
            return fc_engine_error('animated_unsupported', 'Çok sayfalı/animasyonlu görsel tek kareye indirgenemez.');
        }

        $image->autoOrientImage();
        $image->setImageBackgroundColor('white');
        $merged = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $merged->setImageFormat('png');
        $blob = $merged->getImageBlob();
        $merged->clear();
        $image->clear();
    } catch (Throwable $exception) {
        error_log('file-converter imagick: ' . get_class($exception));
        return fc_engine_error('malformed_image', 'Görsel Imagick ile çözülemedi.');
    }

    $gd = @imagecreatefromstring($blob);

    if (!$gd instanceof GdImage) {
        return fc_engine_error('malformed_image', 'Görsel GD görseline çevrilemedi.');
    }

    $maxPixels = (int) ($fc['max_image_pixels'] ?? 24_000_000);

    if (imagesx($gd) * imagesy($gd) > $maxPixels) {
        imagedestroy($gd);
        return fc_engine_error('too_many_pixels', 'Görsel çözünürlük sınırını aşıyor.');
    }

    return ['ok' => true, 'image' => $gd];
}

/**
 * SVG'yi rsvg-convert ile PNG'ye, ardından GD görseline çevirir.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, image: GdImage}|array{ok: false, code: string, message: string}
 */
function fc_rsvg_to_gd(string $sourcePath, string $workspace, array $fc): array
{
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'rsvg')) {
        return fc_engine_error('engine_missing', 'rsvg-convert bulunmadığı için SVG rasterleştirilemiyor.');
    }

    $output = $workspace . '/svg-' . bin2hex(random_bytes(6)) . '.png';
    $result = fc_run_process(
        [
            (string) $status['optional']['rsvg']['path'],
            '-f', 'png',
            '-o', $output,
            $sourcePath,
        ],
        $workspace,
        max(20, (int) ($fc['process_timeout_seconds'] ?? 30)),
        16384,
        32768
    );

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'SVG rasterleştirme zaman aşımına uğradı.');
    }

    if (!$result['ok'] || !is_file($output) || filesize($output) < 1) {
        return fc_engine_error('conversion_failed', 'SVG güvenli biçimde rasterleştirilemedi.');
    }

    $image = @imagecreatefrompng($output);

    if (!$image instanceof GdImage) {
        return fc_engine_error('conversion_failed', 'SVG raster çıktısı çözülemedi.');
    }

    $maxPixels = (int) ($fc['max_image_pixels'] ?? 24_000_000);

    if (imagesx($image) * imagesy($image) > $maxPixels) {
        imagedestroy($image);
        return fc_engine_error('too_many_pixels', 'SVG çözünürlük sınırını aşıyor.');
    }

    return ['ok' => true, 'image' => $image];
}

/**
 * 7-Zip ile arşiv içeriğini listeler.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, entries: list<array{name: string, size: int, compressed: int}>}|array{ok: false, code: string, message: string}
 */
function fc_7z_list(string $archivePath, array $fc): array
{
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'sevenzip')) {
        return fc_engine_error('engine_missing', '7-Zip bulunmadığı için bu arşiv açılamıyor.');
    }

    $result = fc_run_process(
        [(string) $status['optional']['sevenzip']['path'], 'l', '-slt', '-ba', '--', $archivePath],
        dirname($archivePath),
        max(20, (int) ($fc['process_timeout_seconds'] ?? 30)),
        524288,
        32768
    );

    if (!$result['ok']) {
        return fc_engine_error('invalid_archive', 'Arşiv listelenemedi.');
    }

    $maxEntries = (int) ($fc['max_archive_entries'] ?? 200);
    $maxTotal = (int) ($fc['max_archive_uncompressed_bytes'] ?? 64 * 1024 * 1024);
    $maxEntry = (int) ($fc['max_archive_entry_bytes'] ?? 32 * 1024 * 1024);
    $maxRatio = (int) ($fc['max_archive_ratio'] ?? 200);
    $blocks = preg_split('/\R\R+/', trim($result['stdout'])) ?: [];
    $entries = [];
    $total = 0;

    foreach ($blocks as $block) {
        $fields = [];

        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $position = strpos($line, ' = ');

            if ($position === false) {
                continue;
            }

            $fields[trim(substr($line, 0, $position))] = trim(substr($line, $position + 3));
        }

        if (!isset($fields['Path']) || $fields['Path'] === '' || $fields['Path'] === $archivePath) {
            continue;
        }

        $name = str_replace('\\', '/', $fields['Path']);

        if ($name === '' || str_ends_with($name, '/') || (($fields['Attributes'] ?? '') !== '' && str_contains($fields['Attributes'], 'D'))) {
            continue;
        }

        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1 || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
            return fc_engine_error('unsafe_archive', 'Arşiv güvenli olmayan dosya yolu içeriyor.');
        }

        if (isset($fields['Symbolic Link']) && $fields['Symbolic Link'] !== '') {
            return fc_engine_error('unsafe_archive', 'Sembolik bağlantı içeren arşivler reddedilir.');
        }

        $size = isset($fields['Size']) && ctype_digit($fields['Size']) ? (int) $fields['Size'] : 0;
        $compressed = isset($fields['Packed Size']) && ctype_digit($fields['Packed Size']) ? (int) $fields['Packed Size'] : 0;

        if ($size > $maxEntry) {
            return fc_engine_error('archive_entry_too_large', 'Arşivdeki bir dosya izin verilen boyutu aşıyor.');
        }

        if ($compressed > 0 && $size > 1024 * 1024 && ($size / $compressed) > $maxRatio) {
            return fc_engine_error('archive_bomb', 'Arşiv olağandışı sıkıştırma oranı gösteriyor.');
        }

        $total += $size;

        if ($total > $maxTotal || count($entries) >= $maxEntries) {
            return fc_engine_error('archive_too_large', 'Arşiv izin verilen sınırları aşıyor.');
        }

        $entries[] = ['name' => $name, 'size' => $size, 'compressed' => $compressed];
    }

    return ['ok' => true, 'entries' => $entries];
}

/**
 * 7-Zip ile güvenli çıkarma. Çıkarma sonrası ağaç yeniden doğrulanır.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, files: list<array{name: string, bytes: string}>}|array{ok: false, code: string, message: string}
 */
function fc_7z_extract(string $archivePath, string $workspace, array $fc): array
{
    $listing = fc_7z_list($archivePath, $fc);

    if (!($listing['ok'] ?? false)) {
        return $listing;
    }

    $status = fc_engine_status();
    $output = $workspace . '/7z-out-' . bin2hex(random_bytes(6));

    if (!is_dir($output) && !@mkdir($output, 0700, true) && !is_dir($output)) {
        return fc_engine_error('storage_unavailable', 'Arşiv çıkarma dizini oluşturulamadı.');
    }

    $result = fc_run_process(
        [
            (string) $status['optional']['sevenzip']['path'],
            'x',
            '-y',
            '-spf-',
            '-o' . $output,
            '--',
            $archivePath,
        ],
        $output,
        max(30, (int) ($fc['process_timeout_seconds'] ?? 30)),
        32768,
        65536
    );

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'Arşiv çıkarma zaman aşımına uğradı.');
    }

    if (!$result['ok']) {
        return fc_engine_error('conversion_failed', 'Arşiv çıkarılamadı.');
    }

    return fc_scan_extracted_tree($output, $fc, $listing['entries']);
}

/**
 * @param list<array{name: string, size: int, compressed: int}> $expected
 * @param array<string, mixed> $fc
 * @return array{ok: true, files: list<array{name: string, bytes: string}>}|array{ok: false, code: string, message: string}
 */
function fc_scan_extracted_tree(string $root, array $fc, array $expected = []): array
{
    $realRoot = realpath($root);

    if ($realRoot === false || !is_dir($realRoot)) {
        return fc_engine_error('storage_unavailable', 'Arşiv çıkarma dizini doğrulanamadı.');
    }

    $maxEntries = (int) ($fc['max_archive_entries'] ?? 200);
    $maxTotal = (int) ($fc['max_archive_uncompressed_bytes'] ?? 64 * 1024 * 1024);
    $maxEntry = (int) ($fc['max_archive_entry_bytes'] ?? 32 * 1024 * 1024);
    $files = [];
    $total = 0;
    $count = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->isDir()) {
                continue;
            }

            $count++;

            if ($count > $maxEntries) {
                return fc_engine_error('archive_too_many_entries', 'Arşiv izin verilen dosya sayısını aşıyor.');
            }

            if ($file->isLink()) {
                return fc_engine_error('unsafe_archive', 'Sembolik bağlantı içeren arşivler reddedilir.');
            }

            $path = $file->getPathname();
            $real = realpath($path);

            if ($real === false || !str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')) {
                return fc_engine_error('unsafe_archive', 'Arşiv çıkarma dizini dışına yazma girişimi reddedildi.');
            }

            $size = (int) $file->getSize();

            if ($size > $maxEntry) {
                return fc_engine_error('archive_entry_too_large', 'Arşivdeki bir dosya izin verilen boyutu aşıyor.');
            }

            $total += $size;

            if ($total > $maxTotal) {
                return fc_engine_error('archive_too_large', 'Arşiv izin verilen toplam boyutu aşıyor.');
            }

            $bytes = @file_get_contents($path);

            if ($bytes === false || strlen($bytes) !== $size) {
                return fc_engine_error('invalid_archive', 'Arşivdeki dosya okunamadı.');
            }

            $relative = ltrim(substr(str_replace('\\', '/', $real), strlen(rtrim(str_replace('\\', '/', $realRoot), '/'))), '/');

            $files[] = ['name' => fc_safe_archive_name($relative), 'bytes' => $bytes];
        }
    } catch (Throwable $exception) {
        error_log('file-converter 7z scan: ' . get_class($exception));
        return fc_engine_error('invalid_archive', 'Arşiv içeriği doğrulanamadı.');
    }

    if ($files === [] && $expected !== []) {
        return fc_engine_error('conversion_failed', 'Arşivden dosya çıkarılamadı.');
    }

    return ['ok' => true, 'files' => $files];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_convert_with_engine(
    string $sourcePath,
    string $sourceKey,
    string $targetFormat,
    string $destination,
    string $workspace,
    array $fc
): array {
    $extracted = fc_7z_extract($sourcePath, $workspace, $fc);

    if (!($extracted['ok'] ?? false)) {
        return $extracted;
    }

    if ($targetFormat === '7z' || $targetFormat === 'bz2' || $targetFormat === 'xz') {
        return fc_archive_write_with_engine($extracted['files'], $targetFormat, $destination, $workspace, $fc);
    }

    return fc_archive_write($extracted['files'], $targetFormat, $destination, $workspace, $fc);
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, name: string, bytes: string}|array{ok: false, code: string, message: string}
 */
function fc_archive_extract_with_engine(string $sourcePath, string $sourceKey, string $workspace, array $fc): array
{
    $extracted = fc_7z_extract($sourcePath, $workspace, $fc);

    if (!($extracted['ok'] ?? false)) {
        return $extracted;
    }

    if (count($extracted['files']) !== 1) {
        return fc_engine_error('extract_requires_single_file', 'Arşivden tek dosya çıkarmak için arşivde tam olarak bir dosya olmalı.');
    }

    return [
        'ok' => true,
        'name' => $extracted['files'][0]['name'],
        'bytes' => $extracted['files'][0]['bytes'],
    ];
}

/**
 * @param list<array{name: string, bytes: string}> $files
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_archive_write_with_engine(
    array $files,
    string $format,
    string $destination,
    string $workspace,
    array $fc
): array {
    $status = fc_engine_status();

    if (!fc_engine_is_available($status, 'sevenzip')) {
        return fc_engine_error('engine_missing', '7-Zip bulunmadığı için bu arşiv biçimi oluşturulamıyor.');
    }

    if ($format === 'rar') {
        return fc_engine_error('unsupported_target', 'RAR oluşturma desteklenmiyor; yalnızca RAR okunabilir.');
    }

    if ($format === 'gz' && count($files) !== 1) {
        return fc_engine_error('gz_requires_single_file', 'GZ yalnızca tek dosya sıkıştırabilir.');
    }

    $inputDirectory = $workspace . '/7z-in-' . bin2hex(random_bytes(6));
    $used = [];

    if (!is_dir($inputDirectory) && !@mkdir($inputDirectory, 0700, true) && !is_dir($inputDirectory)) {
        return fc_engine_error('storage_unavailable', 'Arşiv girdi dizini oluşturulamadı.');
    }

    foreach ($files as $file) {
        $name = fc_unique_archive_name($file['name'], $used);
        $target = $inputDirectory . '/' . str_replace('/', DIRECTORY_SEPARATOR, $name);

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return fc_engine_error('storage_unavailable', 'Arşiv girdisi yazılamadı.');
        }

        if (@file_put_contents($target, $file['bytes']) !== strlen($file['bytes'])) {
            return fc_engine_error('storage_unavailable', 'Arşiv girdisi yazılamadı.');
        }
    }

    $type = match ($format) {
        '7z' => '-t7z',
        'bz2' => '-tbzip2',
        'xz' => '-txz',
        default => '-t7z',
    };

    $command = [
        (string) $status['optional']['sevenzip']['path'],
        'a',
        $type,
        '-y',
        $destination,
        '.',
    ];

    $result = fc_run_process(
        $command,
        $inputDirectory,
        max(30, (int) ($fc['process_timeout_seconds'] ?? 30)),
        32768,
        65536
    );

    if ($result['timed_out']) {
        return fc_engine_error('timeout', 'Arşiv oluşturma zaman aşımına uğradı.');
    }

    if (!$result['ok'] || !is_file($destination) || filesize($destination) < 1) {
        return fc_engine_error('conversion_failed', 'Arşiv oluşturulamadı.');
    }

    return ['ok' => true, 'bytes' => (int) filesize($destination)];
}

/**
 * GD görselini Imagick ile TIFF olarak yazar. Imagick yoksa hedef gizlidir.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string}
 */
function fc_imagick_write_tiff(GdImage $image, string $destination, array $fc): array
{
    if (!class_exists('Imagick')) {
        return fc_engine_error('engine_missing', 'Imagick bulunmadığı için TIFF üretilemiyor.');
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $canvas = imagecreatetruecolor($width, $height);

    if (!$canvas instanceof GdImage) {
        return fc_engine_error('conversion_failed', 'TIFF için bellek ayrılamadı.');
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
    imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagepng($canvas);
    $png = ob_get_clean();
    imagedestroy($canvas);

    if (!is_string($png) || $png === '') {
        return fc_engine_error('conversion_failed', 'TIFF kaynak verisi üretilemedi.');
    }

    try {
        $imagick = new Imagick();
        $imagick->readImageBlob($png);
        $imagick->setImageFormat('tiff');
        $imagick->setImageCompression(Imagick::COMPRESSION_LZW);
        $imagick->writeImage($destination);
        $imagick->clear();
    } catch (Throwable $exception) {
        error_log('file-converter imagick tiff: ' . get_class($exception));
        return fc_engine_error('conversion_failed', 'Görsel TIFF olarak yazılamadı.');
    }

    $size = @filesize($destination);

    if ($size === false || $size < 1) {
        return fc_engine_error('conversion_failed', 'TIFF çıktısı boş.');
    }

    return ['ok' => true, 'bytes' => (int) $size];
}

/* ---- Bölüm: biçim kaydı, doğrulama, CSRF, yanıt (file_converter) ---- */
/**
 * File Converter — yetenek kaydı, yükleme doğrulaması, isteğe özel geçici
 * çalışma dizini ve yanıt yardımcıları.
 *
 * Hiçbir kullanıcı dosyası kalıcı olarak saklanmaz. Dönüşüm, sistem geçici
 * dizininde rastgele adlı bir çalışma dizininde yapılır; yanıt gönderildikten
 * sonra (başarı, hata, zaman aşımı veya iptal) silinir. Uygulama veritabanı,
 * oturum, IP kaydı veya kalıcı indirme bağlantısı üretmez.
 */

/**
 * Bir istisnayı yığın izi ve kullanıcı verisi olmadan loglar.
 */
function fc_log_exception(string $context, Throwable $exception): void
{
    $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '';
    $message = function_exists('mb_substr')
        ? mb_substr($message, 0, 240, 'UTF-8')
        : substr($message, 0, 240);

    error_log(
        'file-converter ' . $context . ': ' . get_class($exception)
        . ($message !== '' ? ' — ' . $message : '')
    );
}

function fc_truncate(string $text, int $limit): string
{
    if ($limit < 1) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit
            ? mb_substr($text, 0, $limit, 'UTF-8')
            : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
}

function fc_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

/**
 * php.ini boyut değerini (8M, 512K, 1G) bayta çevirir; 0/negatif sınırsızdır.
 */
function fc_ini_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '' || preg_match('/^(-?\d+)\s*([KMG])?$/i', $value, $match) !== 1) {
        return 0;
    }

    $number = (int) $match[1];
    $unit = strtoupper($match[2] ?? '');

    return match ($unit) {
        'K' => $number * 1024,
        'M' => $number * 1024 * 1024,
        'G' => $number * 1024 * 1024 * 1024,
        default => $number,
    };
}

/**
 * Yapılandırma ve php.ini değerlerinden etkin sınırları üretir.
 *
 * @param array<string, mixed> $fc
 * @return array<string, int>
 */
function fc_limits(array $fc): array
{
    $phpUpload = fc_ini_bytes((string) ini_get('upload_max_filesize'));
    $phpPost = fc_ini_bytes((string) ini_get('post_max_size'));

    $maxFile = (int) ($fc['max_file_bytes'] ?? 8 * 1024 * 1024);
    $maxRequest = (int) ($fc['max_request_bytes'] ?? 24 * 1024 * 1024);

    if ($phpUpload > 0) {
        $maxFile = min($maxFile, $phpUpload);
    }

    if ($phpPost > 0) {
        $maxRequest = min($maxRequest, $phpPost);
    }

    $maxFile = max(1, min($maxFile, max(1, $maxRequest - 16 * 1024)));

    return [
        'max_file_bytes' => $maxFile,
        'max_request_bytes' => max(1, $maxRequest),
        'max_files_per_request' => max(1, (int) ($fc['max_files_per_request'] ?? 6)),
        'max_image_pixels' => max(10_000, (int) ($fc['max_image_pixels'] ?? 24_000_000)),
        'max_text_bytes' => max(1024, (int) ($fc['max_text_bytes'] ?? 8 * 1024 * 1024)),
        'max_output_bytes' => max(1024, (int) ($fc['max_output_bytes'] ?? 64 * 1024 * 1024)),
        'process_timeout_seconds' => max(5, (int) ($fc['process_timeout_seconds'] ?? 30)),
        'office_timeout_seconds' => max(10, (int) ($fc['office_timeout_seconds'] ?? 90)),
        'pdf_dpi' => max(72, min(300, (int) ($fc['pdf_dpi'] ?? 150))),
        'max_archive_entries' => max(1, (int) ($fc['max_archive_entries'] ?? 200)),
        'max_archive_entry_bytes' => max(1024, (int) ($fc['max_archive_entry_bytes'] ?? 32 * 1024 * 1024)),
        'max_archive_uncompressed_bytes' => max(1024, (int) ($fc['max_archive_uncompressed_bytes'] ?? 64 * 1024 * 1024)),
        'max_archive_ratio' => max(10, (int) ($fc['max_archive_ratio'] ?? 200)),
        'php_upload_max' => max(0, $phpUpload),
        'php_post_max' => max(0, $phpPost),
    ];
}

/** @param array<string, int> $limits */
function fc_request_size_exceeded(array $limits): bool
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    return $length > 0 && $length > (int) ($limits['max_request_bytes'] ?? 0);
}

/**
 * PHP `post_max_size` aşıldığında gövdeyi betik çalışmadan atar. Bu durumda
 * boş `$_POST`/`$_FILES` CSRF hatası gibi görünmemelidir.
 *
 * @param array<string, int> $limits
 */
function fc_request_body_discarded(array $limits): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }

    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = (int) ($limits['php_post_max'] ?? 0);

    return $length > 0 && $postMax > 0 && $length > $postMax
        && $_POST === [] && $_FILES === [];
}

/**
 * @param array<string, mixed> $payload
 */
function fc_json_response(int $status, array $payload): never
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fc_json_error(int $status, string $code, string $message, array $extra = []): never
{
    fc_json_response($status, [
        'ok' => false,
        'error' => array_merge([
            'code' => $code,
            'message' => $message,
        ], $extra),
    ]);
}

function fc_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function fc_csrf_cookie_name(): string
{
    return 'fc_csrf';
}

function fc_cookie_path(): string
{
    $base = function_exists('app_base_path') ? app_base_path() : '';

    return ($base === '' ? '' : $base) . '/';
}

/**
 * Durum gerektirmeyen çift gönderimli CSRF belirteci üretir.
 *
 * Belirteç hem HttpOnly çerezde hem formda bulunur; sunucu tarafında oturum
 * veya kayıt tutulmaz.
 */
function fc_csrf_token(): string
{
    $name = fc_csrf_cookie_name();
    $current = isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : '';

    if (preg_match('/^[a-f0-9]{64}$/', $current) === 1) {
        return $current;
    }

    $token = bin2hex(random_bytes(32));
    $_COOKIE[$name] = $token;

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie($name, $token, [
            'expires' => time() + 86400,
            'path' => fc_cookie_path(),
            'secure' => fc_is_https(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return $token;
}

function fc_csrf_valid(?string $submitted): bool
{
    $name = fc_csrf_cookie_name();
    $cookie = isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : '';

    if ($submitted === null
        || preg_match('/^[a-f0-9]{64}$/', $submitted) !== 1
        || preg_match('/^[a-f0-9]{64}$/', $cookie) !== 1
    ) {
        return false;
    }

    return hash_equals($cookie, $submitted);
}

/**
 * @return array<int, string>
 */
function &fc_workspace_paths(): array
{
    static $paths = [];

    return $paths;
}

/**
 * Çalışma dizinini kapanışta silinecekler listesine ekler.
 */
function fc_workspace_register(string $path): void
{
    static $registered = false;

    $paths = &fc_workspace_paths();

    if (!in_array($path, $paths, true)) {
        $paths[] = $path;
    }

    if (!$registered) {
        $registered = true;
        register_shutdown_function('fc_workspace_cleanup_all');
    }
}

/**
 * Yalnızca sistem geçici dizini altındaki `fc-` önekli dizinleri siler.
 */
function fc_is_workspace_path(string $path): bool
{
    $real = realpath($path);
    $temp = realpath(sys_get_temp_dir());

    if ($real === false || $temp === false || !is_dir($real)) {
        return false;
    }

    $normalize = static fn (string $value): string => rtrim(str_replace('\\', '/', $value), '/');
    $realNormalized = $normalize($real);
    $tempNormalized = $normalize($temp);

    if (!str_starts_with($realNormalized, $tempNormalized . '/')) {
        return false;
    }

    return str_starts_with(basename($realNormalized), 'fc-');
}

function fc_workspace_destroy(string $path): void
{
    if (!fc_is_workspace_path($path)) {
        return;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    } catch (Throwable $exception) {
        error_log('file-converter workspace cleanup: ' . get_class($exception));
    }

    @rmdir($path);
}

function fc_workspace_cleanup_all(): void
{
    $paths = &fc_workspace_paths();

    foreach ($paths as $path) {
        fc_workspace_destroy($path);
    }

    $paths = [];
}

/**
 * İsteğe özel, rastgele adlı ve 0700 izinli geçici çalışma dizini açar.
 *
 * @return array{ok: true, path: string}|array{ok: false, code: string, message: string}
 */
function fc_workspace_create(): array
{
    $temp = realpath(sys_get_temp_dir());

    if ($temp === false || !is_dir($temp) || !is_writable($temp)) {
        return ['ok' => false, 'code' => 'storage_unavailable', 'message' => 'Geçici çalışma alanı kullanılamıyor.'];
    }

    $path = $temp . DIRECTORY_SEPARATOR . 'fc-' . bin2hex(random_bytes(8));

    if (!@mkdir($path, 0700) && !is_dir($path)) {
        return ['ok' => false, 'code' => 'storage_unavailable', 'message' => 'Geçici çalışma dizini oluşturulamadı.'];
    }

    $real = realpath($path);

    if ($real === false || !fc_is_workspace_path($real)) {
        @rmdir($path);

        return ['ok' => false, 'code' => 'storage_unavailable', 'message' => 'Geçici çalışma dizini doğrulanamadı.'];
    }

    fc_workspace_register($real);

    return ['ok' => true, 'path' => $real];
}

/**
 * `$_FILES` girdisini tek/çoklu dosya ayrımı yapmadan listeye çevirir.
 *
 * @param array<string, mixed> $files
 * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
function fc_normalize_uploads(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }

    $names = $files['name'];

    if (!is_array($names)) {
        return [[
            'name' => (string) $names,
            'type' => (string) ($files['type'] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'] ?? ''),
            'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'] ?? 0),
        ]];
    }

    $list = [];

    foreach ($names as $index => $name) {
        $list[] = [
            'name' => (string) $name,
            'type' => (string) ($files['type'][$index] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
            'error' => (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }

    return $list;
}

function fc_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucunun yükleme sınırını aşıyor.',
        UPLOAD_ERR_PARTIAL => 'Dosya yalnızca kısmen yüklendi; lütfen yeniden deneyin.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici yükleme dizini yok.',
        UPLOAD_ERR_CANT_WRITE => 'Yükleme diske yazılamadı.',
        UPLOAD_ERR_EXTENSION => 'Yükleme bir PHP eklentisi tarafından durduruldu.',
        default => 'Dosya yüklenemedi.',
    };
}

/**
 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $upload
 * @param array<string, int> $limits
 * @return array{ok: true, size: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_validate_upload(array $upload, array $limits): array
{
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

        return [
            'ok' => false,
            'code' => $tooLarge ? 'file_too_large' : 'upload_failed',
            'message' => fc_upload_error_message($upload['error']),
            'status' => $tooLarge ? 413 : 400,
        ];
    }

    $tmp = $upload['tmp_name'];

    if ($tmp === '' || !is_file($tmp) || !is_uploaded_file($tmp)) {
        return [
            'ok' => false,
            'code' => 'upload_failed',
            'message' => 'Yükleme doğrulanamadı.',
            'status' => 400,
        ];
    }

    $size = @filesize($tmp);

    if ($size === false || $size < 1) {
        return [
            'ok' => false,
            'code' => 'empty_file',
            'message' => 'Dosya boş.',
            'status' => 422,
        ];
    }

    if ($size > (int) $limits['max_file_bytes']) {
        return [
            'ok' => false,
            'code' => 'file_too_large',
            'message' => 'Dosya boyutu sınırı aşıyor: en fazla ' . fc_format_bytes((int) $limits['max_file_bytes']) . '.',
            'status' => 413,
        ];
    }

    return ['ok' => true, 'size' => (int) $size];
}

/**
 * Yüklenen dosyayı kullanıcı adından bağımsız rastgele bir ada taşır.
 *
 * @return array{ok: true, path: string}|array{ok: false, code: string, message: string, status: int}
 */
function fc_move_upload(string $tmpName, string $workspace): array
{
    $target = $workspace . DIRECTORY_SEPARATOR . 'upload-' . bin2hex(random_bytes(8)) . '.bin';

    if (!@move_uploaded_file($tmpName, $target)) {
        return [
            'ok' => false,
            'code' => 'storage_unavailable',
            'message' => 'Yüklenen dosya geçici alana taşınamadı.',
            'status' => 500,
        ];
    }

    if (!is_file($target) || filesize($target) < 1) {
        @unlink($target);

        return [
            'ok' => false,
            'code' => 'storage_unavailable',
            'message' => 'Yüklenen dosya geçici alana yazılamadı.',
            'status' => 500,
        ];
    }

    return ['ok' => true, 'path' => $target];
}

function fc_finfo_mime(string $path): string
{
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = @$finfo->file($path);

        if (is_string($mime) && $mime !== '') {
            return strtolower(trim(explode(';', $mime)[0]));
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);

        if (is_string($mime) && $mime !== '') {
            return strtolower(trim(explode(';', $mime)[0]));
        }
    }

    return 'application/octet-stream';
}

/**
 * Sunucunun tanıdığı kaynak biçimler. `kind` dönüşüm yolunu belirler.
 *
 * @return array<string, array{
 *     label: string,
 *     kind: string,
 *     extension: string,
 *     extensions: list<string>,
 *     mimes: list<string>,
 *     family?: string
 * }>
 */
function fc_format_registry(): array
{
    static $registry = null;

    if (is_array($registry)) {
        return $registry;
    }

    $registry = [
        'jpg' => ['label' => 'JPEG', 'kind' => 'image', 'extension' => 'jpg', 'extensions' => ['jpg', 'jpeg'], 'mimes' => ['image/jpeg']],
        'png' => ['label' => 'PNG', 'kind' => 'image', 'extension' => 'png', 'extensions' => ['png'], 'mimes' => ['image/png']],
        'gif' => ['label' => 'GIF', 'kind' => 'image', 'extension' => 'gif', 'extensions' => ['gif'], 'mimes' => ['image/gif']],
        'bmp' => ['label' => 'BMP', 'kind' => 'image', 'extension' => 'bmp', 'extensions' => ['bmp'], 'mimes' => ['image/bmp', 'image/x-ms-bmp']],
        'webp' => ['label' => 'WebP', 'kind' => 'image', 'extension' => 'webp', 'extensions' => ['webp'], 'mimes' => ['image/webp']],
        'avif' => ['label' => 'AVIF', 'kind' => 'image', 'extension' => 'avif', 'extensions' => ['avif'], 'mimes' => ['image/avif']],
        'tiff' => ['label' => 'TIFF', 'kind' => 'image', 'extension' => 'tiff', 'extensions' => ['tif', 'tiff'], 'mimes' => ['image/tiff']],
        'ico' => ['label' => 'ICO', 'kind' => 'image', 'extension' => 'ico', 'extensions' => ['ico'], 'mimes' => ['image/x-icon', 'image/vnd.microsoft.icon']],
        'svg' => ['label' => 'SVG', 'kind' => 'image', 'extension' => 'svg', 'extensions' => ['svg'], 'mimes' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain']],
        'pdf' => ['label' => 'PDF', 'kind' => 'pdf', 'extension' => 'pdf', 'extensions' => ['pdf'], 'mimes' => ['application/pdf']],
        'doc' => ['label' => 'DOC', 'kind' => 'document', 'family' => 'word', 'extension' => 'doc', 'extensions' => ['doc'], 'mimes' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/cdfv2']],
        'docx' => ['label' => 'DOCX', 'kind' => 'document', 'family' => 'word', 'extension' => 'docx', 'extensions' => ['docx'], 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'odt' => ['label' => 'ODT', 'kind' => 'document', 'family' => 'word', 'extension' => 'odt', 'extensions' => ['odt'], 'mimes' => ['application/vnd.oasis.opendocument.text', 'application/zip']],
        'rtf' => ['label' => 'RTF', 'kind' => 'document', 'family' => 'word', 'extension' => 'rtf', 'extensions' => ['rtf'], 'mimes' => ['application/rtf', 'text/rtf', 'text/plain']],
        'ppt' => ['label' => 'PPT', 'kind' => 'document', 'family' => 'slides', 'extension' => 'ppt', 'extensions' => ['ppt'], 'mimes' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/cdfv2']],
        'pptx' => ['label' => 'PPTX', 'kind' => 'document', 'family' => 'slides', 'extension' => 'pptx', 'extensions' => ['pptx'], 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']],
        'odp' => ['label' => 'ODP', 'kind' => 'document', 'family' => 'slides', 'extension' => 'odp', 'extensions' => ['odp'], 'mimes' => ['application/vnd.oasis.opendocument.presentation', 'application/zip']],
        'xls' => ['label' => 'XLS', 'kind' => 'document', 'family' => 'sheet', 'extension' => 'xls', 'extensions' => ['xls'], 'mimes' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/cdfv2']],
        'xlsx' => ['label' => 'XLSX', 'kind' => 'document', 'family' => 'sheet', 'extension' => 'xlsx', 'extensions' => ['xlsx'], 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
        'ods' => ['label' => 'ODS', 'kind' => 'document', 'family' => 'sheet', 'extension' => 'ods', 'extensions' => ['ods'], 'mimes' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
        'txt' => ['label' => 'TXT', 'kind' => 'text', 'extension' => 'txt', 'extensions' => ['txt'], 'mimes' => ['text/plain']],
        'md' => ['label' => 'Markdown', 'kind' => 'text', 'extension' => 'md', 'extensions' => ['md', 'markdown'], 'mimes' => ['text/markdown', 'text/x-markdown', 'text/plain']],
        'html' => ['label' => 'HTML', 'kind' => 'text', 'extension' => 'html', 'extensions' => ['html', 'htm'], 'mimes' => ['text/html', 'application/xhtml+xml']],
        'csv' => ['label' => 'CSV', 'kind' => 'text', 'extension' => 'csv', 'extensions' => ['csv'], 'mimes' => ['text/csv', 'application/csv', 'text/plain']],
        'json' => ['label' => 'JSON', 'kind' => 'text', 'extension' => 'json', 'extensions' => ['json'], 'mimes' => ['application/json', 'text/plain', 'text/json']],
        'xml' => ['label' => 'XML', 'kind' => 'text', 'extension' => 'xml', 'extensions' => ['xml'], 'mimes' => ['application/xml', 'text/xml', 'text/plain']],
        'yaml' => ['label' => 'YAML', 'kind' => 'text', 'extension' => 'yaml', 'extensions' => ['yaml', 'yml'], 'mimes' => ['application/yaml', 'application/x-yaml', 'text/yaml', 'text/plain']],
        'toml' => ['label' => 'TOML', 'kind' => 'text', 'extension' => 'toml', 'extensions' => ['toml'], 'mimes' => ['application/toml', 'text/toml', 'text/plain']],
        'mp3' => ['label' => 'MP3', 'kind' => 'audio', 'extension' => 'mp3', 'extensions' => ['mp3'], 'mimes' => ['audio/mpeg', 'audio/mp3']],
        'wav' => ['label' => 'WAV', 'kind' => 'audio', 'extension' => 'wav', 'extensions' => ['wav'], 'mimes' => ['audio/wav', 'audio/x-wav']],
        'ogg' => ['label' => 'OGG', 'kind' => 'audio', 'extension' => 'ogg', 'extensions' => ['ogg', 'oga'], 'mimes' => ['audio/ogg', 'application/ogg']],
        'opus' => ['label' => 'Opus', 'kind' => 'audio', 'extension' => 'opus', 'extensions' => ['opus'], 'mimes' => ['audio/ogg', 'audio/opus', 'application/ogg']],
        'aac' => ['label' => 'AAC', 'kind' => 'audio', 'extension' => 'aac', 'extensions' => ['aac'], 'mimes' => ['audio/aac', 'audio/x-aac', 'audio/mpeg']],
        'm4a' => ['label' => 'M4A', 'kind' => 'audio', 'extension' => 'm4a', 'extensions' => ['m4a'], 'mimes' => ['audio/mp4', 'audio/x-m4a']],
        'flac' => ['label' => 'FLAC', 'kind' => 'audio', 'extension' => 'flac', 'extensions' => ['flac'], 'mimes' => ['audio/flac', 'audio/x-flac']],
        'mp4' => ['label' => 'MP4', 'kind' => 'video', 'extension' => 'mp4', 'extensions' => ['mp4'], 'mimes' => ['video/mp4', 'application/mp4']],
        'm4v' => ['label' => 'M4V', 'kind' => 'video', 'extension' => 'm4v', 'extensions' => ['m4v'], 'mimes' => ['video/x-m4v', 'video/mp4']],
        'webm' => ['label' => 'WebM', 'kind' => 'video', 'extension' => 'webm', 'extensions' => ['webm'], 'mimes' => ['video/webm']],
        'mkv' => ['label' => 'MKV', 'kind' => 'video', 'extension' => 'mkv', 'extensions' => ['mkv'], 'mimes' => ['video/x-matroska', 'video/webm']],
        'mov' => ['label' => 'MOV', 'kind' => 'video', 'extension' => 'mov', 'extensions' => ['mov'], 'mimes' => ['video/quicktime']],
        'avi' => ['label' => 'AVI', 'kind' => 'video', 'extension' => 'avi', 'extensions' => ['avi'], 'mimes' => ['video/x-msvideo', 'video/avi']],
        'mpeg' => ['label' => 'MPEG', 'kind' => 'video', 'extension' => 'mpeg', 'extensions' => ['mpeg', 'mpg'], 'mimes' => ['video/mpeg']],
        'zip' => ['label' => 'ZIP', 'kind' => 'archive', 'extension' => 'zip', 'extensions' => ['zip'], 'mimes' => ['application/zip', 'application/x-zip-compressed']],
        'tar' => ['label' => 'TAR', 'kind' => 'archive', 'extension' => 'tar', 'extensions' => ['tar'], 'mimes' => ['application/x-tar', 'application/tar']],
        'gz' => ['label' => 'GZ', 'kind' => 'archive', 'extension' => 'gz', 'extensions' => ['gz', 'tgz'], 'mimes' => ['application/gzip', 'application/x-gzip']],
        'bz2' => ['label' => 'BZ2', 'kind' => 'archive', 'extension' => 'bz2', 'extensions' => ['bz2'], 'mimes' => ['application/x-bzip2', 'application/bzip2']],
        'xz' => ['label' => 'XZ', 'kind' => 'archive', 'extension' => 'xz', 'extensions' => ['xz'], 'mimes' => ['application/x-xz']],
        '7z' => ['label' => '7Z', 'kind' => 'archive', 'extension' => '7z', 'extensions' => ['7z'], 'mimes' => ['application/x-7z-compressed']],
        'rar' => ['label' => 'RAR', 'kind' => 'archive', 'extension' => 'rar', 'extensions' => ['rar'], 'mimes' => ['application/vnd.rar', 'application/x-rar-compressed']],
    ];

    return $registry;
}

function fc_format_key_for_extension(string $extension): ?string
{
    $extension = strtolower(trim($extension));

    if ($extension === '') {
        return null;
    }

    foreach (fc_format_registry() as $key => $entry) {
        if (in_array($extension, $entry['extensions'], true)) {
            return $key;
        }
    }

    return null;
}

function fc_source_kind(string $key): string
{
    return (string) (fc_format_registry()[$key]['kind'] ?? '');
}

function fc_error_result(string $code, string $message, int $status = 422): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'status' => $status,
    ];
}

function fc_error_status(string $code): int
{
    return match ($code) {
        'file_too_large', 'output_too_large', 'archive_too_large', 'archive_entry_too_large' => 413,
        'unsupported_source', 'unsupported_target', 'extension_mismatch', 'mime_mismatch' => 415,
        'engine_missing', 'storage_unavailable' => 503,
        'conversion_failed', 'invalid_archive' => 500,
        default => 422,
    };
}

function fc_read_head(string $path, int $length = 1024): string
{
    $head = @file_get_contents($path, false, null, 0, $length);

    return is_string($head) ? $head : '';
}

/**
 * ZIP tabanlı ofis belgelerini içerik parçalarından tanır.
 */
function fc_office_zip_kind(string $path): ?string
{
    if (!class_exists('PharData')) {
        return null;
    }

    $real = realpath($path);

    if ($real === false) {
        return null;
    }

    try {
        $phar = new PharData($real);

        if ($phar->offsetExists('word/document.xml')) {
            unset($phar);

            return 'docx';
        }

        if ($phar->offsetExists('xl/workbook.xml')) {
            unset($phar);

            return 'xlsx';
        }

        if ($phar->offsetExists('ppt/presentation.xml')) {
            unset($phar);

            return 'pptx';
        }

        $odf = null;

        if ($phar->offsetExists('mimetype')) {
            $mime = @file_get_contents('phar://' . str_replace('\\', '/', $real) . '/mimetype');

            if (is_string($mime)) {
                $odf = match (trim($mime)) {
                    'application/vnd.oasis.opendocument.text' => 'odt',
                    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
                    'application/vnd.oasis.opendocument.presentation' => 'odp',
                    default => null,
                };
            }
        }

        unset($phar);

        return $odf;
    } catch (Throwable $exception) {
        return null;
    }
}

function fc_ole_guess(string $path): ?string
{
    $bytes = @file_get_contents($path, false, null, 0, 1024 * 1024);

    if (!is_string($bytes)) {
        return null;
    }

    if (str_contains($bytes, 'WordDocument')) {
        return 'doc';
    }

    if (str_contains($bytes, 'PowerPoint Document')) {
        return 'ppt';
    }

    if (str_contains($bytes, 'Workbook') || str_contains($bytes, 'Book')) {
        return 'xls';
    }

    return null;
}

/**
 * Dosya imzasından kaynak anahtarını çıkarır. Metin biçimleri için null döner.
 */
function fc_magic_key(string $head, string $mime, string $extension, string $path): ?string
{
    if ($head === '') {
        return null;
    }

    if (str_starts_with($head, '%PDF-')) {
        return 'pdf';
    }

    if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            return $extension;
        }

        return fc_ole_guess($path);
    }

    if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08")) {
        return fc_office_zip_kind($path) ?? 'zip';
    }

    if (str_starts_with($head, '{\\rtf')) {
        return 'rtf';
    }

    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return 'jpg';
    }

    if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
        return 'png';
    }

    if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
        return 'gif';
    }

    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'webp';
    }

    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE') {
        return 'wav';
    }

    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'AVI ') {
        return 'avi';
    }

    if (str_starts_with($head, 'BM') && strlen($head) >= 18) {
        $dib = unpack('V', substr($head, 14, 4))[1] ?? 0;

        if (in_array((int) $dib, [12, 40, 52, 56, 108, 124], true)) {
            return 'bmp';
        }
    }

    if (str_starts_with($head, "\x00\x00\x01\x00") && strlen($head) >= 6) {
        $count = unpack('v', substr($head, 4, 2))[1] ?? 0;

        if ((int) $count >= 1 && (int) $count <= 64) {
            return 'ico';
        }
    }

    if (str_starts_with($head, "\x1F\x8B")) {
        return 'gz';
    }

    if (str_starts_with($head, 'BZh')) {
        return 'bz2';
    }

    if (str_starts_with($head, "\xFD7zXZ\x00")) {
        return 'xz';
    }

    if (str_starts_with($head, "7z\xBC\xAF\x27\x1C")) {
        return '7z';
    }

    if (str_starts_with($head, "Rar!\x1A\x07")) {
        return 'rar';
    }

    if (strlen($head) > 262 && substr($head, 257, 5) === 'ustar') {
        return 'tar';
    }

    if (str_starts_with($head, 'fLaC')) {
        return 'flac';
    }

    if (str_starts_with($head, 'OggS')) {
        if (str_contains(substr($head, 0, 64), 'OpusHead')) {
            return 'opus';
        }

        return 'ogg';
    }

    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
        return str_contains(substr($head, 0, 64), 'webm') ? 'webm' : 'mkv';
    }

    if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
        $brand = substr($head, 8, 4);
        $brands = substr($head, 8, 32);

        if (str_contains($brands, 'avif') || str_contains($brands, 'avis')) {
            return 'avif';
        }

        if (str_starts_with($brand, 'heic') || str_starts_with($brand, 'heix') || $brand === 'mif1') {
            return null;
        }

        return match ($brand) {
            'qt  ' => 'mov',
            'M4A ', 'M4B ' => 'm4a',
            'M4V ' => 'm4v',
            default => match ($extension) {
                'm4a' => 'm4a',
                'm4v' => 'm4v',
                'mov' => 'mov',
                default => 'mp4',
            },
        };
    }

    if (str_starts_with($head, "\x00\x00\x01\xBA") || str_starts_with($head, "\x00\x00\x01\xB3")) {
        return 'mpeg';
    }

    if (str_starts_with($head, 'ID3') || (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
        if (!str_starts_with($mime, 'audio/') && !in_array($extension, ['mp3', 'mp2', 'aac'], true)) {
            return null;
        }

        $second = ord($head[1]);

        // ADTS (AAC) çerçeve başlığı: 0xFFF1 / 0xFFF9
        if (($second & 0xF6) === 0xF0) {
            return 'aac';
        }

        return 'mp3';
    }

    if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
        if (str_starts_with($mime, 'image/') || in_array($extension, ['tif', 'tiff'], true)) {
            return 'tiff';
        }
    }

    $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");

    if (preg_match('/<svg[\s>]/i', substr($trimmed, 0, 1024)) === 1) {
        return 'svg';
    }

    return null;
}

/**
 * Metin ve veri biçimlerini içerik + uzantı ile doğrular.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, key: string}|array{ok: false, code: string, message: string, status: int}
 */
function fc_detect_text_source(string $path, string $head, string $mime, string $extension, array $fc): array
{
    if (str_contains($head, "\0")) {
        return fc_error_result('unsupported_source', 'Dosya ikili (binary) veri içeriyor; metin biçimi olarak okunamaz.', 415);
    }

    $extensionKey = fc_format_key_for_extension($extension);
    $extensionIsText = $extensionKey !== null && fc_source_kind($extensionKey) === 'text';

    $textMime = str_starts_with($mime, 'text/')
        || in_array($mime, [
            'application/json',
            'application/xml',
            'text/xml',
            'application/yaml',
            'application/x-yaml',
            'application/toml',
            'application/csv',
            'application/xhtml+xml',
        ], true);

    if (!$textMime && !$extensionIsText) {
        return fc_error_result('unsupported_source', 'Dosya türü tanınamadı veya desteklenmiyor.', 415);
    }

    $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");

    if ($extensionIsText) {
        $key = (string) $extensionKey;
    } elseif (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
        $key = 'json';
    } elseif (stripos($trimmed, '<?xml') === 0) {
        $key = 'xml';
    } elseif (preg_match('/^<(!doctype\s+html|html)[\s>]/i', $trimmed) === 1) {
        $key = 'html';
    } else {
        $key = 'txt';
    }

    $read = fc_text_read($path, $key, $fc);

    if (!($read['ok'] ?? false)) {
        return fc_error_result(
            (string) ($read['code'] ?? 'invalid_text'),
            (string) ($read['message'] ?? 'Metin dosyası okunamadı.'),
            fc_error_status((string) ($read['code'] ?? ''))
        );
    }

    if (in_array($key, ['json', 'yaml', 'toml', 'xml', 'csv'], true)) {
        $parsed = fc_text_structured_parse($key, $read['text'], $fc);

        if (!($parsed['ok'] ?? false)) {
            $code = (string) ($parsed['code'] ?? 'invalid_text');

            return fc_error_result(
                $code,
                (string) ($parsed['message'] ?? 'Dosya içeriği geçersiz.'),
                fc_error_status($code)
            );
        }
    }

    return ['ok' => true, 'key' => $key];
}

/**
 * Kaynak dosyayı MIME + imza + gerçek biçim ile doğrular.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, key: string, mime: string, size: int, label: string}|array{ok: false, code: string, message: string, status: int}
 */
function fc_detect_source(string $path, string $originalName, array $fc): array
{
    if (!is_file($path)) {
        return fc_error_result('read_failed', 'Dosya okunamadı.', 400);
    }

    $size = @filesize($path);

    if ($size === false || $size < 1) {
        return fc_error_result('empty_file', 'Dosya boş.', 422);
    }

    $limits = fc_limits($fc);

    if ($size > (int) $limits['max_file_bytes']) {
        return fc_error_result('file_too_large', 'Dosya boyutu sınırı aşıyor.', 413);
    }

    $head = fc_read_head($path);
    $mime = fc_finfo_mime($path);
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $key = fc_magic_key($head, $mime, $extension, $path);

    if ($key === null) {
        $text = fc_detect_text_source($path, $head, $mime, $extension, $fc);

        if (!($text['ok'] ?? false)) {
            return $text;
        }

        $key = (string) $text['key'];
    }

    $registry = fc_format_registry();
    $allowedMimes = $registry[$key]['mimes'] ?? [];
    $genericMimes = [
        'application/octet-stream',
        'application/zip',
        'application/x-ole-storage',
        'application/cdfv2',
        'application/vnd.ms-office',
        'text/plain',
    ];

    if ($allowedMimes !== []
        && !in_array($mime, $allowedMimes, true)
        && !in_array($mime, $genericMimes, true)
    ) {
        return fc_error_result('mime_mismatch', 'Dosya içeriği bildirilen türle uyuşmuyor.', 415);
    }

    if ($extension !== '') {
        $extensionKey = fc_format_key_for_extension($extension);

        if ($extensionKey !== null && $extensionKey !== $key) {
            return fc_error_result(
                'extension_mismatch',
                'Dosya uzantısı içerikle uyuşmuyor; içerik ' . ($registry[$key]['label'] ?? $key) . ' olarak algılandı.',
                415
            );
        }
    }

    return [
        'ok' => true,
        'key' => $key,
        'mime' => $mime,
        'size' => (int) $size,
        'label' => $registry[$key]['label'] ?? strtoupper($key),
    ];
}

/**
 * Dönüşüm hedeflerinin görünen adı, uzantısı, MIME türü ve motoru.
 *
 * @return array<string, array{label: string, kind: string, extension: string, mime: string, engine: string, note?: string}>
 */
function fc_target_meta(): array
{
    return [
        'jpg' => ['label' => 'JPEG', 'kind' => 'image', 'extension' => 'jpg', 'mime' => 'image/jpeg', 'engine' => 'gd'],
        'png' => ['label' => 'PNG', 'kind' => 'image', 'extension' => 'png', 'mime' => 'image/png', 'engine' => 'gd'],
        'gif' => ['label' => 'GIF', 'kind' => 'image', 'extension' => 'gif', 'mime' => 'image/gif', 'engine' => 'gd'],
        'bmp' => ['label' => 'BMP', 'kind' => 'image', 'extension' => 'bmp', 'mime' => 'image/bmp', 'engine' => 'gd'],
        'webp' => ['label' => 'WebP', 'kind' => 'image', 'extension' => 'webp', 'mime' => 'image/webp', 'engine' => 'gd'],
        'avif' => ['label' => 'AVIF', 'kind' => 'image', 'extension' => 'avif', 'mime' => 'image/avif', 'engine' => 'gd'],
        'ico' => ['label' => 'ICO', 'kind' => 'image', 'extension' => 'ico', 'mime' => 'image/x-icon', 'engine' => 'gd'],
        'tiff' => ['label' => 'TIFF', 'kind' => 'image', 'extension' => 'tiff', 'mime' => 'image/tiff', 'engine' => 'imagick'],
        'pdf' => ['label' => 'PDF', 'kind' => 'pdf', 'extension' => 'pdf', 'mime' => 'application/pdf', 'engine' => 'core'],
        'txt' => ['label' => 'TXT', 'kind' => 'text', 'extension' => 'txt', 'mime' => 'text/plain; charset=utf-8', 'engine' => 'core'],
        'html' => ['label' => 'HTML', 'kind' => 'text', 'extension' => 'html', 'mime' => 'text/html; charset=utf-8', 'engine' => 'core'],
        'csv' => ['label' => 'CSV', 'kind' => 'text', 'extension' => 'csv', 'mime' => 'text/csv; charset=utf-8', 'engine' => 'core'],
        'json' => ['label' => 'JSON', 'kind' => 'text', 'extension' => 'json', 'mime' => 'application/json; charset=utf-8', 'engine' => 'core'],
        'yaml' => ['label' => 'YAML', 'kind' => 'text', 'extension' => 'yaml', 'mime' => 'text/yaml; charset=utf-8', 'engine' => 'core'],
        'toml' => ['label' => 'TOML', 'kind' => 'text', 'extension' => 'toml', 'mime' => 'application/toml; charset=utf-8', 'engine' => 'core'],
        'xml' => ['label' => 'XML', 'kind' => 'text', 'extension' => 'xml', 'mime' => 'application/xml; charset=utf-8', 'engine' => 'core'],
        'rtf' => ['label' => 'RTF', 'kind' => 'document', 'extension' => 'rtf', 'mime' => 'application/rtf', 'engine' => 'libreoffice'],
        'docx' => ['label' => 'DOCX', 'kind' => 'document', 'extension' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'engine' => 'libreoffice'],
        'odt' => ['label' => 'ODT', 'kind' => 'document', 'extension' => 'odt', 'mime' => 'application/vnd.oasis.opendocument.text', 'engine' => 'libreoffice'],
        'pptx' => ['label' => 'PPTX', 'kind' => 'document', 'extension' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'engine' => 'libreoffice'],
        'odp' => ['label' => 'ODP', 'kind' => 'document', 'extension' => 'odp', 'mime' => 'application/vnd.oasis.opendocument.presentation', 'engine' => 'libreoffice'],
        'xlsx' => ['label' => 'XLSX', 'kind' => 'document', 'extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'engine' => 'libreoffice'],
        'ods' => ['label' => 'ODS', 'kind' => 'document', 'extension' => 'ods', 'mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'engine' => 'libreoffice'],
        'mp3' => ['label' => 'MP3', 'kind' => 'audio', 'extension' => 'mp3', 'mime' => 'audio/mpeg', 'engine' => 'ffmpeg'],
        'wav' => ['label' => 'WAV', 'kind' => 'audio', 'extension' => 'wav', 'mime' => 'audio/wav', 'engine' => 'ffmpeg'],
        'ogg' => ['label' => 'OGG', 'kind' => 'audio', 'extension' => 'ogg', 'mime' => 'audio/ogg', 'engine' => 'ffmpeg'],
        'opus' => ['label' => 'Opus', 'kind' => 'audio', 'extension' => 'opus', 'mime' => 'audio/ogg', 'engine' => 'ffmpeg'],
        'aac' => ['label' => 'AAC', 'kind' => 'audio', 'extension' => 'aac', 'mime' => 'audio/aac', 'engine' => 'ffmpeg'],
        'm4a' => ['label' => 'M4A', 'kind' => 'audio', 'extension' => 'm4a', 'mime' => 'audio/mp4', 'engine' => 'ffmpeg'],
        'flac' => ['label' => 'FLAC', 'kind' => 'audio', 'extension' => 'flac', 'mime' => 'audio/flac', 'engine' => 'ffmpeg'],
        'mp4' => ['label' => 'MP4', 'kind' => 'video', 'extension' => 'mp4', 'mime' => 'video/mp4', 'engine' => 'ffmpeg'],
        'm4v' => ['label' => 'M4V', 'kind' => 'video', 'extension' => 'm4v', 'mime' => 'video/x-m4v', 'engine' => 'ffmpeg'],
        'webm' => ['label' => 'WebM', 'kind' => 'video', 'extension' => 'webm', 'mime' => 'video/webm', 'engine' => 'ffmpeg'],
        'mkv' => ['label' => 'MKV', 'kind' => 'video', 'extension' => 'mkv', 'mime' => 'video/x-matroska', 'engine' => 'ffmpeg'],
        'mov' => ['label' => 'MOV', 'kind' => 'video', 'extension' => 'mov', 'mime' => 'video/quicktime', 'engine' => 'ffmpeg'],
        'avi' => ['label' => 'AVI', 'kind' => 'video', 'extension' => 'avi', 'mime' => 'video/x-msvideo', 'engine' => 'ffmpeg'],
        'mpeg' => ['label' => 'MPEG', 'kind' => 'video', 'extension' => 'mpeg', 'mime' => 'video/mpeg', 'engine' => 'ffmpeg'],
        'zip' => ['label' => 'ZIP', 'kind' => 'archive', 'extension' => 'zip', 'mime' => 'application/zip', 'engine' => 'phar'],
        'tar' => ['label' => 'TAR', 'kind' => 'archive', 'extension' => 'tar', 'mime' => 'application/x-tar', 'engine' => 'phar'],
        'tar.gz' => ['label' => 'TAR.GZ', 'kind' => 'archive', 'extension' => 'tar.gz', 'mime' => 'application/gzip', 'engine' => 'phar'],
        'gz' => ['label' => 'GZ', 'kind' => 'archive', 'extension' => 'gz', 'mime' => 'application/gzip', 'engine' => 'zlib', 'note' => 'GZ yalnızca tek dosyalı arşivlerde üretilebilir.'],
        'bz2' => ['label' => 'BZ2', 'kind' => 'archive', 'extension' => 'bz2', 'mime' => 'application/x-bzip2', 'engine' => 'sevenzip'],
        'xz' => ['label' => 'XZ', 'kind' => 'archive', 'extension' => 'xz', 'mime' => 'application/x-xz', 'engine' => 'sevenzip'],
        '7z' => ['label' => '7Z', 'kind' => 'archive', 'extension' => '7z', 'mime' => 'application/x-7z-compressed', 'engine' => 'sevenzip'],
        'extract' => ['label' => 'Tek dosya çıkar', 'kind' => 'archive', 'extension' => '', 'mime' => 'application/octet-stream', 'engine' => 'phar', 'note' => 'Yalnızca arşivde tam olarak bir dosya varsa çalışır.'],
    ];
}

/**
 * Çalışma anındaki gerçek yetenekleri ve gizlenen dönüşümlerin nedenlerini
 * üretir. Arayüz yalnızca burada listelenen hedefleri gösterir.
 *
 * @return array<string, mixed>
 */
function fc_capabilities(): array
{
    static $capabilities = null;

    if (is_array($capabilities)) {
        return $capabilities;
    }

    $registry = fc_format_registry();
    $status = fc_engine_status();
    $images = fc_image_capabilities();
    $hasPhar = class_exists('PharData');
    $hasZlib = extension_loaded('zlib');
    $hasImagick = fc_engine_is_available($status, 'imagick');
    $hasRsvg = fc_engine_is_available($status, 'rsvg');
    $hasLibreOffice = fc_engine_is_available($status, 'libreoffice');
    $hasPdfRender = fc_engine_is_available($status, 'pdftoppm');
    $hasPdfText = fc_engine_is_available($status, 'pdftotext');
    $hasSevenZip = fc_engine_is_available($status, 'sevenzip');
    $hasFfmpeg = fc_engine_is_available($status, 'ffmpeg') && fc_engine_is_available($status, 'ffprobe');
    $encoders = $hasFfmpeg ? fc_ffmpeg_encoders() : [];

    $sources = [];
    $targets = [];

    $addSource = static function (string $key) use (&$sources, $registry): void {
        if (!isset($registry[$key]) || isset($sources[$key])) {
            return;
        }

        $sources[$key] = [
            'label' => $registry[$key]['label'],
            'kind' => $registry[$key]['kind'],
            'extensions' => $registry[$key]['extensions'],
        ];
    };

    $addTarget = static function (string $source, string $target) use (&$targets): void {
        if (!isset($targets[$source])) {
            $targets[$source] = [];
        }

        if (!in_array($target, $targets[$source], true)) {
            $targets[$source][] = $target;
        }
    };

    // Görseller
    $imageTargets = $images['target'];

    if ($hasImagick) {
        $imageTargets[] = 'tiff';
    }

    $imageSources = $images['source'];

    if ($hasImagick) {
        $imageSources[] = 'tiff';
    }

    if ($hasRsvg) {
        $imageSources[] = 'svg';
    }

    foreach (array_unique($imageSources) as $sourceKey) {
        $addSource($sourceKey);

        foreach (array_unique($imageTargets) as $targetKey) {
            if ($targetKey !== $sourceKey) {
                $addTarget($sourceKey, $targetKey);
            }
        }

        $addTarget($sourceKey, 'pdf');
    }

    // PDF
    if ($hasPdfRender && $images['available']) {
        $addSource('pdf');

        foreach (['png', 'jpg', 'webp', 'gif', 'bmp', 'avif'] as $targetKey) {
            if (in_array($targetKey, $images['target'], true)) {
                $addTarget('pdf', $targetKey);
            }
        }

        if ($hasImagick) {
            $addTarget('pdf', 'tiff');
        }
    }

    if ($hasPdfText) {
        $addSource('pdf');
        $addTarget('pdf', 'txt');
    }

    // Ofis belgeleri (LibreOffice)
    if ($hasLibreOffice) {
        $documentTargets = [
            'word' => ['pdf', 'txt', 'html', 'rtf', 'docx', 'odt'],
            'sheet' => ['pdf', 'csv', 'html', 'xlsx', 'ods'],
            'slides' => ['pdf', 'txt', 'html', 'pptx', 'odp'],
        ];

        foreach (['doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'] as $sourceKey) {
            $family = (string) ($registry[$sourceKey]['family'] ?? '');
            $addSource($sourceKey);

            foreach ($documentTargets[$family] ?? [] as $targetKey) {
                if ($targetKey !== $sourceKey) {
                    $addTarget($sourceKey, $targetKey);
                }
            }
        }
    }

    // RTF → metin/HTML her zaman yerleşik çalışır.
    $addSource('rtf');
    $addTarget('rtf', 'txt');
    $addTarget('rtf', 'html');

    // Metin ve veri
    $textTargets = [
        'txt' => ['html'],
        'md' => ['html', 'txt'],
        'html' => ['txt'],
        'json' => ['yaml', 'toml', 'xml', 'csv', 'html'],
        'yaml' => ['json', 'toml', 'xml', 'csv', 'html'],
        'toml' => ['json', 'yaml', 'xml', 'csv', 'html'],
        'xml' => ['json', 'yaml', 'csv', 'html'],
        'csv' => ['json', 'yaml', 'xml', 'html'],
    ];

    foreach ($textTargets as $sourceKey => $list) {
        $addSource($sourceKey);

        foreach ($list as $targetKey) {
            $addTarget($sourceKey, $targetKey);
        }
    }

    if ($hasLibreOffice) {
        foreach (['txt', 'md', 'html', 'csv'] as $sourceKey) {
            foreach ($sourceKey === 'csv' ? ['pdf', 'xlsx', 'ods'] : ['pdf', 'docx', 'odt'] as $targetKey) {
                $addTarget($sourceKey, $targetKey);
            }
        }
    }

    // Ses / video
    $profiles = fc_ffmpeg_profiles();
    $audioTargets = [];
    $videoTargets = [];

    foreach ($profiles as $key => $profile) {
        if (!fc_ffmpeg_profile_available($key, $encoders)) {
            continue;
        }

        if ($profile['kind'] === 'audio') {
            $audioTargets[] = $key;
        } else {
            $videoTargets[] = $key;
        }
    }

    foreach (['mp3', 'wav', 'ogg', 'opus', 'aac', 'm4a', 'flac'] as $sourceKey) {
        $available = array_values(array_filter($audioTargets, static fn (string $key): bool => $key !== $sourceKey));

        if ($available === []) {
            continue;
        }

        $addSource($sourceKey);

        foreach ($available as $targetKey) {
            $addTarget($sourceKey, $targetKey);
        }
    }

    foreach (['mp4', 'm4v', 'webm', 'mkv', 'mov', 'avi', 'mpeg'] as $sourceKey) {
        $available = array_merge(
            array_values(array_filter($videoTargets, static fn (string $key): bool => $key !== $sourceKey)),
            $audioTargets
        );

        if ($available === []) {
            continue;
        }

        $addSource($sourceKey);

        foreach ($available as $targetKey) {
            $addTarget($sourceKey, $targetKey);
        }
    }

    // Arşivler
    $archiveSources = [];

    if ($hasPhar) {
        $archiveSources[] = 'zip';
        $archiveSources[] = 'tar';
    }

    if ($hasZlib) {
        $archiveSources[] = 'gz';
    }

    if ($hasSevenZip) {
        array_push($archiveSources, 'bz2', 'xz', '7z', 'rar');
    }

    $archiveTargets = [];

    if ($hasPhar) {
        $archiveTargets[] = 'zip';
        $archiveTargets[] = 'tar';
    }

    if ($hasPhar && $hasZlib) {
        $archiveTargets[] = 'tar.gz';
        $archiveTargets[] = 'gz';
    }

    if ($hasSevenZip) {
        array_push($archiveTargets, 'bz2', 'xz', '7z');
    }

    foreach ($archiveSources as $sourceKey) {
        $addSource($sourceKey);

        foreach (array_unique($archiveTargets) as $targetKey) {
            if ($targetKey !== $sourceKey) {
                $addTarget($sourceKey, $targetKey);
            }
        }

        $addTarget($sourceKey, 'extract');
    }

    $notes = [];

    if (!$images['available']) {
        $notes[] = 'GD veya fileinfo eklentisi eksik olduğu için görsel dönüşümleri kullanılamıyor.';
    }

    if (!$hasLibreOffice) {
        $notes[] = 'LibreOffice bulunamadığı için DOC/DOCX/ODT/RTF/PPT/PPTX/ODP/XLS/XLSX/ODS belgeleri ve ofis→PDF dönüşümleri gizli.';
    }

    if (!$hasPdfRender && !$hasPdfText) {
        $notes[] = 'Poppler (pdftoppm/pdftotext) bulunamadığı için PDF→görsel ve PDF→metin dönüşümleri gizli.';
    } elseif (!$hasPdfRender) {
        $notes[] = 'pdftoppm bulunamadığı için PDF→görsel dönüşümleri gizli.';
    } elseif (!$hasPdfText) {
        $notes[] = 'pdftotext bulunamadığı için PDF→metin dönüşümü gizli.';
    }

    if ($audioTargets === [] && $videoTargets === []) {
        $notes[] = 'FFmpeg/FFprobe bulunamadığı için ses ve video dönüşümleri gizli.';
    }

    if (!$hasSevenZip) {
        $notes[] = '7-Zip bulunamadığı için BZ2/XZ/7Z/RAR arşivleri gizli.';
    }

    if (!$hasPhar) {
        $notes[] = 'PharData eklentisi olmadığı için ZIP/TAR arşivleri kullanılamıyor.';
    }

    if (!$hasZlib) {
        $notes[] = 'zlib eklentisi olmadığı için GZ arşivleri kullanılamıyor.';
    }

    if (!$hasImagick) {
        $notes[] = 'Imagick bulunamadığı için TIFF kaynak ve hedefi gizli.';
    }

    if (!$hasRsvg) {
        $notes[] = 'rsvg-convert bulunamadığı için SVG rasterleştirmesi gizli.';
    }

    $notes[] = 'PDF dönüşümlerinde yalnızca ilk sayfa işlenir.';
    $notes[] = 'Animasyonlu GIF/WebP/APNG/AVIF reddedilir; tek kareye indirgenmez.';
    $notes[] = 'RAR yalnızca okunabilir; RAR oluşturulmaz.';

    $sourceMap = [];

    foreach (array_keys($sources) as $sourceKey) {
        foreach ($registry[$sourceKey]['extensions'] as $extension) {
            $sourceMap[$extension] = $sourceKey;
        }
    }

    $hasAnyTarget = false;

    foreach ($targets as $list) {
        if ($list !== []) {
            $hasAnyTarget = true;
            break;
        }
    }

    $capabilities = [
        'available' => $sources !== [] && $hasAnyTarget,
        'sources' => $sources,
        'targets' => array_filter($targets, static fn (array $list): bool => $list !== []),
        'meta' => fc_target_meta(),
        'source_map' => $sourceMap,
        'groups' => [
            'image' => 'Görseller',
            'pdf' => 'PDF',
            'document' => 'Belgeler',
            'text' => 'Metin ve veri',
            'audio' => 'Ses',
            'video' => 'Video',
            'archive' => 'Arşivler',
        ],
        'notes' => $notes,
        'engines' => fc_engine_public_summary(),
    ];

    return $capabilities;
}

function fc_target_allowed(string $sourceKey, string $targetKey): bool
{
    $capabilities = fc_capabilities();

    return in_array($targetKey, $capabilities['targets'][$sourceKey] ?? [], true);
}

/**
 * Kullanıcı adını yalnızca indirme dosyası adı olarak, güvenli biçimde kullanır.
 */
function fc_safe_filename(string $name, string $fallback = 'dosya'): string
{
    $name = str_replace(['\\', '/'], ' ', $name);
    $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name, " .\t");

    if ($name === '' || $name === '.' || $name === '..') {
        $name = $fallback;
    }

    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 120, 'UTF-8');
    } else {
        $name = substr($name, 0, 120);
    }

    return $name;
}

function fc_base_name(string $originalName): string
{
    $file = basename(str_replace('\\', '/', $originalName));
    $base = (string) pathinfo($file, PATHINFO_FILENAME);
    $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

    if ($extension === 'gz' && str_ends_with(strtolower($base), '.tar')) {
        $base = substr($base, 0, -4);
    }

    return fc_safe_filename($base !== '' ? $base : 'donusum');
}

function fc_output_name(string $originalName, string $targetKey): string
{
    $meta = fc_target_meta()[$targetKey] ?? null;

    if ($meta === null || $meta['extension'] === '') {
        return fc_safe_filename(basename(str_replace('\\', '/', $originalName)), 'donusum');
    }

    return fc_base_name($originalName) . '.' . $meta['extension'];
}

/**
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_write_bytes(string $destination, string $bytes): array
{
    if (@file_put_contents($destination, $bytes) !== strlen($bytes)) {
        return fc_error_result('storage_unavailable', 'Dönüştürülen dosya yazılamadı.', 500);
    }

    return ['ok' => true, 'bytes' => strlen($bytes)];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_image_source(
    string $path,
    string $sourceKey,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    if (!extension_loaded('gd')) {
        return fc_error_result('engine_missing', 'GD eklentisi olmadığı için görseller dönüştürülemiyor.', 503);
    }

    if ($sourceKey === 'tiff') {
        $decoded = fc_imagick_to_gd($path, $fc);

        if (!($decoded['ok'] ?? false)) {
            return $decoded + ['status' => fc_error_status((string) ($decoded['code'] ?? ''))];
        }

        $image = $decoded['image'];
    } elseif ($sourceKey === 'svg') {
        $decoded = fc_rsvg_to_gd($path, $workspace, $fc);

        if (!($decoded['ok'] ?? false)) {
            return $decoded + ['status' => fc_error_status((string) ($decoded['code'] ?? ''))];
        }

        $image = $decoded['image'];
    } else {
        $image = fc_image_decode($path, $sourceKey, $fc);

        if (is_array($image)) {
            return $image + ['status' => fc_error_status((string) ($image['code'] ?? ''))];
        }
    }

    if ($targetKey === 'pdf') {
        $result = fc_image_to_pdf($image, $destination, $fc);
    } elseif ($targetKey === 'tiff') {
        $result = fc_imagick_write_tiff($image, $destination, $fc);
    } else {
        $result = fc_convert_image($image, $targetKey, $destination, $fc);
    }

    fc_destroy_image($image);

    if (!($result['ok'] ?? false)) {
        return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
    }

    return ['ok' => true, 'bytes' => (int) ($result['bytes'] ?? 0)];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_pdf_source(
    string $path,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    if ($targetKey === 'txt') {
        $result = fc_convert_pdf_to_text($path, $workspace, $fc);

        if (!($result['ok'] ?? false)) {
            return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
        }

        return fc_write_bytes($destination, $result['text']);
    }

    $result = fc_convert_pdf_to_image($path, $targetKey, $destination, $workspace, $fc);

    if (!($result['ok'] ?? false)) {
        return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
    }

    return ['ok' => true, 'bytes' => (int) $result['bytes']];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_document_source(
    string $path,
    string $sourceKey,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    if ($sourceKey === 'rtf' && in_array($targetKey, ['txt', 'html'], true)) {
        return fc_convert_text_source($path, 'rtf', $targetKey, $destination, $workspace, $fc);
    }

    $result = fc_convert_with_libreoffice($path, $targetKey, $destination, $workspace, $fc);

    if (!($result['ok'] ?? false)) {
        return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
    }

    return ['ok' => true, 'bytes' => (int) $result['bytes']];
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, bytes: int}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_text_source(
    string $path,
    string $sourceKey,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    if (in_array($targetKey, ['pdf', 'docx', 'odt', 'xlsx', 'ods'], true)) {
        $status = fc_engine_status();

        if (!fc_engine_is_available($status, 'libreoffice')) {
            return fc_error_result('engine_missing', 'LibreOffice bulunmadığı için bu dönüşüm yapılamıyor.', 503);
        }

        $input = $path;

        if ($sourceKey === 'md') {
            $read = fc_text_read($path, 'md', $fc);

            if (!($read['ok'] ?? false)) {
                return $read + ['status' => fc_error_status((string) ($read['code'] ?? ''))];
            }

            $input = $workspace . '/md-' . bin2hex(random_bytes(6)) . '.html';
            $written = fc_write_bytes($input, fc_markdown_to_html($read['text']));

            if (!($written['ok'] ?? false)) {
                return $written;
            }
        }

        $result = fc_convert_with_libreoffice($input, $targetKey, $destination, $workspace, $fc);

        if (!($result['ok'] ?? false)) {
            return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
        }

        return ['ok' => true, 'bytes' => (int) $result['bytes']];
    }

    $read = fc_text_read($path, $sourceKey, $fc);

    if (!($read['ok'] ?? false)) {
        return $read + ['status' => fc_error_status((string) ($read['code'] ?? ''))];
    }

    $converted = fc_text_convert($read['text'], $sourceKey, $targetKey, $fc);

    if (!($converted['ok'] ?? false)) {
        return $converted + ['status' => fc_error_status((string) ($converted['code'] ?? ''))];
    }

    if (trim($converted['text']) === '') {
        return fc_error_result('empty_output', 'Dönüşüm sonucu boş; bu dosyada aktarılabilir veri yok.', 422);
    }

    return fc_write_bytes($destination, $converted['text']);
}

/**
 * @param array<string, mixed> $fc
 * @return array{ok: true, path: string, bytes: int, name?: string}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_archive_source(
    string $path,
    string $sourceKey,
    string $targetKey,
    string $destination,
    string $workspace,
    array $fc
): array {
    if ($targetKey === 'extract') {
        $extracted = fc_archive_extract($path, $sourceKey, $workspace, $fc);

        if (!($extracted['ok'] ?? false)) {
            return $extracted + ['status' => fc_error_status((string) ($extracted['code'] ?? ''))];
        }

        $name = fc_safe_filename(basename(str_replace('\\', '/', (string) $extracted['name'])), 'dosya');
        $output = $workspace . '/out-' . bin2hex(random_bytes(6)) . '-' . $name;
        $written = fc_write_bytes($output, $extracted['bytes']);

        if (!($written['ok'] ?? false)) {
            return $written;
        }

        return ['ok' => true, 'path' => $output, 'bytes' => (int) $written['bytes'], 'name' => $name];
    }

    $result = fc_archive_convert($path, $sourceKey, $targetKey, $destination, $workspace, $fc);

    if (!($result['ok'] ?? false)) {
        return $result + ['status' => fc_error_status((string) ($result['code'] ?? ''))];
    }

    return ['ok' => true, 'path' => $destination, 'bytes' => (int) $result['bytes']];
}

/**
 * Tek bir kaynak dosyayı isteğe özel çalışma dizininde dönüştürür.
 *
 * @param array<string, mixed> $fc
 * @return array{ok: true, path: string, name: string, mime: string, size: int, source_key: string, target_key: string}|array{ok: false, code: string, message: string, status: int}
 */
function fc_convert_input_path(
    string $sourcePath,
    string $originalName,
    string $targetKey,
    string $workspace,
    array $fc
): array {
    if (!is_dir($workspace)) {
        return fc_error_result('storage_unavailable', 'Geçici çalışma dizini bulunamadı.', 500);
    }

    $meta = fc_target_meta()[$targetKey] ?? null;

    if ($meta === null) {
        return fc_error_result('invalid_target', 'Seçilen hedef biçim geçersiz.', 400);
    }

    $detected = fc_detect_source($sourcePath, $originalName, $fc);

    if (!($detected['ok'] ?? false)) {
        return $detected;
    }

    $sourceKey = (string) $detected['key'];

    if (!fc_target_allowed($sourceKey, $targetKey)) {
        return fc_error_result(
            'unsupported_target',
            ($detected['label'] ?? $sourceKey) . ' kaynağı ' . $meta['label'] . ' biçimine bu sunucuda dönüştürülemiyor.',
            415
        );
    }

    $registry = fc_format_registry();
    $canonical = (string) $registry[$sourceKey]['extension'];
    $input = $sourcePath;

    if (strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION)) !== $canonical) {
        $input = $workspace . '/input-' . bin2hex(random_bytes(6)) . '.' . $canonical;

        if (!@copy($sourcePath, $input)) {
            return fc_error_result('storage_unavailable', 'Geçici giriş dosyası oluşturulamadı.', 500);
        }
    }

    $extension = $targetKey === 'extract' ? '' : (string) $meta['extension'];
    $destination = $workspace . '/out-' . bin2hex(random_bytes(6)) . ($extension !== '' ? '.' . $extension : '');
    $kind = fc_source_kind($sourceKey);

    $result = match ($kind) {
        'image' => fc_convert_image_source($input, $sourceKey, $targetKey, $destination, $workspace, $fc),
        'pdf' => fc_convert_pdf_source($input, $targetKey, $destination, $workspace, $fc),
        'document' => fc_convert_document_source($input, $sourceKey, $targetKey, $destination, $workspace, $fc),
        'text' => fc_convert_text_source($input, $sourceKey, $targetKey, $destination, $workspace, $fc),
        'audio', 'video' => fc_convert_with_ffmpeg($input, $targetKey, $destination, $workspace, $fc),
        'archive' => fc_convert_archive_source($input, $sourceKey, $targetKey, $destination, $workspace, $fc),
        default => fc_error_result('unsupported_source', 'Bu kaynak türü desteklenmiyor.', 415),
    };

    if (!($result['ok'] ?? false)) {
        if (!isset($result['status'])) {
            $result['status'] = fc_error_status((string) ($result['code'] ?? ''));
        }

        return $result;
    }

    $outputPath = (string) ($result['path'] ?? $destination);

    if (!is_file($outputPath)) {
        return fc_error_result('conversion_failed', 'Dönüşüm çıktısı oluşmadı.', 500);
    }

    $size = @filesize($outputPath);
    $limits = fc_limits($fc);

    if ($size === false || $size < 1) {
        return fc_error_result('conversion_failed', 'Dönüşüm çıktısı boş.', 500);
    }

    if ($size > (int) $limits['max_output_bytes']) {
        return fc_error_result('output_too_large', 'Dönüşüm çıktısı izin verilen boyutu aşıyor.', 413);
    }

    $mime = $targetKey === 'extract'
        ? fc_finfo_mime($outputPath)
        : (string) $meta['mime'];

    return [
        'ok' => true,
        'path' => $outputPath,
        'name' => (string) ($result['name'] ?? fc_output_name($originalName, $targetKey)),
        'mime' => $mime !== '' ? $mime : 'application/octet-stream',
        'size' => (int) $size,
        'source_key' => $sourceKey,
        'target_key' => $targetKey,
    ];
}

/**
 * Dönüştürülen dosyayı doğrudan yanıt olarak gönderir; kalıcı bağlantı yoktur.
 */
function fc_send_file(string $path, string $name, string $mime): never
{
    if (!is_file($path) || filesize($path) < 1) {
        fc_json_error(500, 'conversion_failed', 'Dönüşüm çıktısı okunamadı.');
    }

    $name = fc_safe_filename($name, 'donusum');
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'donusum';
    $ascii = str_replace(['"', '\\'], '_', $ascii);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (headers_sent()) {
        exit;
    }

    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Download-Options: noopen');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    @readfile($path);

    fc_workspace_cleanup_all();
    exit;
}
}

/**
 * Tek dosyalık sürümün yapılandırması. Değerler FC_* ortam değişkenleriyle
 * değiştirilebilir; php.ini yükleme sınırları fc_limits() içinde ayrıca uygulanır.
 *
 * @return array{file_converter: array<string, int>}
 */
function fc_standalone_config(): array
{
    $envInt = static function (string $name, int $default, int $minimum = 1): int {
        $value = getenv($name);

        if ($value === false || $value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
            return $default;
        }

        $integer = (int) $value;

        return $integer >= $minimum ? $integer : $default;
    };

    return [
        'file_converter' => [
            'max_file_bytes' => $envInt('FC_MAX_FILE_BYTES', 8 * 1024 * 1024),
            'max_request_bytes' => $envInt('FC_MAX_REQUEST_BYTES', 24 * 1024 * 1024, 1024),
            'max_files_per_request' => $envInt('FC_MAX_FILES', 6, 1),
            'max_image_pixels' => $envInt('FC_MAX_IMAGE_PIXELS', 24_000_000, 10_000),
            'max_text_bytes' => $envInt('FC_MAX_TEXT_BYTES', 8 * 1024 * 1024, 1024),
            'max_output_bytes' => $envInt('FC_MAX_OUTPUT_BYTES', 64 * 1024 * 1024, 1024),
            'process_timeout_seconds' => $envInt('FC_PROCESS_TIMEOUT', 30, 5),
            'office_timeout_seconds' => $envInt('FC_OFFICE_TIMEOUT', 90, 10),
            'pdf_dpi' => $envInt('FC_PDF_DPI', 150, 72),
            'max_archive_entries' => $envInt('FC_MAX_ARCHIVE_ENTRIES', 200, 1),
            'max_archive_entry_bytes' => $envInt('FC_MAX_ARCHIVE_ENTRY_BYTES', 32 * 1024 * 1024, 1024),
            'max_archive_uncompressed_bytes' => $envInt('FC_MAX_ARCHIVE_TOTAL_BYTES', 64 * 1024 * 1024, 1024),
            'max_archive_ratio' => $envInt('FC_MAX_ARCHIVE_RATIO', 200, 10),
        ],
    ];
}

$fcStandaloneConfig = fc_standalone_config();
$fc = $fcStandaloneConfig['file_converter'];

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; " .
        "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; " .
        "connect-src 'self'; object-src 'none'; base-uri 'self'; " .
        "form-action 'self'; frame-ancestors 'none'"
    );
}

$isStandaloneDirect = PHP_SAPI !== 'cli'
    || realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__;

if ($isStandaloneDirect && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');

    $limits = fc_limits($fc);

    if (fc_request_size_exceeded($limits) || fc_request_body_discarded($limits)) {
        header('Connection: close');
        fc_json_error(
            413,
            'request_too_large',
            'İstek boyutu sunucu sınırını aşıyor: en fazla ' . fc_format_bytes($limits['max_request_bytes']) . '.'
        );
    }

    $token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null;

    if (!fc_csrf_valid($token)) {
        fc_json_error(
            403,
            'csrf_failed',
            'Güvenlik doğrulaması başarısız. Sayfayı yenileyip yeniden deneyin; çerezler kapalıysa açın.'
        );
    }

    $target = isset($_POST['target']) && is_string($_POST['target']) ? trim($_POST['target']) : '';

    if ($target === '' || !isset(fc_target_meta()[$target])) {
        fc_json_error(400, 'invalid_target', 'Dönüştürme hedefi geçersiz.');
    }

    $uploads = array_values(array_filter(
        fc_normalize_uploads($_FILES['file'] ?? []),
        static fn (array $upload): bool => $upload['error'] !== UPLOAD_ERR_NO_FILE
    ));

    if ($uploads === []) {
        fc_json_error(400, 'missing_file', 'Dönüştürülecek dosya seçilmedi.');
    }

    if (count($uploads) > $limits['max_files_per_request']) {
        fc_json_error(
            422,
            'too_many_files',
            'Tek istekte en fazla ' . $limits['max_files_per_request'] . ' dosya dönüştürülebilir.'
        );
    }

    $totalBytes = 0;

    foreach ($uploads as $upload) {
        $totalBytes += max(0, $upload['size']);
    }

    if ($totalBytes > $limits['max_request_bytes']) {
        fc_json_error(
            413,
            'request_too_large',
            'Toplam dosya boyutu istek sınırını aşıyor: en fazla ' . fc_format_bytes($limits['max_request_bytes']) . '.'
        );
    }

    $workspaceResult = fc_workspace_create();

    if (!($workspaceResult['ok'] ?? false)) {
        fc_json_error(503, 'storage_unavailable', 'Geçici çalışma alanı hazırlanamadı.');
    }

    $workspace = (string) $workspaceResult['path'];
    $outputs = [];

    foreach ($uploads as $index => $upload) {
        $validation = fc_validate_upload($upload, $limits);

        if (!($validation['ok'] ?? false)) {
            fc_json_error(
                (int) ($validation['status'] ?? 422),
                (string) ($validation['code'] ?? 'upload_failed'),
                (string) ($validation['message'] ?? 'Yükleme doğrulanamadı.'),
                ['file_index' => $index]
            );
        }

        $moved = fc_move_upload($upload['tmp_name'], $workspace);

        if (!($moved['ok'] ?? false)) {
            fc_json_error(
                (int) ($moved['status'] ?? 500),
                (string) ($moved['code'] ?? 'storage_unavailable'),
                (string) ($moved['message'] ?? 'Dosya geçici alana taşınamadı.'),
                ['file_index' => $index]
            );
        }

        try {
            $converted = fc_convert_input_path(
                (string) $moved['path'],
                $upload['name'],
                $target,
                $workspace,
                $fc
            );
        } catch (Throwable $exception) {
            fc_log_exception('convert', $exception);
            fc_json_error(500, 'conversion_failed', 'Dönüşüm sırasında beklenmeyen bir hata oluştu.', [
                'file_index' => $index,
            ]);
        }

        if (!($converted['ok'] ?? false)) {
            fc_json_error(
                (int) ($converted['status'] ?? 422),
                (string) ($converted['code'] ?? 'conversion_failed'),
                (string) ($converted['message'] ?? 'Dönüşüm başarısız oldu.'),
                ['file_index' => $index]
            );
        }

        $outputs[] = $converted;
    }

    if (count($outputs) === 1) {
        $output = $outputs[0];
        fc_send_file((string) $output['path'], (string) $output['name'], (string) $output['mime']);
    }

    if (!class_exists('PharData')) {
        fc_json_error(503, 'engine_missing', 'Birden fazla dosyayı tek yanıtta göndermek için ZIP desteği gerekir.');
    }

    $zipPath = $workspace . '/sonuc-' . bin2hex(random_bytes(6)) . '.zip';
    $files = [];

    foreach ($outputs as $output) {
        $files[] = [
            'name' => fc_safe_filename((string) $output['name']),
            'bytes' => (string) @file_get_contents((string) $output['path']),
        ];
    }

    $zipped = fc_archive_write($files, 'zip', $zipPath, $workspace, $fc);

    if (!($zipped['ok'] ?? false)) {
        fc_json_error(500, 'conversion_failed', 'Dönüştürülen dosyalar tek arşivde paketlenemedi.');
    }

    fc_send_file($zipPath, 'donusum-' . date('Ymd-His') . '.zip', 'application/zip');
}

if ($isStandaloneDirect) {
    header('X-Robots-Tag: noindex, nofollow');
}

$capabilities = fc_capabilities();
$limits = fc_limits($fc);
$csrfToken = $isStandaloneDirect ? fc_csrf_token() : '';
$formAction = (string) ($_SERVER['SCRIPT_NAME'] ?? basename(__FILE__));

$sources = $capabilities['sources'] ?? [];
$targets = $capabilities['targets'] ?? [];
$meta = $capabilities['meta'] ?? [];
$groups = $capabilities['groups'] ?? [];
$notes = $capabilities['notes'] ?? [];
$engines = $capabilities['engines'] ?? ['available' => [], 'missing' => []];
$sourceMap = $capabilities['source_map'] ?? [];
$available = ($capabilities['available'] ?? false) && $sources !== [] && $targets !== [];

$targetSources = [];

foreach ($targets as $sourceKey => $list) {
    foreach ($list as $targetKey) {
        $targetSources[$targetKey][] = $sourceKey;
    }
}

$groupedTargets = [];
$defaultTarget = '';

foreach ($groups as $kind => $groupLabel) {
    foreach ($targetSources as $targetKey => $sourceKeys) {
        $targetMeta = $meta[$targetKey] ?? null;

        if ($targetMeta === null || ($targetMeta['kind'] ?? '') !== $kind) {
            continue;
        }

        $groupedTargets[$kind][] = [
            'key' => $targetKey,
            'meta' => $targetMeta,
            'sources' => $sourceKeys,
        ];

        if ($defaultTarget === '' || $targetKey === 'webp') {
            $defaultTarget = $targetKey;
        }
    }
}

$acceptExtensions = [];

foreach ($sourceMap as $extension => $sourceKey) {
    $acceptExtensions[] = (string) $extension;
}

$acceptExtensions = array_values(array_unique($acceptExtensions));
$accept = implode(',', array_map(static fn (string $extension): string => '.' . $extension, $acceptExtensions));
$maxFileBytes = (int) $limits['max_file_bytes'];
$maxFiles = (int) $limits['max_files_per_request'];
$sourceMapJson = json_encode($sourceMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
$availableEngines = array_values($engines['available'] ?? []);
$missingEngines = array_values($engines['missing'] ?? []);
?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Converter</title>
    <style>
:root {
    --bg: #fbf7ed;
    --surface: #f2ead8;
    --surface-raised: #f6efe0;
    --ink: #282828;
    --ink-soft: #46433d;
    --muted: #6b665c;
    --line: #ded5c3;
    --line-strong: #c8bca6;
    --accent: #a4481f;
    --accent-soft: #f0dccb;
    --olive: #5b6a3d;
    --focus: #8d3a16;
    --selection: #e9d3bf;
    --font-sans: "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
    --font-serif: "Iowan Old Style", "Palatino Linotype", Palatino, "Book Antiqua", Georgia, serif;
    --font-mono: "Cascadia Mono", ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
    --container: 1160px;
    --gutter: clamp(1.25rem, 4vw, 2.5rem);
    --radius: 2px;
    --ease: cubic-bezier(.2, .6, .2, 1);
    --speed: 180ms;
    color-scheme: light;
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0d1117;
        --surface: #161b22;
        --surface-raised: #1b222c;
        --ink: #e6edf3;
        --ink-soft: #c9d1d9;
        --muted: #9aa4af;
        --line: #30363d;
        --line-strong: #3d444d;
        --accent: #79b8ff;
        --accent-soft: #16283c;
        --olive: #8fbf7f;
        --focus: #a5d6ff;
        --selection: #1f3a56;
        color-scheme: dark;
    }
}

*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font-sans);
    font-size: 1rem;
    line-height: 1.65;
    -webkit-font-smoothing: antialiased;
}
main { display: block; }
h1, h2, h3 { color: var(--ink); text-wrap: balance; }
p { margin-top: 0; }
a { color: var(--accent); text-decoration-thickness: 1px; text-underline-offset: .18em; }
[hidden] { display: none !important; }
::selection { background: var(--selection); color: var(--ink); }

.container {
    width: 100%;
    max-width: var(--container);
    margin-inline: auto;
    padding-inline: var(--gutter);
}

.section { padding-block: clamp(2.25rem, 5vw, 3.75rem); }

.row { display: flex; flex-wrap: wrap; margin-inline: -.75rem; }
.row > * { width: 100%; padding-inline: .75rem; }

@media (min-width: 992px) {
    .col-lg-4 { width: 33.3333%; }
    .col-lg-5 { width: 41.6667%; }
    .col-lg-7 { width: 58.3333%; }
    .col-lg-8 { width: 66.6667%; }
    .offset-lg-1 { margin-left: 8.3333%; }
}

.section__label {
    margin-bottom: 1.15rem;
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: .67rem;
    letter-spacing: .14em;
    line-height: 1.5;
    text-transform: uppercase;
}

.section__num { margin-right: .45rem; color: var(--accent); }

.section__title {
    margin: 0 0 1.25rem;
    font-family: var(--font-serif);
    font-size: clamp(2rem, 3.8vw, 3rem);
    font-weight: 400;
    line-height: 1.06;
    letter-spacing: -.03em;
}

.section__intro {
    max-width: 36rem;
    margin-bottom: 1.5rem;
    color: var(--ink-soft);
    font-size: .98rem;
    line-height: 1.7;
}

.visually-hidden {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
}

:where(a, button, [tabindex]):focus-visible {
    outline: 2px solid var(--focus);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
    }
}
/* ==========================================================================
   File Converter — araç arayüzü
   Renkler yalnızca site.css tokenlarından gelir; tema otomatik uyum sağlar.
   ========================================================================== */

.site-nav--tool .site-nav__list {
    gap: 1.5rem;
}

.site-nav__label {
    display: inline-flex;
    align-items: center;
    min-height: 2.75rem;
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: .72rem;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.tool .section__intro {
    max-width: 34rem;
}

.tool-notice {
    margin-top: 2rem;
    padding: 1.35rem 1.4rem;
    border: 1px solid var(--line);
    border-left: 2px solid var(--accent);
    background: var(--surface-raised);
}

.tool-notice__title {
    margin: 0 0 .75rem;
    font-family: var(--font-mono);
    font-size: .74rem;
    font-weight: 600;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--muted);
}

.tool-notice__list {
    margin: 0;
    padding-left: 1.1rem;
    color: var(--ink-soft);
    font-size: .88rem;
    line-height: 1.6;
}

.tool-notice__list li + li {
    margin-top: .3rem;
}

/* Sunucu motorları ve sınırlar ------------------------------------------- */

.tool-meta {
    margin-top: 2rem;
}

.tool-meta__title {
    margin: 0 0 .6rem;
    font-family: var(--font-mono);
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: var(--muted);
}

.tool-meta__line {
    margin: 0 0 .35rem;
    color: var(--ink-soft);
    font-size: .86rem;
    line-height: 1.55;
}

.tool-meta__key {
    display: inline-block;
    min-width: 7.5rem;
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: .72rem;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.tool-notes {
    margin: 0;
    padding-left: 1.1rem;
    color: var(--muted);
    font-size: .82rem;
    line-height: 1.6;
}

.tool-notes li + li {
    margin-top: .25rem;
}

/* Form ------------------------------------------------------------------- */

.tool-form {
    padding: clamp(1.25rem, 3vw, 1.9rem);
    border: 1px solid var(--line);
    background: var(--surface-raised);
}

.tool-field {
    margin-bottom: 1.2rem;
}

.tool-label {
    display: block;
    margin-bottom: .4rem;
    font-family: var(--font-mono);
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: var(--muted);
}

.tool-field__hint {
    margin: .45rem 0 0;
    color: var(--muted);
    font-size: .8rem;
    line-height: 1.5;
}

.tool-select {
    width: 100%;
    min-height: 2.75rem;
    padding: .55rem .7rem;
    border: 1px solid var(--line-strong);
    border-radius: var(--radius);
    background: var(--bg);
    color: var(--ink);
    font: inherit;
    font-size: .92rem;
}

.tool-select:focus-visible {
    outline: 2px solid var(--focus);
    outline-offset: 2px;
}

.dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .35rem;
    min-height: 11rem;
    padding: 2rem 1.25rem;
    border: 1px dashed var(--line-strong);
    border-radius: var(--radius);
    background: var(--bg);
    text-align: center;
    cursor: pointer;
    transition: border-color var(--speed) var(--ease), background var(--speed) var(--ease);
}

.dropzone:hover,
.dropzone:focus-within {
    border-color: var(--accent);
    background: var(--surface);
}

.dropzone.is-dragover {
    border-style: solid;
    border-color: var(--accent);
    background: var(--accent-soft);
}

.dropzone__icon {
    font-family: var(--font-mono);
    font-size: 1.4rem;
    color: var(--accent);
}

.dropzone__title {
    font-size: .98rem;
    font-weight: 600;
    color: var(--ink);
}

.dropzone__hint {
    max-width: 26rem;
    color: var(--muted);
    font-size: .82rem;
    line-height: 1.5;
}

/* Dosya listesi ---------------------------------------------------------- */

.file-list {
    margin: 1.1rem 0 0;
    padding: 0;
    list-style: none;
    border-top: 1px solid var(--line);
}

.file-list:empty {
    border-top: 0;
    margin-top: 0;
}

.file-item {
    padding: .9rem .1rem;
    border-bottom: 1px solid var(--line);
}

.file-item.is-error {
    border-left: 2px solid var(--accent);
    padding-left: .7rem;
}

.file-item__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem .75rem;
}

.file-item__name {
    flex: 1 1 12rem;
    min-width: 0;
    overflow-wrap: anywhere;
    font-size: .92rem;
    font-weight: 600;
}

.file-item__size {
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: .72rem;
}

.file-item__remove {
    min-width: 2.75rem;
    min-height: 2.75rem;
    padding: .3rem .55rem;
    border: 1px solid transparent;
    border-radius: var(--radius);
    background: transparent;
    color: var(--muted);
    font: inherit;
    font-size: .78rem;
    cursor: pointer;
}

.file-item__remove:hover,
.file-item__remove:focus-visible {
    border-color: var(--line-strong);
    color: var(--ink);
}

.file-item__status {
    margin: .35rem 0 0;
    color: var(--ink-soft);
    font-size: .82rem;
    line-height: 1.45;
}

.file-item__status.is-error {
    color: var(--accent);
}

.file-item__download {
    display: inline-flex;
    align-items: center;
    min-height: 2.75rem;
    margin-top: .25rem;
    color: var(--ink);
    font-size: .86rem;
    font-weight: 600;
    text-decoration: underline;
    text-decoration-color: var(--line-strong);
    text-underline-offset: .25em;
}

.file-item__download[hidden] {
    display: none;
}

/* Eylemler ve durum ------------------------------------------------------ */

.tool-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    margin-top: 1.4rem;
}

.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2.75rem;
    padding: .55rem 1.15rem;
    border: 1px solid var(--line-strong);
    border-radius: var(--radius);
    background: transparent;
    color: var(--ink);
    font: inherit;
    font-size: .86rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: background var(--speed) var(--ease), border-color var(--speed) var(--ease), color var(--speed) var(--ease);
}

.button--primary {
    border-color: var(--accent);
    background: var(--accent);
    color: var(--bg);
}

.button--quiet {
    color: var(--ink-soft);
}

.button:disabled {
    cursor: not-allowed;
    opacity: .55;
}

@media (hover: hover) {
    .button:not(:disabled):hover {
        border-color: var(--accent);
        background: var(--surface);
        color: var(--accent);
    }

    .button--primary:not(:disabled):hover {
        background: var(--ink);
        border-color: var(--ink);
        color: var(--bg);
    }
}

.button:focus-visible,
.file-item__remove:focus-visible,
.file-item__download:focus-visible,
.dropzone:focus-within {
    outline: 2px solid var(--focus);
    outline-offset: 2px;
}

.tool-status {
    min-height: 1.4rem;
    margin: 1rem 0 0;
    color: var(--ink-soft);
    font-size: .86rem;
    line-height: 1.5;
}

.tool-status.is-error {
    color: var(--accent);
}

.tool-links {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem 1.75rem;
    margin-top: 1.6rem;
}

.tool-noscript {
    margin-top: 1rem;
    color: var(--muted);
    font-size: .82rem;
    line-height: 1.5;
}

.tool-unavailable {
    padding: 1.5rem;
    border: 1px solid var(--line);
    border-left: 2px solid var(--accent);
    background: var(--surface-raised);
}

.tool-unavailable__title {
    margin: 0 0 .7rem;
    font-family: var(--font-serif);
    font-size: 1.3rem;
}

.tool-unavailable p {
    margin: 0 0 .6rem;
    color: var(--ink-soft);
    font-size: .9rem;
    line-height: 1.6;
}

.tool-unavailable p:last-child {
    margin-bottom: 0;
}

@media (max-width: 575.98px) {
    .tool-form {
        padding: 1.1rem;
    }

    .tool-meta__key {
        display: block;
        min-width: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dropzone,
    .button,
    .file-item__remove {
        transition: none;
    }
}

    </style>
</head>
<body>
<main>
<section class="section tool" aria-labelledby="tool-title">
    <div class="container">
        <div class="row">
            <div class="col-lg-5">
                <p class="section__label"><span class="section__num">Araç</span> File Converter</p>
                <h1 class="section__title" id="tool-title">Dosyaları dönüştür.</h1>
                <p class="section__intro">
                    Görsel, belge, metin, ses, video ve arşiv dosyalarını sunucuda kurulu motorlarla
                    yeniden üretir. Desteklenen biçimler, sunucudaki PHP eklentilerine ve harici
                    araçlara göre belirlenir.
                </p>
                <div class="tool-notice">
                    <h2 class="tool-notice__title">Sunucu tarafında dönüştürme</h2>
                    <ul class="tool-notice__list">
                        <li>Dönüşümler isteğe özel rastgele adlı geçici bir dizinde yapılır; yanıt sonrasında bu dizin temizlenir.</li>
                        <li>Dönüşüm sonucu doğrudan indirme yanıtı olarak gönderilir; kalıcı indirme bağlantısı oluşturulmaz.</li>
                        <li>Dönüşüm bu sunucuda çalışan PHP eklentileri ve harici araçlarla yapılır.</li>
                        <li>Standart web sunucusu erişim günlükleri uygulamanın dışındadır ve barındırma sağlayıcınızın politikasına tabidir.</li>
                    </ul>
                </div>
                <div class="tool-meta">
                    <h2 class="tool-meta__title">Sunucudaki motorlar</h2>
                    <?php if ($availableEngines !== []): ?>
                        <p class="tool-meta__line">
                            <span class="tool-meta__key">Kullanılabilir</span>
                            <?= e(implode(', ', $availableEngines)) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($missingEngines !== []): ?>
                        <p class="tool-meta__line">
                            <span class="tool-meta__key">Bu ortamda yok</span>
                            <?= e(implode(', ', $missingEngines)) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="tool-meta">
                    <h2 class="tool-meta__title">Sınırlar ve davranış</h2>
                    <ul class="tool-notes">
                        <li>Dosya başına en fazla <?= e(fc_format_bytes($maxFileBytes)) ?>; tek istekte en fazla <?= e($maxFiles) ?> dosya.</li>
                        <li>Dosya türü MIME, dosya imzası ve gerçek çözme işlemiyle doğrulanır; yalnızca uzantıya güvenilmez.</li>
                        <li>Her dosya bağımsız işlenir; bir dosyanın hatası diğerlerini etkilemez.</li>
                        <?php foreach ($notes as $note): ?>
                            <li><?= e($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="col-lg-7">
                <?php if (!$available): ?>
                    <div class="tool-unavailable" role="alert" aria-labelledby="tool-unavailable-title">
                        <h2 class="tool-unavailable__title" id="tool-unavailable-title">Bu sunucuda dönüştürme kullanılamıyor.</h2>
                        <p>Gerçekten çalışan bir kaynak/hedef biçim çifti bulunamadı. Eksik PHP eklentileri veya harici motorlar için yukarıdaki listeye bakın.</p>
                        <p>Sahte dönüşüm veya yalnızca uzantı değiştirme yapılmaz; bu nedenle araç devre dışı bırakıldı.</p>
                    </div>
                <?php else: ?>
                    <form
                        class="tool-form"
                        method="post"
                        action="<?= e($formAction) ?>"
                        enctype="multipart/form-data"
                        data-tool-form
                        data-csrf="<?= e($csrfToken) ?>"
                        data-max-file-bytes="<?= e($maxFileBytes) ?>"
                        data-max-files="<?= e($maxFiles) ?>"
                        data-source-map="<?= e($sourceMapJson) ?>"
                    >
                        <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
                        <div class="tool-field">
                            <label class="tool-label" for="tool-target">Hedef biçim</label>
                            <select class="tool-select" id="tool-target" name="target" required aria-describedby="tool-target-hint">
                                <?php foreach ($groupedTargets as $kind => $options): ?>
                                    <optgroup label="<?= e($groups[$kind] ?? $kind) ?>">
                                        <?php foreach ($options as $option): ?>
                                            <option
                                                value="<?= e($option['key']) ?>"
                                                data-sources="<?= e(implode(',', $option['sources'])) ?>"
                                                data-note="<?= e($option['meta']['note'] ?? '') ?>"
                                                <?= $option['key'] === $defaultTarget ? 'selected' : '' ?>
                                            >
                                                <?= e($option['meta']['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="tool-field__hint" id="tool-target-hint" data-target-hint>
                                Hedefler, seçtiğiniz dosyanın gerçek türüne göre sunucuda ayrıca doğrulanır.
                            </p>
                        </div>
                        <label class="dropzone" for="tool-file-input" data-dropzone>
                            <input
                                class="visually-hidden"
                                id="tool-file-input"
                                type="file"
                                name="file[]"
                                accept="<?= e($accept) ?>"
                                multiple
                                data-file-input
                            >
                            <span class="dropzone__icon" aria-hidden="true">&darr;</span>
                            <span class="dropzone__title">Dosyaları buraya bırakın veya seçin</span>
                            <span class="dropzone__hint">
                                Dosya başına en fazla <?= e(fc_format_bytes($maxFileBytes)) ?>
                                &middot; en fazla <?= e($maxFiles) ?> dosya
                                &middot; her dosya ayrı istekte dönüştürülür
                            </span>
                        </label>
                        <ul class="file-list" data-file-list aria-live="polite"></ul>
                        <div class="tool-actions">
                            <button class="button button--primary" type="submit" data-convert disabled>Dönüştür</button>
                            <button class="button button--quiet" type="button" data-clear>Listeyi temizle</button>
                        </div>
                        <p class="tool-status" role="status" aria-live="polite" data-tool-status></p>
                        <noscript>
                            <p class="tool-noscript">
                                JavaScript kapalıyken ilerleme ve dosya başına durum gösterilemez. Form yine de
                                gönderilir; tek dosya doğrudan, birden fazla dosya ZIP olarak indirilir.
                            </p>
                        </noscript>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
</main>
<script>
(function () {
    'use strict';

    var form = document.querySelector('[data-tool-form]');

    if (!form) {
        return;
    }

    var input = form.querySelector('[data-file-input]');
    var select = form.querySelector('#tool-target');
    var dropzone = form.querySelector('[data-dropzone]');
    var list = form.querySelector('[data-file-list]');
    var status = form.querySelector('[data-tool-status]');
    var submit = form.querySelector('[data-convert]');
    var clearButton = form.querySelector('[data-clear]');
    var hint = form.querySelector('[data-target-hint]');

    if (!input || !select || !list || !status || !submit || !clearButton) {
        return;
    }

    var maxBytes = parseInt(form.getAttribute('data-max-file-bytes'), 10) || 0;
    var maxFiles = parseInt(form.getAttribute('data-max-files'), 10) || 1;
    var csrf = form.getAttribute('data-csrf') || '';
    var action = form.getAttribute('action') || 'convert.php';
    var sourceMap = {};
    var hintDefault = hint ? hint.textContent.trim() : '';
    var items = [];
    var running = false;

    try {
        sourceMap = JSON.parse(form.getAttribute('data-source-map') || '{}');
    } catch (error) {
        sourceMap = {};
    }

    function formatBytes(bytes) {
        if (!bytes || bytes < 1024) {
            return (bytes || 0) + ' B';
        }

        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB';
        }

        return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
    }

    function sourceKeyFor(file) {
        var name = (file && file.name ? file.name : '').toLowerCase();

        if (/\.tar\.gz$/.test(name)) {
            return 'gz';
        }

        var dot = name.lastIndexOf('.');
        var extension = dot >= 0 ? name.slice(dot + 1) : '';

        if (extension === 'tgz') {
            return 'gz';
        }

        return sourceMap[extension] || '';
    }

    function currentOption() {
        return select.options[select.selectedIndex] || null;
    }

    function optionSupports(option, key) {
        if (!option || key === '') {
            return true;
        }

        return (option.getAttribute('data-sources') || '').split(',').indexOf(key) !== -1;
    }

    function updateHint() {
        if (!hint) {
            return;
        }

        var option = currentOption();
        var note = option ? (option.getAttribute('data-note') || '') : '';

        hint.textContent = note !== '' ? note : hintDefault;
    }

    function updateTargets() {
        var key = items.length > 0 ? sourceKeyFor(items[0].file) : '';
        var firstAllowed = null;

        Array.prototype.forEach.call(select.options, function (option) {
            var allowed = optionSupports(option, key);
            option.disabled = !allowed;

            if (allowed && firstAllowed === null) {
                firstAllowed = option;
            }
        });

        var option = currentOption();

        if (option && option.disabled && firstAllowed) {
            firstAllowed.selected = true;
        }

        updateHint();
        updateSubmit();
    }

    function updateSubmit() {
        var option = currentOption();
        var usable = items.length > 0 && option !== null && !option.disabled;
        submit.disabled = running || !usable;
        form.setAttribute('aria-busy', running ? 'true' : 'false');
    }

    function setStatus(message, isError) {
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    function triggerDownload(url, name) {
        var link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function filenameFromDisposition(header) {
        if (!header) {
            return '';
        }

        var encoded = /filename\*=UTF-8''([^;]+)/i.exec(header);

        if (encoded && encoded[1]) {
            try {
                return decodeURIComponent(encoded[1].trim());
            } catch (error) {
                return '';
            }
        }

        var quoted = /filename="([^"]+)"/i.exec(header);

        if (quoted && quoted[1]) {
            return quoted[1];
        }

        var loose = /filename=([^;]+)/i.exec(header);

        return loose ? loose[1].trim().replace(/^"|"$/g, '') : '';
    }

    function removeItem(item) {
        if (running) {
            return;
        }

        if (item.url) {
            URL.revokeObjectURL(item.url);
        }

        var index = items.indexOf(item);

        if (index !== -1) {
            items.splice(index, 1);
        }

        if (item.li.parentNode) {
            item.li.parentNode.removeChild(item.li);
        }

        updateTargets();
    }

    function buildItem(file) {
        var li = document.createElement('li');
        li.className = 'file-item';

        var head = document.createElement('div');
        head.className = 'file-item__head';

        var name = document.createElement('span');
        name.className = 'file-item__name';
        name.textContent = file.name;

        var size = document.createElement('span');
        size.className = 'file-item__size';
        size.textContent = formatBytes(file.size);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'file-item__remove';
        remove.setAttribute('aria-label', file.name + ' dosyasını listeden çıkar');
        remove.textContent = 'Kaldır';

        var state = document.createElement('p');
        state.className = 'file-item__status';
        state.textContent = 'Sırada';

        var download = document.createElement('a');
        download.className = 'file-item__download';
        download.hidden = true;

        head.appendChild(name);
        head.appendChild(size);
        head.appendChild(remove);
        li.appendChild(head);
        li.appendChild(state);
        li.appendChild(download);
        list.appendChild(li);

        var item = {
            file: file,
            li: li,
            statusEl: state,
            download: download,
            url: '',
            state: 'queued'
        };

        remove.addEventListener('click', function () {
            removeItem(item);
        });

        return item;
    }

    function addFiles(fileList) {
        var files = Array.prototype.slice.call(fileList || []);

        if (files.length === 0) {
            return;
        }

        for (var index = 0; index < files.length; index++) {
            var file = files[index];

            if (items.length >= maxFiles) {
                setStatus('Tek seferde en fazla ' + maxFiles + ' dosya ekleyebilirsiniz.', true);
                break;
            }

            if (maxBytes > 0 && file.size > maxBytes) {
                setStatus(file.name + ' çok büyük: en fazla ' + formatBytes(maxBytes) + '.', true);
                continue;
            }

            items.push(buildItem(file));
        }

        setStatus('');
        updateTargets();
    }

    function clearAll() {
        if (running) {
            return;
        }

        items.forEach(function (item) {
            if (item.url) {
                URL.revokeObjectURL(item.url);
            }
        });

        items = [];
        list.textContent = '';
        setStatus('');
        updateTargets();
    }

    function convertItem(item) {
        return new Promise(function (resolve) {
            item.state = 'working';
            item.li.classList.remove('is-error');
            item.statusEl.classList.remove('is-error');
            item.statusEl.textContent = 'Dönüştürülüyor…';

            var data = new FormData();
            data.append('csrf', csrf);
            data.append('target', select.value);
            data.append('file', item.file, item.file.name);

            fetch(action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json, application/octet-stream'
                }
            }).then(function (response) {
                var type = response.headers.get('content-type') || '';

                if (!response.ok) {
                    return response.json().then(function (payload) {
                        var message = payload && payload.error && payload.error.message
                            ? payload.error.message
                            : 'Dönüşüm başarısız oldu.';
                        throw new Error(message);
                    }).catch(function (error) {
                        if (error instanceof Error && error.message) {
                            throw error;
                        }

                        throw new Error('Sunucu yanıtı okunamadı (HTTP ' + response.status + ').');
                    });
                }

                if (type.indexOf('application/json') === 0) {
                    throw new Error('Sunucu dosya yerine hata yanıtı döndürdü.');
                }

                var downloadName = filenameFromDisposition(response.headers.get('content-disposition'));

                return response.blob().then(function (blob) {
                    return { blob: blob, name: downloadName || item.file.name };
                });
            }).then(function (result) {
                if (!result.blob || result.blob.size === 0) {
                    throw new Error('Dönüşüm çıktısı boş.');
                }

                var url = URL.createObjectURL(result.blob);
                item.url = url;
                item.state = 'done';
                item.download.href = url;
                item.download.download = result.name;
                item.download.textContent = result.name + ' (' + formatBytes(result.blob.size) + ')';
                item.download.hidden = false;
                item.statusEl.textContent = 'Tamamlandı. İndirme başladı; bağlantı listeyi temizleyene kadar geçerli.';
                triggerDownload(url, result.name);
                resolve(true);
            }).catch(function (error) {
                item.state = 'error';
                item.li.classList.add('is-error');
                item.statusEl.classList.add('is-error');
                item.statusEl.textContent = error && error.message
                    ? error.message
                    : 'Dönüşüm başarısız oldu.';
                resolve(false);
            });
        });
    }

    function runAll() {
        if (running) {
            return;
        }

        var pending = items.filter(function (item) {
            return item.state !== 'done';
        });

        if (pending.length === 0) {
            setStatus('Tüm dosyalar zaten dönüştürüldü.');
            return;
        }

        running = true;
        updateSubmit();

        var index = 0;

        function next() {
            if (index >= pending.length) {
                running = false;
                updateSubmit();

                var failed = items.filter(function (item) {
                    return item.state === 'error';
                }).length;

                if (failed > 0) {
                    setStatus(failed + ' dosya dönüştürülemedi. Ayrıntılar listede.', true);
                } else {
                    setStatus(pending.length + ' dosya dönüştürüldü.');
                }

                return;
            }

            var item = pending[index];
            index += 1;
            setStatus(index + ' / ' + pending.length + ' dönüştürülüyor…');
            convertItem(item).then(next);
        }

        next();
    }

    input.addEventListener('change', function () {
        addFiles(input.files);
        input.value = '';
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (items.length === 0) {
            setStatus('Önce en az bir dosya seçin.', true);
            return;
        }

        runAll();
    });

    clearButton.addEventListener('click', clearAll);

    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            dropzone.addEventListener(name, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files) {
                addFiles(event.dataTransfer.files);
            }
        });
    }

    updateTargets();
    updateSubmit();
})();

</script>
</body>
</html>
