<?php

/**
 * Точка входа: проверки до загрузки кода, требующего PHP 8.
 * (В одном файле с declare(strict_types=1) проверку версии ставить нельзя.)
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Нужен PHP 8</title></head><body>';
    echo '<h1>Требуется PHP 8.0 или новее</h1>';
    echo '<p>Текущая версия: <code>' . htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
    echo '<p>На хостинге переключите интерпретатор на PHP 8.x (панель: версия PHP для домена).</p>';
    echo '</body></html>';
    exit(1);
}

if (!extension_loaded('curl')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Нет cURL</title></head><body>';
    echo '<h1>Расширение cURL не включено</h1>';
    echo '<p>Включите <code>php-curl</code> в настройках PHP на хостинге.</p>';
    echo '</body></html>';
    exit(1);
}

require __DIR__ . '/proxy.php';
