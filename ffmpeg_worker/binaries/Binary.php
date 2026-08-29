<?php

/**
 * Represents a binary executable that can be utilized within the application.
 * This class serves as a base for handling binaries and their functionality.
 * It can be extended to include methods for interacting with specific binary executables.
 */

namespace Simp\Pindrop\Modules\ffmpeg_worker\binaries;

class Binary
{
    protected string $ffmpegPath = '';
    protected string $ffprobePath = '';

    public function __construct(?string $arch = null)
    {
        $this->detectSystem($arch);

        if (empty($this->ffmpegPath) || empty($this->ffprobePath)) {
            throw new \RuntimeException("Failed to detect system architecture");
        }

        $this->ensureExecutable($this->ffmpegPath);
        $this->ensureExecutable($this->ffprobePath);
    }

    /**
     * Get FFmpeg binary path
     */
    public function getFFmpeg(): string
    {
        return $this->ffmpegPath;
    }

    /**
     * Get FFprobe binary path
     */
    public function getFFprobe(): string
    {
        return $this->ffprobePath;
    }

    /**
     * Detect system architecture and set proper binaries
     * @param string|null $arch
     */
    protected function detectSystem(?string $arch = null): void
    {
        $arch = empty($arch) ? $this->getArch() : $arch;
        $basePath = __DIR__ . '/../ffmpeg/';

        switch ($arch) {
            case 'x86_64':
                $this->ffmpegPath = $basePath . 'ffmpeg-release-amd64-static/ffmpeg-7.0.2-amd64-static/ffmpeg';
                $this->ffprobePath = $basePath . 'ffmpeg-release-amd64-static/ffmpeg-7.0.2-amd64-static/ffprobe';
                break;
            case 'i686':
            case 'i386':
                $this->ffmpegPath = $basePath . 'ffmpeg-release-i686-static/ffmpeg-7.0.2-i686-static/ffmpeg';
                $this->ffprobePath = $basePath . 'ffmpeg-release-i686-static/ffmpeg-7.0.2-i686-static/ffprobe';
                break;
            case 'aarch64':
                $this->ffmpegPath = $basePath . 'ffmpeg-release-arm64-static/ffmpeg-7.0.2-arm64-static/ffmpeg';
                $this->ffprobePath = $basePath . 'ffmpeg-release-arm64-static/ffmpeg-7.0.2-arm64-static/ffprobe';
                break;
            case 'armv7l':
                $this->ffmpegPath = $basePath . 'ffmpeg-release-armhf-static/ffmpeg-7.0.2-armhf-static/ffmpeg';
                $this->ffprobePath = $basePath . 'ffmpeg-release-armhf-static/ffmpeg-7.0.2-armhf-static/ffprobe';
                break;
            case 'arm':
            case 'armel':
                $this->ffmpegPath = $basePath . 'ffmpeg-release-armel-static/ffmpeg-7.0.2-armel-static/ffmpeg';
                $this->ffprobePath = $basePath . 'ffmpeg-release-armel-static/ffmpeg-7.0.2-armel-static/ffprobe';
                break;
            default:
                throw new \RuntimeException("Unsupported architecture: {$arch}");
        }
    }

    /**
     * Get system architecture
     */
    protected function getArch(): string
    {
        return php_uname('m');
    }

    /**
     * Ensure the binary is executable, try to chmod if not
     */
    protected function ensureExecutable(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Binary not found: {$path}");
        }

        if (!is_executable($path)) {
            if (!chmod($path, 0755)) {
                throw new \RuntimeException("Failed to make binary executable: {$path}");
            }
        }
    }

    /**
     * Builds a new instance of the class with the specified architecture.
     *
     * @param string|null $arch The architecture for which the instance should be built.
     * @return self A new instance of the class configured for the specified architecture.
     */
    public static function build(?string $arch = null): Binary
    {
        return new self($arch);
    }


}