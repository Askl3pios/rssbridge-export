<?php
function fetchGoComics($comic, $title) {
    $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $entries = [];

    echo "\n📰 Bearbetar $comic...\n";

    for ($i = 0; $i < 7; $i++) {
        $date = $today->modify("-$i days")->format('Y/m/d');
        $url = "https://www.gocomics.com/{$comic}/$date";

        echo "🔗 Hämtar $url... ";

        $html = @file_get_contents($url);
        if ($html === false) {
            echo "❌ kunde inte ladda\n";
            continue;
        }

        // Försök hitta bild-URL från meta-taggen
        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $match)) {
            $imgUrl = $match[1];
            echo "✅ bild hittad\n";
        } else {
            echo "⚠️ ingen bild hittades\n";
            continue;
        }

        $entryDate = $today->modify("-$i days")->format('Y-m-d');
        $entryLink = "https://www.gocomics.com/{$comic}/$entryDate";

        $entries[] = [
            'title' => "$title – $entryDate",
            'link' => $entryLink,
            'updated' => $entryDate . "T00:00:00Z",
            'id' => $entryLink,
            'img' => $imgUrl
        ];
    }

    if (empty($entries)) {
        echo "⚠️ Inga strippar hittades för $comic\n";
        return;
    }

    // Bygg RSS-flöde (Atom-format)
    $rssFeed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$title}</title>
  <link href="https://www.gocomics.com/{$comic}"/>
  <updated>{$entries[0]['updated']}</updated>
  <id>https://www.gocomics.com/{$comic}</id>

XML;

    foreach ($entries as $entry) {
        $rssFeed .= <<<ENTRY
  <entry>
    <title>{$entry['title']}</title>
    <link href="{$entry['link']}"/>
    <id>{$entry['id']}</id>
    <updated>{$entry['updated']}</updated>
    <content type="html">
      <![CDATA[<img src="{$entry['img']}" alt="{$title}" />]]>
    </content>
  </entry>

ENTRY;
    }

    // Tvinga ändring så att git alltid känner av uppdatering
    $rssFeed .= "\n<!-- Uppdaterad: " . date('c') . " -->\n";
    $rssFeed .= "</feed>\n";

    file_put_contents(__DIR__ . "/{$comic}.xml", $rssFeed);
    echo "✏️ Sparade {$comic}.xml (" . strlen($rssFeed) . " bytes)\n";
}

// Lägg till dina serier här:
fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', 'Sherman’s Lagoon');
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');
