<?php

declare(strict_types=1);

// Temporary, token-protected tail of the Laravel log for debugging; remove after use.
if (! hash_equals('2c33be3776da607c8566baaa2f99561105f2357508b8680fcb43cba0562d1a2b', hash('sha256', (string) ($_GET['token'] ?? '')))) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
$files = glob(dirname(__DIR__).'/storage/logs/*.log') ?: [];
usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$lines = max(1, min(2000, (int) ($_GET['lines'] ?? 400)));
foreach (array_slice($files, 0, 2) as $file) {
    echo '== ', basename($file), ' (', filesize($file), " bytes)\n";
    $handle = fopen($file, 'r');
    fseek($handle, max(0, filesize($file) - 4000000));
    $tail = array_slice(explode("\n", (string) stream_get_contents($handle)), -$lines);
    echo implode("\n", $tail), "\n";
}
