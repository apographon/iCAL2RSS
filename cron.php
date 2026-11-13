<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

if (!$isCli && !ir_cron_authorized()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit(1);
}

@set_time_limit(120);
$result = ir_run_all();

if ($isCli) {
    foreach ($result['results'] as $row) {
        $flag = !empty($row['ok']) ? 'OK' : 'FAIL';
        $detail = !empty($row['ok'])
            ? ($row['events'] . ' events')
            : (string) ($row['error'] ?? 'error');
        echo $flag . ' ' . $row['id'] . ' — ' . $detail . "\n";
    }
    echo 'done ' . $result['ran_at'] . "\n";
    exit($result['ok'] ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit($result['ok'] ? 0 : 1);
