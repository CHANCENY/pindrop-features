<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\binary;

final class YTBinary
{
    private static ?string $binaryPath = null;

    public static function getBinaryPath(?string $binaries_directory = null): string
    {
        if (empty($binaries_directory)) {
            self::$binaryPath = $_ENV['PLUGIN_ROOT']. DIRECTORY_SEPARATOR . 
            "yt_dlp_downloader". DIRECTORY_SEPARATOR . "bin";
        }
        else {
            self::$binaryPath = $binaries_directory;
        }

        // resolve the binary path based on the operating system
        $os = strtolower(PHP_OS_FAMILY);
        return self::resolveBinaryPath($os);
    }

    private static function resolveBinaryPath(string $os): string
    {
        // we have these binaries in the bin folder for each OS
        // yt-dlp_arm64.exe, yt-dlp_linux, yt-dlp_linux_aarch64,yt-dlp_macos,yt-dlp_musllinux,yt-dlp_x86.exe

        switch ($os) {
            case 'windows':
                return self::$binaryPath . DIRECTORY_SEPARATOR . 'yt-dlp_x86.exe';
            case 'linux':
                return self::$binaryPath . DIRECTORY_SEPARATOR . 'yt-dlp_linux';
            case 'darwin':
                return self::$binaryPath . DIRECTORY_SEPARATOR . 'yt-dlp_macos';
            default:
                throw new \RuntimeException("Unsupported operating system: $os");
        }
    }


}
