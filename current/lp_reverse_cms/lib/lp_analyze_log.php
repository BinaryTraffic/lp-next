<?php

declare(strict_types=1);

/**
 * LP 構造解析まわりのサーバー側ログ（Git 管理外 data/ 配下へ追記）。
 *
 * @param array<string, mixed> $context
 */
function lp_reverse_analyze_append_log(string $dataDir, string $level, string $message, array $context = []): void
{
    $path = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'lp_structure_analyze.log';
    $rec  = [
        'ts'      => date('c'),
        'level'   => $level,
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
