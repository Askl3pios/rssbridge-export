<?php
// Direkt från publik RSS-Bridge (helt utan Fly.io)
$source = 'https://rss-bridge.org/bridge01/?action=display&bridge=GoComicsBridge&comicname=brewsterrockit&format=Atom';
$target = __DIR__ . '/brewsterrockit.xml';

$data = file_get_contents($source);

if ($data !== false) {
    file_put_contents($target, $data);
    echo "Flödet har uppdaterats.\n";
} else {
    echo "Misslyckades med att hämta flödet.\n";
    exit(1);
}
