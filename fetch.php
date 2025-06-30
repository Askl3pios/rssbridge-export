<?php
function fetchGoComics($comic, $title) {
    $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $entries = [];
    $previousImg = null;

    for ($i = 0; $i < 7; $i++) {
        $dateObj = $today->modify("-$i days");
        $date = $dateObj->format('Y/m/d');
        $entryDate = $dateObj->format('Y-m-d');
        $url = "https://www.gocomics.com/{$comic}/$date";

        $html = @file_get_contents($url);
        if ($html === false) continue;

        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $match)) {
            $imgUrl = $match[1];
            if ($i === 0 && $imgUrl === $previousImg) continue;
            $previousImg = $imgUrl;
        } else {
            continue;
        }

        $entryLink = "https://www.gocomics.com/{$comic}/$entryDate";

        $entries[] = [
            'title' => "$title – $entryDate",
            'link' => $entryLink,
            'updated' => $entryDate . "T00:00:00Z",
            'id' => $entryLink,
            'img' => $imgUrl
        ];
    }

    if (empty($entries)) return;

    $rssFeed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:atom="http://www.w3.org/2005/Atom">
  <title>{$title}</title>
  <link href="https://www.gocomics.com/{$comic}"/>
  <atom:link href="https://askl3pios.github.io/rssbridge-export/{$comic}.xml" rel="self" type="application/atom+xml"/>
  <updated>{$entries[0]['updated']}</updated>
  <id>https://www.gocomics.com/{$comic}</id>

XML;

    foreach ($entries as $entry) {
        $rssFeed .= <<<ENTRY
  <entry>
    <title>{$entry['title']}</title>
    <link href="{$entry['link']}"/>
    <id>{$entry['id']}</id>
    <author><name>{$title}</name></author>
    <updated>{$entry['updated']}</updated>
    <content type="html"><![CDATA[<img src="{$entry['img']}" alt="{$title}" />]]></content>
  </entry>

ENTRY;
    }

    $rssFeed .= "\n<!-- Uppdaterad: " . date('c') . " -->\n";
    $rssFeed .= "</feed>\n";

    file_put_contents(__DIR__ . "/{$comic}.xml", $rssFeed);
}

// 👇 Lägg till dina comics här
fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', 'Sherman’s Lagoon');
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');
