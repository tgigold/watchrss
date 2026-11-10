<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use WatchRss\App;
use WatchRss\Http\Request;
use WatchRss\Http\Response;

/**
 * Die beiden HTML-Seiten: Startseite (Feed zusammenstellen) und Editor.
 */
final class PageController
{
    public function __construct(private readonly App $app)
    {
    }

    public function home(Request $request): Response
    {
        return Response::html($this->app->renderer()->page('home', [
            'pageTitle'       => 'watchrss – YouTube-Kanäle als ein RSS-Feed',
            'pageDescription' => 'Beliebig viele YouTube-Kanäle zu einem RSS-Feed bündeln – '
                . 'mit Shorts-Filter pro Kanal. Kein Account notwendig.',
            'canonical'       => $request->baseUrl() . '/',
            'ttlChoices'  => $this->ttlChoices(),
            'defaultTtl'  => (int) FEED_DEFAULT_TTL_MINUTES,
            'maxChannels' => (int) FEED_MAX_CHANNELS,
            'feed'        => null,
        ]));
    }

    /**
     * Editor für einen bestehenden Feed. Der Token in der URL ist der
     * einzige Zugangsschutz - bewusst so gewählt (siehe README).
     */
    public function edit(Request $request, array $params): Response
    {
        $feed = $this->app->feeds()->findByEditToken($params['token'] ?? '');

        if ($feed === null) {
            return Response::html($this->app->renderer()->page('error', [
                'pageTitle' => 'Feed nicht gefunden',
                'title'     => 'Diesen Feed gibt es nicht',
                'message'   => 'Der Bearbeitungs-Link ist unvollständig oder der Feed wurde gelöscht.',
                'noindex'   => true,
            ]), 404);
        }

        // Der Editor trägt den geheimen Token in der Adresse. Er bekommt
        // deshalb noindex und generische Vorschaudaten - wird der Link
        // irgendwo eingefügt, zeigt die Vorschaukarte nichts Feed-Spezifisches.
        return Response::html($this->app->renderer()->page('edit', [
            'pageTitle'   => $feed->title . ' – watchrss',
            'noindex'     => true,
            'feed'        => $feed,
            'preview'     => $this->app->feedService()->preview($feed, 24),
            'ttlChoices'  => $this->ttlChoices(),
            'defaultTtl'  => $feed->ttlMinutes,
            'maxChannels' => (int) FEED_MAX_CHANNELS,
        ]));
    }

    /**
     * @return array<int,string> Minuten => Beschriftung
     */
    private function ttlChoices(): array
    {
        $labels = [];
        foreach (array_map('intval', explode(',', (string) FEED_TTL_CHOICES)) as $minutes) {
            $labels[$minutes] = $minutes < 60
                ? $minutes . ' Minuten'
                : ($minutes / 60 == 1 ? '1 Stunde' : (int) ($minutes / 60) . ' Stunden');
        }

        return $labels;
    }
}
