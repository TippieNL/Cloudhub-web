<?php
declare(strict_types=1);

namespace CloudHub\Services;

use RuntimeException;

/**
 * Describes the browser-based video thumbnail capability.
 *
 * Video frame extraction is intentionally performed by the browser using
 * HTMLVideoElement + Canvas. PHP does not decode video and no external
 * executable is required.
 *
 * This class remains as a small compatibility boundary for older code that
 * expects a video-thumbnail service to exist.
 */
final class VideoThumbnailService
{
    public function __construct(private array $config = [])
    {
    }

    public function diagnostics(): array
    {
        return [
            'ready' => true,
            'mode' => 'browser',
            'decoder' => 'HTMLVideoElement',
            'frameEncoder' => 'Canvas',
            'serverDependencies' => [],
        ];
    }

    public function thumbnail(string $file): string
    {
        throw new RuntimeException(
            'Video thumbnails are generated in the browser and are not stored server-side',
            415
        );
    }

    public function isVideo(string $file): bool
    {
        return in_array(
            strtolower((string)pathinfo($file, PATHINFO_EXTENSION)),
            ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v', 'avi', 'mkv', 'mpeg', 'mpg', '3gp', '3g2', 'ts', 'm2ts', 'mts'],
            true
        );
    }
}
