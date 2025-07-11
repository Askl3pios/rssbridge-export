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
    $filePath = __DIR__ . "/{$comic}.xml";
    $existingEntries = [];

    if (file_exists($filePath)) {
        $xml = @simplexml_load_file($filePath);
        if ($xml !== false) {
            $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            foreach ($xml->xpath('//atom:entry') as $entry) {
                $existingEntries[(string)$entry->id] = true;
            }
        }
    }

    $newEntries = [];
    $currentDate = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    for ($i = 0; $i < 14; $i++) {
        $dateObj = $currentDate->modify("-$i days");
        $datePath = $dateObj->format('Y/m/d');
        $entryDate = $dateObj->format('Y-m-d');

        $url = "https://www.gocomics.com/{$comic}/$datePath";
        // Använd stream_context_create för att sätta en timeout och undvika att skriptet hänger
        $context = stream_context_create([
            'http' => [
                'timeout' => 10 // 10 sekunders timeout
            ]
        ]);
        $html = @file_get_contents($url, false, $context);

        if ($html === false || empty($html)) {
            // error_log("Kunde inte hämta HTML för $url"); // För felsökning
            continue; // Hoppa över om URL inte kan nås eller om HTML är tom
        }

        $imgUrl = null;

        // FÖRST: Försök hitta bilden via den mest specifika strukturen (<picture class="item-comic-image">)
        if (preg_match('/<picture class="item-comic-image">\s*<source[^>]*>\s*<img src="([^"]+)"[^>]*>/', $html, $match)) {
             $imgUrl = $match[1];
        }
        // ANDRA: Fallback till og:image meta-taggen
        else if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $match)) {
            $imgUrl = $match[1];
        }
        // TREDJE: Mer generell sökning efter img-taggar med specifik src-struktur och klass (från förra gången)
        else if (preg_match('/<img[^>]*src="([^"]+\/comics\/[^"]+)"[^>]*class="[^"]*comic-book-image[^"]*"[^>]*>/', $html, $match)) {
            $imgUrl = $match[1];
        }
        // FJÄRDE: NY - Sök efter featureassets.gocomics.com/assets/ med base64-ish-hash (används nu för 1 & 2 juli)
        else if (preg_match('/<img[^>]*src="(https:\/\/featureassets\.gocomics\.com\/assets\/[a-f0-9]+)"[^>]*>/', $html, $match)) {
            $imgUrl = $match[1];
        }
        // FEMTE: NY - Sök efter en mer generell img-tag med assets.amuniversal.com (också vanlig)
        else if (preg_match('/<img[^>]*src="(https:\/\/assets\.amuniversal\.com\/[a-f0-9]+)"[^>]*>/', $html, $match)) {
            $imgUrl = $match[1];
        }


        if ($imgUrl) {
            $entryLink = "https://www.gocomics.com/{$comic}/$datePath";
            $entryId = $entryLink;

            if (isset($existingEntries[$entryId])) {
                // Denna post finns redan, men vi fortsätter skrapa bakåt för att hitta ALLA de senaste.
                // Vi hanterar sammanslagningen i slutet för att undvika att lägga till gamla poster i flödet igen.
            }

            $newEntries[] = [
                'title' => htmlspecialchars("$title - $entryDate"),
                'link' => htmlspecialchars($entryLink),
                'updated' => $dateObj->format(DateTime::ATOM),
                'id' => htmlspecialchars($entryId),
                'img' => htmlspecialchars($imgUrl)
            ];
        } else {
            // error_log("Kunde inte hitta bild-URL för $url för serie $title"); // Avkommentera för felsökning
            // Om ingen bild hittades, inkludera inte denna post för att undvika tomma poster.
        }
    }

    // Vänd arrayen så de nyaste serierna kommer först
    $newEntries = array_reverse($newEntries);

    // Filtrera bort verkliga dubbletter och samla alla poster (nya och gamla) i en unik och sorterad lista
    $finalEntries = [];
    $seenIds = [];

    // Lägg till de nya posterna först
    foreach ($newEntries as $entry) {
        if (!isset($seenIds[$entry['id']])) {
            $finalEntries[] = $entry;
            $seenIds[$entry['id']] = true;
        }
    }

    // Lägg till befintliga poster som inte redan lagts till
    if (file_exists($filePath)) {
        $xml = @simplexml_load_file($filePath);
        if ($xml !== false) {
            $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
            foreach ($xml->xpath('//atom:entry') as $entry) {
                $entryId = (string)$entry->id;
                if (!isset($seenIds[$entryId])) {
                    $finalEntries[] = [
                        'title' => (string)$entry->title,
                        'link' => (string)$entry->link,
                        'updated' => (string)$entry->updated,
                        'id' => (string)$entry->id,
                        'img' => (string)($entry->content->xpath('img/@src')[0] ?? '')
                    ];
                    $seenIds[$entryId] = true;
                }
            }
        }
    }
    
    // Sortera alla poster efter datum, så de nyaste alltid kommer först
    usort($finalEntries, function($a, $b) {
        return strtotime($b['updated']) - strtotime($a['updated']);
    });


    // Om inga poster alls hittades, skriv en tom feed-struktur (om filen inte fanns initialt)
    if (empty($finalEntries)) {
        if (!file_exists($filePath)) {
            $rssFeed = createAtomFeedHeader($comic, $title, date(DateTime::ATOM));
            $rssFeed .= "</feed>\n";
            file_put_contents($filePath, $rssFeed);
        }
        return;
    }

    // Skapa headern för Atom-flödet
    $latestUpdate = $finalEntries[0]['updated'];
    $rssFeed = createAtomFeedHeader($comic, $title, $latestUpdate);

    // Lägg till de slutgiltiga, unika och sorterade posterna
    foreach ($finalEntries as $entry) {
        $imgTag = '';
        if (!empty($entry['img'])) {
            $imgTag = '<img src="' . htmlspecialchars($entry['img']) . '" alt="' . htmlspecialchars($entry['title']) . '" />';
        }

        $rssFeed .= <<<ENTRY
  <entry>
    <title>{$entry['title']}</title>
    <link href="{$entry['link']}"/>
    <id>{$entry['id']}</id>
    <updated>{$entry['updated']}</updated>
    <author>
      <name>{$title}</name>
    </author>
    <content type="html"><![CDATA[{$imgTag}]]></content>
  </entry>

ENTRY;
    }

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
    $escapedTitle = htmlspecialchars($title);
    $escapedComic = htmlspecialchars($comic);
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
fetchGoComics('shermanslagoon', "Sherman's Lagoon");
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');

?>
