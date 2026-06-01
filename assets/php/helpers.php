<?php
if (!defined('ASSET_VER')) {
    $vf = $_SERVER['DOCUMENT_ROOT'] . '/assets/version.txt';
    define('ASSET_VER', file_exists($vf) ? trim(file_get_contents($vf)) : '1');
}

function asset_url(string $path): string {
    return $path . '?v=' . ASSET_VER;
}
