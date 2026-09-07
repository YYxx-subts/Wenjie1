<?php
/**
 * 复制为 config.local.php 并填写真实值（勿提交仓库）。
 * 生产环境缺少本文件或环境变量时，主站会 fail-closed 拒绝启动。
 */
define('ADMIN_USERNAME', 'feiniao9999admin');
define('ADMIN_PASSWORD', 'Asas5656');
define('API_PASSWORD', 'feiniao9999API');

$db = array(
    'host' => '127.0.0.1',
    'user' => 'wjapp',
    'pass' => 'z9P7YKNAqUZNVXGbZSFm6XcJXm3D',
    'name' => 'wenjie',
);

$wx = array(
    'ID' => '',
    'key' => '',
);
