<?php
declare(strict_types=1);
$demo = filter_input(INPUT_GET, 'track', FILTER_VALIDATE_INT);
if (!in_array($demo, [1, 2, 3], true)) {
    http_response_code(404);
    exit;
}
$rate = 16000;
$beat = [1=>0.46, 2=>0.57, 3=>0.38][$demo];
$chords = [[57,60,64,67],[53,57,60,64],[60,64,67,71],[55,59,62,65]];
$notes = [0,2,1,3,0,1,2,3];
$count = (int) ($rate * $beat * 64);
$pcm = '';
for ($i = 0; $i < $count; $i++) {
    $time = $i / $rate;
    $step = $time / $beat;
    $bar = (int) ($step / 4);
    $chord = $chords[($bar + $demo - 1) % 4];
    $half = (int) ($step * 2);
    $phase = $time - $half * $beat / 2;
    $midi = $chord[$notes[$half % 8]] + 12;
    $lead = sin(2 * M_PI * 440 * 2 ** (($midi - 69) / 12) * $phase) * exp(-10 * $phase) * min(1, $phase * 240);
    $pulse = $time - floor($step) * $beat;
    $bass = sin(2 * M_PI * 440 * 2 ** (($chord[0] - 93) / 12) * $pulse) * exp(-6 * $pulse) * min(1, $pulse * 200);
    $fade = min(1, $i / 800, ($count - $i) / 8000);
    $pcm .= pack('v', (int) (($lead * 6500 + $bass * 10500) * $fade));
}
$size = strlen($pcm);
header('Content-Type: audio/wav');
header('Content-Length: ' . ($size + 44));
header('Cache-Control: public, max-age=86400');
echo 'RIFF' . pack('V', $size + 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', $size) . $pcm;
