<?php


return [
    "download" => "download_file"
];


function download_file(\CLIPrinter $printer, ...$values)
{
    $url = param("url");
    $downloaddir = param("dist");

    if (!$url) {
        $printer->printLine("Missing required parameter: url", "red");
        return 1;
    }

    if (!$downloaddir) {
        $printer->printLine("Missing required parameter: dist proceeding with {$_ENV['PUBLIC_STREAM_DIR']}", "yellow");
        $downloaddir = $_ENV['PUBLIC_STREAM_DIR'];
    }

    $printer->printLine("Downloading $url to $downloaddir ...", "bright_cyan");
    $printer->printLine("","");

    $path = curl_get_download($url, $downloaddir, $printer);

    $printer->printLine("","");
    $printer->printLine("Download complete [{$path}]", 'green');

}

function curl_get_download(string $url, string $downloaddir, \CLIPrinter $printer): string|false
{
    if (!is_dir($downloaddir) && !mkdir($downloaddir, 0775, true)) {
        $printer->printLine("Cannot create directory: $downloaddir", 'red');
        return false;
    }

    $headers     = [];      // headers of the final response (lowercased names)
    $fp          = null;
    $partPath    = null;
    $finalPath   = null;
    $lastPercent = -1;
    $barWidth    = max(10, $printer->getTerminalWidth() - 5 - 1 - 24 - 1); // fixed width, label is ~24 chars max

    // Human readable sizes
    $formatBytes = function (float $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    };

    // Work out the filename from Content-Disposition, then the URL
    $resolveFilename = function (array $headers, string $effectiveUrl) use ($url): string {
        $name = null;

        if (!empty($headers['content-disposition'])) {
            $cd = $headers['content-disposition'];
            if (preg_match("/filename\*\s*=\s*[^']*'[^']*'([^;]+)/i", $cd, $m)) {
                $name = rawurldecode(trim($m[1], " \"'"));
            } elseif (preg_match('/filename\s*=\s*"([^"]+)"/i', $cd, $m)) {
                $name = $m[1];
            } elseif (preg_match('/filename\s*=\s*([^;]+)/i', $cd, $m)) {
                $name = trim($m[1], " \"'");
            }
        }

        if (!$name) {
            $path = parse_url($effectiveUrl ?: $url, PHP_URL_PATH) ?: '';
            $name = rawurldecode(basename($path));
        }

        // Sanitize: no paths, no control characters
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/', '_', $name);

        return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'download_' . date('Ymd_His');
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_FAILONERROR    => true,     // HTTP 4xx/5xx => error, nothing written
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_NOPROGRESS     => false,

        // Collect headers (reset on every new response, e.g. after a redirect)
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $len = strlen($line);
            if (stripos($line, 'HTTP/') === 0) {
                $headers = [];
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return $len;
        },

        // Open the file on the first chunk, when headers are known
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
            &$fp, &$partPath, &$finalPath, &$headers, $downloaddir, $resolveFilename
        ) {
            if ($fp === null) {
                $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $filename  = $resolveFilename($headers, $effective);

                // Do not overwrite existing files: file.zip -> file (1).zip
                $info = pathinfo($filename);
                $base = $info['filename'];
                $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
                $i = 1;
                $finalPath = rtrim($downloaddir, '/\\') . DIRECTORY_SEPARATOR . $filename;
                while (file_exists($finalPath)) {
                    $finalPath = rtrim($downloaddir, '/\\') . DIRECTORY_SEPARATOR . "$base ($i)$ext";
                    $i++;
                }

                $partPath = $finalPath . '.part';
                $fp = fopen($partPath, 'wb');
                if ($fp === false) {
                    return 0; // abort
                }
            }
            return fwrite($fp, $data);
        },

        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use (
            $printer, $formatBytes, $barWidth, &$lastPercent, &$headers
        ) {
            // Fallback to Content-Length header if cURL doesn't know the total yet
            if ($dlTotal <= 0 && isset($headers['content-length'])) {
                $dlTotal = (float) $headers['content-length'];
            }

            if ($dlTotal <= 0) {
                return 0; // unknown size, nothing to calculate
            }

            $percent = (int) floor($dlNow / $dlTotal * 100);
            if ($percent === $lastPercent) {
                return 0; // avoid redrawing on every callback
            }
            $lastPercent = $percent;

            $printer->printProgress(
                $dlNow,
                0,
                $dlTotal,
                $barWidth,
                $formatBytes($dlNow) . ' / ' . $formatBytes($dlTotal)
            );

            return 0; // non-zero would abort the download
        },
    ]);

    $ok    = curl_exec($ch);
    $error = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
   
    if ($fp !== null) {
        fclose($fp);
    }

    if ($ok === false) {
        if ($partPath !== null && file_exists($partPath)) {
            unlink($partPath);   // remove incomplete file
        }
        echo PHP_EOL;            // leave the progress line
        $printer->printLine("Download failed: $error (HTTP $code)", 'red');
        return false;
    }

    // Empty body: nothing was written, so no file was opened
    if ($partPath === null) {
        $printer->printLine('Download finished but the response was empty.', 'yellow');
        return false;
    }

    if (!rename($partPath, $finalPath)) {
        $printer->printLine("Could not move file to $finalPath", 'red');
        return false;
    }

    $printer->printLine('Saved: ' . $finalPath, 'bright_green');
    return $finalPath;
}