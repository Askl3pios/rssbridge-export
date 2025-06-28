<?php

function fetchGoComics($comic, $title) {
    $rssItems = [];
    $today = new DateTimeImmutable();
    $client = stream_context_create([
        'http' => ['header' => 'User-Agent: Mozilla/5.0']
    ]);

    for ($i = 0; $i < 7; $i++) {
        $date = $today->modify("-$i days")->format('Y/m/d');
        $displayDate = $today->modify("-$i days")->format('Y-m-d');
        $url = "https://www.gocomics.com/$comic/$date";

        $html = @file_get_contents($url, false, $client);
        if ($html === false) continue;

        // Extrahera bild-URL
        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $matches)) {
            $imgUrl = $matches[1];
        } else {
            continue;
        }

        // Bygg <entry>
        $entry = <<<XML
  <entry>
    <title>{$title} – {$displa
