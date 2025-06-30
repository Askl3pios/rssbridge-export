    $rssFeed = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>{$title}</title>
  <link href="https://www.gocomics.com/{$comic}"/>
  <link href="https://askl3pios.github.io/rssbridge-export/{$comic}.xml" rel="self" type="application/atom+xml"/>
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
    <author><name>{$title}</name></author>
    <content type="html">
      <![CDATA[<img src="{$entry['img']}" alt="{$title}" />]]>
    </content>
  </entry>

ENTRY;
    }

    $rssFeed .= "\n<!-- Uppdaterad: " . date('c') . " -->\n";
    $rssFeed .= "</feed>\n";
