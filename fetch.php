<?php

// Konfigurera standard tidszon för DateTime-objekt
date_default_timezone_set('UTC');

/**
 * Hämtar och skapar ett RSS/Atom-flöde för en GoComics-serie.
 *
 * @param string $comic GoComics slug (t.ex. 'brewsterrockit')
 * @param string $title Den fullständiga titeln på serien (t.ex. 'Brewster Rockit')
 */
function fetchGoComics($comic, $title) {
    // Sökväg till den befintliga RSS-filen
    $filePath = __DIR__ . "/{$comic}.xml";
    $existingEntries = [];

    // Försök att ladda befintliga poster från XML-filen för att undvika dubbletter
    if (file_exists($filePath)) {
        $xml = @simplexml_load_file($filePath);
        if ($xml !== false) {
            foreach ($xml->entry as $entry) {
                $existingEntries[(string)$entry->id] = true;
            }
        }
    }

    $newEntries = [];
    $processedImageUrls = []; // För att hantera fallet där GoComics visar samma bild flera dagar i rad

    // Iterera bakåt i tiden för att hitta de senaste serierna (upp till 14 dagar för att vara säker)
    for ($i = 0; $i < 14; $i++) {
        $dateObj = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-$i days");
        $datePath = $dateObj->format('Y/m/d'); // Format för URL: 2023/10/26
        $entryDate = $dateObj->format('Y-m-d'); // Format för intern användning och ID

        $url = "https://www.gocomics.com/{$comic}/$datePath";
        $html = @file_get_contents($url);

        if ($html === false) {
            // Logga felet men fortsätt
            // error_log("Kunde inte hämta URL: $url");
            continue;
        }

        // Försök att extrahera bild-URL från og:image meta-taggen
        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $match)) {
            $imgUrl = $match[1];

            // Vissa serier kan visa samma bild under flera dagar om det inte finns någon ny
            // Vi vill bara inkludera unika bilder
            if (isset($processedImageUrls[$imgUrl])) {
                continue; // Har redan hanterat denna bild
            }
            $processedImageUrls[$imgUrl] = true;

            $entryLink = "https://www.gocomics.com/{$comic}/$datePath"; // Länk till dagens seriestrip
            $entryId = $entryLink; // Unikt ID för posten

            // Kontrollera om denna post redan finns i den befintliga filen
            if (isset($existingEntries[$entryId])) {
                // Vi har nått poster som redan finns i flödet, så vi kan sluta leta bakåt
                break;
            }

            // Bygg upp en post
            $newEntries[] = [
                'title' => htmlspecialchars("$title - $entryDate"), // HTML-escapa titeln
                'link' => htmlspecialchars($entryLink), // HTML-escapa länken
                'updated' => $dateObj->format(DateTime::ATOM), // ATOM-format för datum
                'id' => htmlspecialchars($entryId), // HTML-escapa ID
                'img' => htmlspecialchars($imgUrl) // HTML-escapa bild-URL
            ];
        } else {
            // error_log("Kunde inte hitta og:image för $url");
        }
    }

    // Vänd arrayen så de nyaste serierna kommer först (viktigt för RSS/Atom)
    $newEntries = array_reverse($newEntries);

    // Om inga nya poster hittades, gör inget
    if (empty($newEntries)) {
        // Om filen inte existerar och inga nya poster hittades, skriv en tom feed-struktur
        // Annars, rör inte den befintliga filen
        if (!file_exists($filePath)) {
            $rssFeed = createAtomFeedHeader($comic, $title, date(DateTime::ATOM));
            $rssFeed .= "</feed>\n";
            file_put_contents($filePath, $rssFeed);
        }
        return;
    }

    // Läs in befintligt flöde för att lägga till nya poster i början
    $currentFeedContent = file_exists($filePath) ? file_get_contents($filePath) : '';
    $existingFeedBody = '';

    // Hitta var 'feed'-taggen slutar för att infoga nya poster
    if (str_contains($currentFeedContent, '</feed>')) {
        $existingFeedBody = substr($currentFeedContent, strpos($currentFeedContent, '<entry>'));
        $existingFeedBody = substr($existingFeedBody, 0, strrpos($existingFeedContent, '</feed>'));
    }

    // Skapa headern för Atom-flödet
    $latestUpdate = $newEntries[0]['updated']; // Senaste postens datum för flödets uppdateringsdatum
    $rssFeed = createAtomFeedHeader($comic, $title, $latestUpdate);

    // Lägg till de nya posterna
    foreach ($newEntries as $entry) {
        $rssFeed .= <<<ENTRY
  <entry>
    <title>{$entry['title']}</title>
    <link href="{$entry['link']}"/>
    <id>{$entry['id']}</id>
    <author>
      <name>{$title}</name>
    </author>
    <updated>{$entry['updated']}</updated>
    <content type="html"><![CDATA[<img src="{$entry['img']}" alt="{$entry['title']}" />]]></content>
  </entry>

ENTRY;
    }

    // Lägg till de befintliga posterna efter de nya
    $rssFeed .= $existingFeedBody;

    // Avsluta flödet
    $rssFeed .= "\n\n";
    $rssFeed .= "</feed>\n";

    // Skriv till fil
    file_put_contents($filePath, $rssFeed);
}

/**
 * Hjälpfunktion för att skapa headern för Atom-flödet.
 */
function createAtomFeedHeader($comic, $title, $updatedDate) {
    // HTML-escapa titeln och comic-slugen för att vara säker i XML
    $escapedTitle = htmlspecialchars($title);
    $escapedComic = htmlspecialchars($comic);
    $selfLink = htmlspecialchars("https://askl3pios.github.io/rssbridge-export/{$comic}.xml");
    $gocomicsLink = htmlspecialchars("https://www.gocomics.com/{$comic}");

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$escapedTitle}</title>
  <link href="{$gocomicsLink}"/>
  <link rel="self" href="{$selfLink}" type="application/atom+xml"/>
  <updated>{$updatedDate}</updated>
  <id>{$gocomicsLink}</id>

XML;
}

// --- Lägg till dina serier här ---
fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', 'Sherman’s Lagoon');
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');

?>
