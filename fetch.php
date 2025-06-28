<?php
// Väljer en stabilare RSS‑Bridge‑instans
$source = 'https://rss-bridge.nixnet.services/?action=display&bridge=GoComicsBridge&comicname=brewsterrockit&format=Atom';
$target = __DIR__ . '/brewsterrockit.xml';

$data = @file_get_contents($source);

if ($data !== false) {
    file_put_contents($target, $data);
    echo "✅ Flöde hämtat!\n";
} else {
    echo "❌ Misslyckades med att hämta flöde från rss-bridge.nixnet.services\n";
    exit(1);
}
