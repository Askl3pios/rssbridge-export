<?php
// Hämta flödet från din publika RSSBridge (ex. från Fly.io eller annan källa)
$source = 'https://rssbridge-fly.fly.dev/?action=display&bridge=GoComicsBridge&comicname=brewsterrockit&date-in-title=on&limit=10&format=Atom';
$target = __DIR__ . '/brewsterrockit.xml';

$data = file_get_contents($source);

if ($data !== false) {
    file_put_contents($target, $data);
} else {
    echo "Misslyckades med att hämta RSS-flödet.";
    exit(1);
}
