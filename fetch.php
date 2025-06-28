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
    <title>{$title} – {$displayDate}</title>
    <link href="{$url}"/>
    <id>{$url}</id>
    <updated>{$displayDate}T00:00:00Z</updated>
    <content type="html"><![CDATA[<img src="{$imgUrl}" alt="{$title}" />]]></content>
  </entry>
XML;

        $rssItems[] = $entry;
    }

    $rssBody = implode("\n", $rssItems);

    $rssFeed = <<<ATOM
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$title}</title>
  <link href="https://www.gocomics.com/{$comic}"/>
  <updated>{$today->format('Y-m-d')}T00:00:00Z</updated>
  <id>https://www.gocomics.com/{$comic}</id>
  {$rssBody}
</feed>
ATOM;

    file_put_contents(__DIR__ . "/{$comic}.xml", $rssFeed);
    echo "✅ Genererat: {$comic}.xml\n";
}

// Generera båda flödena
fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', 'Sherman’s Lagoon');
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');
