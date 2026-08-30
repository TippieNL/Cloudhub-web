<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use CloudHub\Services\VideoThumbnailService;

header('Content-Type: text/plain; charset=utf-8');

try {
    $service = new VideoThumbnailService($config);
    $info = $service->diagnostics();
    echo "Cloud File Hub FFmpeg diagnostics\n";
    echo "================================\n\n";
    echo 'Ready: ' . (!empty($info['ready']) ? 'YES' : 'NO') . "\n\n";
    foreach (['ffmpeg', 'ffprobe'] as $tool) {
        $row = $info[$tool];
        echo strtoupper($tool) . "\n";
        echo '  Path:      ' . ($row['path'] ?? '-') . "\n";
        echo '  Available: ' . (!empty($row['available']) ? 'YES' : 'NO') . "\n";
        echo '  Version:   ' . ($row['version'] ?? ($row['error'] ?? '-')) . "\n\n";
    }
    echo "Project bin\n";
    echo '  ' . $info['projectBin']['path'] . "\n";
    echo '  ffmpeg:  ' . (!empty($info['projectBin']['ffmpeg']['executable']) ? 'executable' : 'missing/not executable') . "\n";
    echo '  ffprobe: ' . (!empty($info['projectBin']['ffprobe']['executable']) ? 'executable' : 'missing/not executable') . "\n\n";
    echo "Thumbnail cache\n";
    echo '  Path:     ' . $info['cacheDir']['path'] . "\n";
    echo '  Writable: ' . (!empty($info['cacheDir']['writable']) ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Diagnostic failed: " . $e->getMessage() . "\n";
}
