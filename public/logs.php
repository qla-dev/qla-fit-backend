<?php

declare(strict_types=1);

// Temporary, token-protected tail of the Laravel log for debugging; remove after use.
if (! hash_equals('1ad2a686e35542250a7531f61bb7ad5807a52349d68b6abb79d46a45f356728f', hash('sha256', (string) ($_GET['token'] ?? '')))) {
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
