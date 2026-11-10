<?php

declare(strict_types=1);

namespace WatchRss\Service;

use DateTimeImmutable;
use DateTimeZone;
use WatchRss\Model\Feed;
use WatchRss\Model\Video;
use XMLWriter;

/**
 * Baut aus den gespeicherten Videos die kombinierte RSS-2.0-Datei.
 *
 * Bewusst RSS 2.0 statt Atom: maximale Kompatibilitaet mit Readern und mit
 * den Zusatz-Namespaces (media:, content:, dc:, sy:) lässt sich alles
 * unterbringen, was das UI verspricht - Kanalname, Titel, Beschreibung,
 * Thumbnail, Link und die Angabe Short/Longform.
 */
final class FeedBuilder
{
    private const NS_ATOM    = 'http://www.w3.org/2005/Atom';
    private const NS_MEDIA   = 'http://search.yahoo.com/mrss/';
    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    private const NS_DC      = 'http://purl.org/dc/elements/1.1/';
    private const NS_SY      = 'http://purl.org/rss/1.0/modules/syndication/';

    /**
     * @param list<Video> $videos
     * @param string      $selfUrl Absolute URL, unter der dieser Feed liegt
     * @param string      $siteUrl Absolute URL der Weboberflaeche
     */
    public function build(Feed $feed, array $videos, string $selfUrl, string $siteUrl): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');
        $writer->writeAttribute('xmlns:atom', self::NS_ATOM);
        $writer->writeAttribute('xmlns:media', self::NS_MEDIA);
        $writer->writeAttribute('xmlns:content', self::NS_CONTENT);
        $writer->writeAttribute('xmlns:dc', self::NS_DC);
        $writer->writeAttribute('xmlns:sy', self::NS_SY);

        $writer->startElement('channel');

        $writer->writeElement('title', $feed->title !== '' ? $feed->title : 'watchrss');
        $writer->writeElement('link', $siteUrl);
        $writer->writeElement('description', $this->channelDescription($feed));
        $writer->writeElement('language', 'de');
        $writer->writeElement('generator', 'watchrss');
        $writer->writeElement('docs', 'https://www.rssboard.org/rss-specification');

        $newest = $videos[0]->publishedAt ?? null;
        $writer->writeElement('lastBuildDate', self::rfc2822($newest));
        if ($newest !== null) {
            $writer->writeElement('pubDate', self::rfc2822($newest));
        }

        // --- Cache-Hinweise für Reader -------------------------------------
        // <ttl> ist die klassische RSS-Angabe in Minuten, sy:updatePeriod/
        // updateFrequency die Syndication-Variante. Beides sind Bitten, keine
        // Garantien - die Durchsetzung passiert zusätzlich per HTTP
        // (Cache-Control/ETag, siehe FeedThrottle).
        $writer->writeElement('ttl', (string) $feed->ttlMinutes);
        // sy: als Abrufe pro Tag ausdruecken - so lassen sich auch Intervalle
        // größer als eine Stunde sauber abbilden (120 Min. = 12x täglich).
        $writer->startElementNs('sy', 'updatePeriod', null);
        $writer->text('daily');
        $writer->endElement();
        $writer->startElementNs('sy', 'updateFrequency', null);
        $writer->text((string) max(1, (int) round(1440 / max(1, $feed->ttlMinutes))));
        $writer->endElement();

        // atom:link rel=self - von vielen Readern zur Deduplizierung genutzt
        $writer->startElementNs('atom', 'link', null);
        $writer->writeAttribute('href', $selfUrl);
        $writer->writeAttribute('rel', 'self');
        $writer->writeAttribute('type', 'application/rss+xml');
        $writer->endElement();

        foreach ($videos as $video) {
            $this->writeItem($writer, $feed, $video);
        }

        $writer->endElement(); // channel
        $writer->endElement(); // rss
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function writeItem(XMLWriter $writer, Feed $feed, Video $video): void
    {
        $title = $feed->prefixTitles && $video->channelTitle !== ''
            ? $video->channelTitle . ': ' . $video->title
            : $video->title;

        $writer->startElement('item');

        $writer->writeElement('title', $title);
        $writer->writeElement('link', $video->link);

        // GUID stabil und unabhängig von der URL: wechselt YouTube ein Video
        // von /watch auf /shorts, bleibt es für den Reader derselbe Eintrag.
        $writer->startElement('guid');
        $writer->writeAttribute('isPermaLink', 'false');
        $writer->text('yt:video:' . $video->videoId);
        $writer->endElement();

        $writer->writeElement('pubDate', self::rfc2822($video->publishedAt));

        // Kanalname: dc:creator wird von praktisch allen Readern angezeigt,
        // <source> ordnet den Eintrag zusätzlich dem Kanalfeed zu.
        if ($video->channelTitle !== '') {
            $writer->startElementNs('dc', 'creator', null);
            $writer->text($video->channelTitle);
            $writer->endElement();

            if ($video->channelId !== '') {
                $writer->startElement('source');
                $writer->writeAttribute('url', YouTubeFeedFetcher::feedUrl($video->channelId));
                $writer->text($video->channelTitle);
                $writer->endElement();
            }
        }

        // Format als Kategorie: "Short" bzw. "Longform" - damit kann man im
        // Reader zusätzlich filtern, auch wenn beides im Feed steckt.
        $writer->startElement('category');
        $writer->writeAttribute('domain', 'watchrss:format');
        $writer->text($video->formatLabel());
        $writer->endElement();

        if ($video->channelTitle !== '') {
            $writer->writeElement('category', $video->channelTitle);
        }

        // Thumbnail: media:thumbnail für moderne Reader, enclosure für alte.
        if ($video->thumbnailUrl !== null && $video->thumbnailUrl !== '') {
            $writer->startElementNs('media', 'thumbnail', null);
            $writer->writeAttribute('url', $video->thumbnailUrl);
            $writer->endElement();

            $writer->startElement('enclosure');
            $writer->writeAttribute('url', $video->thumbnailUrl);
            $writer->writeAttribute('type', 'image/jpeg');
            $writer->writeAttribute('length', '0');
            $writer->endElement();
        }

        $html = $this->itemHtml($video);
        $writer->startElement('description');
        $writer->writeCdata($html);
        $writer->endElement();

        $writer->startElementNs('content', 'encoded', null);
        $writer->writeCdata($html);
        $writer->endElement();

        $writer->endElement(); // item
    }

    /**
     * HTML-Koerper eines Eintrags: Thumbnail als Link, Badge mit Kanal und
     * Format, darunter die Beschreibung.
     */
    private function itemHtml(Video $video): string
    {
        $link  = htmlspecialchars($video->link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = [];

        if ($video->thumbnailUrl !== null && $video->thumbnailUrl !== '') {
            $thumb   = htmlspecialchars($video->thumbnailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt     = htmlspecialchars($video->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = sprintf(
                '<p><a href="%s"><img src="%s" alt="%s" width="480" style="max-width:100%%;height:auto"></a></p>',
                $link,
                $thumb,
                $alt
            );
        }

        $meta = '<em>' . $video->formatLabel() . '</em>';
        if ($video->channelTitle !== '') {
            $meta = '<strong>' . htmlspecialchars($video->channelTitle, ENT_QUOTES, 'UTF-8')
                . '</strong> &middot; ' . $meta;
        }
        $parts[] = '<p>' . $meta . '</p>';

        if ($video->description !== null && $video->description !== '') {
            $text = htmlspecialchars(
                mb_substr($video->description, 0, 4000),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $parts[] = '<p>' . nl2br($text, false) . '</p>';
        }

        $parts[] = sprintf('<p><a href="%s">Auf YouTube ansehen</a></p>', $link);

        return implode("\n", $parts);
    }

    private function channelDescription(Feed $feed): string
    {
        $count = count($feed->channels);

        return sprintf(
            'Kombinierter YouTube-Feed aus %d %s, erzeugt von watchrss.',
            $count,
            $count === 1 ? 'Kanal' : 'Kanälen'
        );
    }

    /**
     * RSS verlangt Datumsangaben im Format RFC 2822.
     */
    private static function rfc2822(?string $utcTimestamp): string
    {
        try {
            $date = $utcTimestamp !== null
                ? new DateTimeImmutable($utcTimestamp, new DateTimeZone('UTC'))
                : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (\Exception) {
            $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return $date->format(DATE_RSS);
    }
}
