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
        // Suppress errors for malformed XML files with '@'
        $xml = @simplexml_load_file($filePath);
        if ($xml !== false) {
            // Namespace-hantering för Atom-flöden för att korrekt läsa befintliga poster
            $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            foreach ($xml->xpath('//atom:entry') as $entry) {
                $existingEntries[(string)$entry->id] = true;
            }
        }
    }

    $newEntries = [];

    // Iterera bakåt i tiden för att hitta de senaste serierna (upp till 14 dagar för att vara säker)
    // Starta från dagens datum och gå bakåt.
    $currentDate = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    for ($i = 0; $i < 14; $i++) { // Gå tillbaka 14 dagar för att fånga upp missade dagar
        $dateObj = $currentDate->modify("-$i days");
        $datePath = $dateObj->format('Y/m/d'); // Format för URL: 2023/10/26
        $entryDate = $dateObj->format('Y-m-d'); // Format för intern användning och ID

        $url = "https://www.gocomics.com/{$comic}/$datePath";
        $html = @file_get_contents($url);

        if ($html === false) {
            // error_log("Kunde inte hämta URL: $url"); // Avkommentera för felsökning
            continue; // Hoppa över om URL inte kan nås
        }

        // Försök att extrahera bild-URL från og:image meta-taggen
        if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $match)) {
            $imgUrl = $match[1];

            $entryLink = "https://www.gocomics.com/{$comic}/$datePath"; // Länk till dagens seriestrip
            $entryId = $entryLink; // Unikt ID för posten (baserat på datum-URL)

            // Kontrollera om denna post (baserat på ID/datum-URL) redan finns i den befintliga filen
            if (isset($existingEntries[$entryId])) {
                // Vi har nått poster som redan finns i flödet, så vi kan sluta leta bakåt
                // Detta förhindrar att vi lägger till samma post flera gånger över tid
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
            // error_log("Kunde inte hitta og:image för $url"); // Avkommentera för felsökning
        }
    }

    // Vänd arrayen så de nyaste serierna kommer först (viktigt för RSS/Atom)
    $newEntries = array_reverse($newEntries);

    // Om inga nya poster hittades, och ingen fil existerar, skriv en tom feed-struktur.
    // Annars, om inga nya poster hittas och filen existerar, rör inte filen.
    if (empty($newEntries)) {
        if (!file_exists($filePath)) {
            $rssFeed = createAtomFeedHeader($comic, $title, date(DateTime::ATOM));
            $rssFeed .= "</feed>\n";
            file_put_contents($filePath, $rssFeed);
        }
        return;
    }

    // Läs in befintligt flöde för att lägga till nya poster i början
    // Vi vill bara behålla det som är INNE I <feed> men inte header/footer
    $existingFeedEntriesContent = '';
    if (file_exists($filePath)) {
        $currentFeedContent = file_get_contents($filePath);
        // Hitta positionen för första och sista <entry> taggen
        $startPos = strpos($currentFeedContent, '<entry>');
        $endPos = strrpos($currentFeedContent, '</entry>');

        if ($startPos !== false && $endPos !== false) {
            // Extrahera bara entry-delarna
            $existingFeedEntriesContent = substr($currentFeedContent, $startPos, $endPos - $startPos + strlen('</entry>'));
        }
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
    <updated>{$entry['updated']}</updated>
    <author>
      <name>{$title}</name>
    </author>
    <content type="html"><![CDATA[<img src="{$entry['img']}" alt="{$entry['title']}" />]]></content>
  </entry>

ENTRY;
    }

    // Lägg till de befintliga posterna efter de nya
    $rssFeed .= $existingFeedEntriesContent;

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
    // Din GitHub Pages URL för self-länken
    $selfLink = htmlspecialchars("https://askl3pios.github.io/rssbridge-export/{$comic}.xml");
    $gocomicsLink = htmlspecialchars("https://www.gocomics.com/{$comic}");

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$escapedTitle}</title>
  <link href="{$gocomicsLink}" />
  <link rel="self" href="{$selfLink}" type="application/atom+xml" />
  <updated>{$updatedDate}</updated>
  <id>{$gocomicsLink}</id>

XML;
}

// --- Lägg till dina serier här ---
fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', 'Sherman’s Lagoon');
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');

?>
