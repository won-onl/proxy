<?php

/**
 * Откройте в браузере: https://won-onl.ru/verify.php
 *
 * Если видите текст «proxy_wononl_ru_ok» — запрос к домену попал на РФ-хостинг с прокси.
 * Если видите страницу FastPanel «Why am I seeing this page?» — DNS won-onl.ru указывает
 * не на тот сервер (часто на IP FastVPS вместо IP РФ), либо PHP не выполняется.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo "proxy_wononl_ru_ok\n";
echo 'HTTP_HOST=' . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo 'REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
