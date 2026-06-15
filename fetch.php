<?php

date_default_timezone_set('UTC');
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

function logMessage($level, $message) {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    error_log("[{$timestamp}] [{$level}] {$message}");
}

function fetchGoComics($comic, $title) {
    $filePath = __DIR__ . "/{$comic}.xml";
    $existingEntries = loadExistingEntries($filePath);

    logMessage('INFO', "Startar hämtning för {$title} ({$comic})");

    $newEntries = [];
    $currentDate = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    for ($i = 0; $i < 7; $i++) {
        $dateObj = $currentDate->modify("-{$i} days");
        $datePath = $dateObj->format('Y/m/d');
        $entryDate = $dateObj->format('Y-m-d');
        $entryLink = "https://www.gocomics.com/{$comic}/{$datePath}";
        $entryId = $entryLink;

        logMessage('INFO', "Kontrollerar {$title} för {$entryDate}: {$entryLink}");

        $response = fetchHtml($entryLink);

        if ($response['html'] === null) {
            logMessage('WARNING', "Ingen HTML hämtades för {$entryLink}. HTTP-status: {$response['status']}");
            continue;
        }

        logMessage('INFO', "Hämtade HTML för {$entryLink}. HTTP-status: {$response['status']}, längd: " . strlen($response['html']) . " byte");

        $imgUrl = extractComicImageUrl($response['html']);

        if (!$imgUrl) {
            logMessage('WARNING', "Ingen comic-bild hittades för {$entryLink}");
            continue;
        }

        logMessage('INFO', "Hittade bild för {$entryLink}: {$imgUrl}");

        $newEntries[] = [
            'title' => htmlspecialchars("{$title} - {$entryDate}", ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'link' => htmlspecialchars($entryLink, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'updated' => $dateObj->format(DateTime::ATOM),
            'id' => htmlspecialchars($entryId, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            'img' => htmlspecialchars($imgUrl, ENT_QUOTES | ENT_XML1, 'UTF-8')
        ];
    }

    $finalEntries = mergeEntries($newEntries, $existingEntries);

    if (empty($finalEntries)) {
        logMessage('WARNING', "Inga poster hittades alls för {$title}");
        if (!file_exists($filePath)) {
            $feed = createAtomFeedHeader($comic, $title, date(DateTime::ATOM));
            $feed .= "</feed>\n";
            file_put_contents($filePath, $feed);
            logMessage('INFO', "Skapade tom feed-fil för {$title}: {$filePath}");
        }
        return;
    }

    usort($finalEntries, function ($a, $b) {
        return strtotime($b['updated']) <=> strtotime($a['updated']);
    });

    $latestUpdate = $finalEntries[0]['updated'];
    $feed = createAtomFeedHeader($comic, $title, $latestUpdate);

    foreach ($finalEntries as $entry) {
        $imgTag = '';
        if (!empty($entry['img'])) {
            $imgTag = '<img src="' . $entry['img'] . '" alt="' . $entry['title'] . '" />';
        }

        $feed .= <<<ENTRY
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

    $feed .= "</feed>\n";
    file_put_contents($filePath, $feed);

    logMessage('INFO', "Skrev " . count($finalEntries) . " poster till {$filePath} för {$title}");
}

function fetchHtml($url) {
    $headers = [
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
        'Pragma: no-cache'
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers)
        ]
    ]);

    $html = @file_get_contents($url, false, $context);
    $status = extractHttpStatus(isset($http_response_header) ? $http_response_header : []);

    if ($html === false || trim($html) === '') {
        return [
            'html' => null,
            'status' => $status
        ];
    }

    return [
        'html' => $html,
        'status' => $status
    ];
}

function extractHttpStatus($responseHeaders) {
    if (empty($responseHeaders)) {
        return 'unknown';
    }

    foreach ($responseHeaders as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            return (int) $match[1];
        }
    }

    return 'unknown';
}

function extractComicImageUrl($html) {
    if (!class_exists('DOMDocument')) {
        logMessage('ERROR', 'DOMDocument saknas i PHP-miljön');
        return extractComicImageUrlWithRegex($html);
    }

    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $xpath = new DOMXPath($dom);

    $queries = [
        '//img[contains(@src, "featureassets.gocomics.com/assets/")]',
        '//img[contains(@data-src, "featureassets.gocomics.com/assets/")]',
        '//img[contains(@src, "assets.amuniversal.com/")]',
        '//img[contains(@data-src, "assets.amuniversal.com/")]',
        '//meta[@property="og:image"]/@content'
    ];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if (!$nodes || $nodes->length === 0) {
            continue;
        }

        foreach ($nodes as $node) {
            $value = $node->nodeValue;

            if ($node instanceof DOMElement) {
                $value = $node->getAttribute('src');
                if (!$value) {
                    $value = $node->getAttribute('data-src');
                }
            }

            $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (isValidComicImageUrl($value)) {
                return $value;
            }
        }
    }

    return extractComicImageUrlWithRegex($html);
}

function extractComicImageUrlWithRegex($html) {
    $patterns = [
        '/<img[^>]+src="(https:\/\/featureassets\.gocomics\.com\/assets\/[^"]+)"/i',
        '/<img[^>]+data-src="(https:\/\/featureassets\.gocomics\.com\/assets\/[^"]+)"/i',
        '/<img[^>]+src="(https:\/\/assets\.amuniversal\.com\/[^"]+)"/i',
        '/<img[^>]+data-src="(https:\/\/assets\.amuniversal\.com\/[^"]+)"/i',
        '/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match)) {
            $value = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isValidComicImageUrl($value)) {
                return $value;
            }
        }
    }

    return null;
}

function isValidComicImageUrl($url) {
    if (!$url) {
        return false;
    }

    return (
        str_contains($url, 'featureassets.gocomics.com/assets/') ||
        str_contains($url, 'assets.amuniversal.com/') ||
        str_contains($url, '/comics/')
    );
}

function loadExistingEntries($filePath) {
    $entries = [];

    if (!file_exists($filePath)) {
        return $entries;
    }

    $xml = @simplexml_load_file($filePath);
    if ($xml === false) {
        logMessage('WARNING', "Kunde inte läsa befintlig XML-fil: {$filePath}");
        return $entries;
    }

    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');

    foreach ($xml->xpath('//atom:entry') as $entry) {
        $entryId = (string) $entry->id;
        $img = '';

        $contentNodes = $entry->xpath('./atom:content');
        if (!empty($contentNodes)) {
            $contentHtml = (string) $contentNodes[0];
            if (preg_match('/<img[^>]*src="([^"]+)"/i', $contentHtml, $match)) {
                $img = $match[1];
            }
        }

        $entries[] = [
            'title' => (string) $entry->title,
            'link' => (string) $entry->link['href'],
            'updated' => (string) $entry->updated,
            'id' => $entryId,
            'img' => $img
        ];
    }

    logMessage('INFO', "Läste " . count($entries) . " befintliga poster från {$filePath}");

    return $entries;
}

function mergeEntries($newEntries, $existingEntries) {
    $finalEntries = [];
    $seenIds = [];

    foreach ($newEntries as $entry) {
        if (!isset($seenIds[$entry['id']])) {
            $finalEntries[] = $entry;
            $seenIds[$entry['id']] = true;
        }
    }

    foreach ($existingEntries as $entry) {
        if (!isset($seenIds[$entry['id']])) {
            $finalEntries[] = $entry;
            $seenIds[$entry['id']] = true;
        }
    }

    return $finalEntries;
}

function createAtomFeedHeader($comic, $title, $updatedDate) {
    $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $selfLink = htmlspecialchars("https://askl3pios.github.io/rssbridge-export/{$comic}.xml", ENT_QUOTES | ENT_XML1, 'UTF-8');
    $gocomicsLink = htmlspecialchars("https://www.gocomics.com/{$comic}", ENT_QUOTES | ENT_XML1, 'UTF-8');

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

fetchGoComics('brewsterrockit', 'Brewster Rockit');
fetchGoComics('shermanslagoon', "Sherman's Lagoon");
fetchGoComics('calvinandhobbes', 'Calvin and Hobbes');
