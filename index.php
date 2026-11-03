<?php
declare(strict_types=1);
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(32));
$storage = __DIR__ . '/storage';
try {
    $db = new PDO('mysql:host=127.0.0.1;dbname=musicx;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $error) {
    http_response_code(503);
    exit('Inicia MySQL en XAMPP e importa schema.sql desde phpMyAdmin. Consulta LEEME.txt.');
}

if (isset($_GET['audio'])) {
    $query = $db->prepare('SELECT filename FROM tracks WHERE id = ?');
    $query->execute([(int) $_GET['audio']]);
    $filename = $query->fetchColumn();
    $path = $storage . '/' . $filename;
    if (!$filename || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    session_write_close();
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) ||
            ($parts[1] === '' && (int) $parts[2] === 0)) {
            header("Content-Range: bytes */$size", true, 416);
            exit;
        }
        $start = $parts[1] === '' ? max(0, $size - (int) $parts[2]) : (int) $parts[1];
        if ($parts[1] !== '' && $parts[2] !== '') $end = min($end, (int) $parts[2]);
        if ($start > $end) {
            header("Content-Range: bytes */$size", true, 416);
            exit;
        }
        header("Content-Range: bytes $start-$end/$size", true, 206);
    }
    $types = ['mp3'=>'audio/mpeg','wav'=>'audio/wav','flac'=>'audio/flac','ogg'=>'audio/ogg','m4a'=>'audio/mp4'];
    header('Content-Type: ' . $types[pathinfo($filename, PATHINFO_EXTENSION)]);
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . ($end - $start + 1));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
    $stream = fopen($path, 'rb');
    fseek($stream, $start);
    while ($start <= $end && !feof($stream) && !connection_aborted()) {
        $chunk = fread($stream, min(65536, $end - $start + 1));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $start += strlen($chunk);
    }
    fclose($stream);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['token'], (string) ($_POST['token'] ?? ''))) {
        http_response_code(400);
        exit('Solicitud inválida o archivo mayor al límite de PHP.');
    }
    if (isset($_POST['favorite'])) {
        $query = $db->prepare('UPDATE tracks SET favorite = NOT favorite WHERE id = ?');
        $query->execute([(int) $_POST['favorite']]);
        exit;
    }
    $file = $_FILES['song'] ?? [];
    $extensions = [
        'mp3'=>['audio/mpeg'], 'wav'=>['audio/wav','audio/x-wav','audio/vnd.wave'],
        'flac'=>['audio/flac','audio/x-flac'], 'ogg'=>['audio/ogg','application/ogg'],
        'm4a'=>['audio/mp4','audio/x-m4a'],
    ];
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $valid = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK &&
        $file['size'] > 0 && $file['size'] <= 50 * 1024 * 1024 &&
        in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), $extensions[$extension] ?? [], true);
    if (!$valid) {
        $_SESSION['message'] = 'Selecciona un audio compatible dentro del límite de carga.';
    } else {
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $target = $storage . '/' . $filename;
        try {
            if (!is_dir($storage) && !mkdir($storage) && !is_dir($storage)) throw new RuntimeException();
            if (!is_file($storage . '/.htaccess') && !copy(__DIR__ . '/storage.htaccess', $storage . '/.htaccess')) {
                throw new RuntimeException();
            }
            if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException();
            $title = trim($_POST['title'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
            $query = $db->prepare('INSERT INTO tracks (title, artist, filename) VALUES (?, ?, ?)');
            $query->execute([mb_substr($title, 0, 255), mb_substr(trim($_POST['artist'] ?? ''), 0, 255), $filename]);
            $_SESSION['message'] = 'Canción añadida a tu biblioteca.';
        } catch (Throwable $error) {
            if (is_file($target)) unlink($target);
            $_SESSION['message'] = 'No se pudo guardar la canción.';
        }
    }
    header('Location: ./', true, 303);
    exit;
}
$tracks = $db->query('SELECT id,title,artist,favorite FROM tracks ORDER BY id DESC')->fetchAll();
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MusicX | Tu música</title>
<link rel="stylesheet" href="musicx.css">
<body>
<aside><a class="brand" href="./"><i>♫</i> MusicX</a><small>COLECCIÓN</small><button data-view="all" aria-pressed="true">▤ &nbsp; Biblioteca</button><button data-view="liked" aria-pressed="false">♡ &nbsp; Favoritos</button><button data-view="queue" aria-pressed="false">≡ &nbsp; Cola</button><button data-view="discover" aria-pressed="false">⌕ &nbsp; Descubrir</button><a class="roadmap" href="roadmap.html">Roadmap ↗</a></aside>
<main>
<header><input id="filter" type="search" placeholder="Buscar en tu biblioteca" aria-label="Buscar en tu biblioteca"><button id="import" class="primary">＋ Importar canción</button></header>
<section class="hero"><div><small>TU ESPACIO MUSICAL</small><h1>En tu frecuencia.</h1><p>Tu colección, lista para sonar.</p></div><div class="vinyl" aria-hidden="true"></div></section>
<p id="notice" role="status"><?=escape($message)?></p>
<section id="collection"><div class="section-title"><h2 id="heading">Tu biblioteca</h2><span id="count"></span></div><div id="tracks"></div></section>
<section id="discover" hidden><h2>Descubrir en Audius</h2><form id="search"><input id="query" required aria-label="Canción o artista" placeholder="Canción o artista"><button class="primary">Buscar</button></form><p id="search-status" role="status"></p><div id="results"></div></section>
</main>
<dialog id="upload"><form method="post" enctype="multipart/form-data">
<h2>Importar canción</h2><p>MP3, WAV, FLAC, OGG o M4A.</p>
<input type="hidden" name="token" value="<?=escape($_SESSION['token'])?>">
<label>Archivo de audio<input type="file" name="song" accept=".mp3,.wav,.flac,.ogg,.m4a" required></label>
<label>Título<input name="title" maxlength="255" placeholder="Nombre del archivo"></label>
<label>Artista<input name="artist" maxlength="255" placeholder="Opcional"></label>
<div class="actions"><button type="button" id="cancel">Cancelar</button><button class="primary">Guardar canción</button></div>
</form></dialog>
<footer><div><b id="playing">Sin reproducción</b><small id="artist">MusicX</small></div><audio id="audio" controls preload="none"></audio><div class="actions"><button id="shuffle" aria-pressed="false" aria-label="Aleatorio">⤨</button><button id="repeat" aria-pressed="false" aria-label="Repetir">↻</button><button id="next" aria-label="Siguiente">▶▏</button></div></footer>
<script id="data" type="application/json"><?=json_encode(['tracks'=>$tracks,'token'=>$_SESSION['token']], JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<script src="musicx.js" defer></script>
</body>
</html>
