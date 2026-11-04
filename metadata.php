<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!hash_equals($_SESSION['token'] ?? '-', (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    exit('{}');
}
session_write_close();
$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
$artist = mb_substr(trim((string) ($_POST['artist'] ?? '')), 0, 255);
if ($title === '') {
    http_response_code(400);
    exit('{}');
}
$lock = fopen(sys_get_temp_dir() . '/musicx-musicbrainz.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    http_response_code(429);
    exit('{}');
}
function phrase(string $value): string {
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}
$query = 'recording:' . phrase($title) . ($artist === '' ? '' : ' AND artist:' . phrase($artist));
$url = 'https://musicbrainz.org/ws/2/recording?' . http_build_query(['query'=>$query,'fmt'=>'json','limit'=>5]);
$context = stream_context_create(['http'=>[
    'timeout'=>7, 'follow_location'=>0,
    'header'=>"User-Agent: MusicX/1.1 (https://github.com/AVR1515/MusicX)\r\nAccept: application/json\r\n",
]]);
$start = microtime(true);
$result = @file_get_contents($url, false, $context);
usleep((int) max(0, (1 - (microtime(true) - $start)) * 1000000));
fclose($lock);
$data = $result === false ? null : json_decode($result, true);
if (!isset($data['recordings'])) {
    http_response_code(502);
    exit('{}');
}
echo json_encode($data['recordings'], JSON_INVALID_UTF8_SUBSTITUTE);
