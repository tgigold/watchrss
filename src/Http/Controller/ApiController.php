<?php

declare(strict_types=1);

namespace WatchRss\Http\Controller;

use WatchRss\App;
use WatchRss\Exception\ResolveException;
use WatchRss\Exception\ValidationException;
use WatchRss\Http\Request;
use WatchRss\Http\Response;
use WatchRss\Model\Channel;
use WatchRss\Model\Feed;

/**
 * JSON-Schnittstelle für das Frontend.
 *
 * Bewusst getrennt von den HTML-Seiten: das Frontend ist damit austauschbar
 * und die gleiche API lässt sich später für ein Login oder eine App nutzen.
 */
final class ApiController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * POST /api/resolve - Freitext-Eingabe zu Kanälen auflösen.
     * Body: {"input": "youtube.com/bmw\n@mkbhd"}
     */
    public function resolve(Request $request): Response
    {
        $input = (string) $request->input('input', '');

        if (trim($input) === '') {
            return Response::json(['error' => 'Bitte mindestens einen Kanal eingeben.'], 422);
        }

        try {
            $result = $this->app->feedService()->resolveInput($input);
        } catch (ResolveException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json([
            'channels' => array_map(
                fn (Channel $channel): array => $this->channelPayload($channel),
                $result['channels']
            ),
            'errors'   => $result['errors'],
        ]);
    }

    /**
     * POST /api/feeds - neuen Kombi-Feed anlegen.
     */
    public function create(Request $request): Response
    {
        try {
            $feed = $this->app->feedService()->save(null, $request->body);
        } catch (ValidationException | ResolveException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json($this->feedPayload($feed, $request), 201);
    }

    /**
     * POST /api/feeds/{edit_token} - bestehenden Feed ändern.
     */
    public function update(Request $request, array $params): Response
    {
        $feed = $this->app->feeds()->findByEditToken($params['token'] ?? '');
        if ($feed === null) {
            return Response::json(['error' => 'Feed nicht gefunden.'], 404);
        }

        try {
            $feed = $this->app->feedService()->save($feed, $request->body);
        } catch (ValidationException | ResolveException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json($this->feedPayload($feed, $request));
    }

    /**
     * GET /api/feeds/{edit_token}/preview - aktuelle Einträge des Feeds.
     */
    public function preview(Request $request, array $params): Response
    {
        $feed = $this->app->feeds()->findByEditToken($params['token'] ?? '');
        if ($feed === null) {
            return Response::json(['error' => 'Feed nicht gefunden.'], 404);
        }

        return Response::json(['items' => $this->app->feedService()->preview($feed, 24)]);
    }

    /**
     * POST /api/feeds/{edit_token}/rotate - neue öffentliche Adresse erzeugen.
     *
     * Die alte RSS-Adresse wird damit ungültig. Möglich ist das nur mit dem
     * geheimen Edit-Token - wer bloß die Feed-Adresse kennt, kann sie also
     * nicht unter dem Besitzer wegziehen.
     */
    public function rotate(Request $request, array $params): Response
    {
        $feed = $this->app->feeds()->findByEditToken($params['token'] ?? '');
        if ($feed === null || $feed->id === null) {
            return Response::json(['error' => 'Feed nicht gefunden.'], 404);
        }

        $this->app->feeds()->rotateFeedToken($feed->id);

        $updated = $this->app->feeds()->findByEditToken($feed->editToken);
        if ($updated === null) {
            return Response::json(['error' => 'Feed konnte nicht neu geladen werden.'], 500);
        }

        return Response::json($this->feedPayload($updated, $request));
    }

    /**
     * @return array<string,mixed>
     */
    private function channelPayload(Channel $channel): array
    {
        return $channel->toArray() + [
            'include_long'   => true,
            'include_shorts' => true,
        ];
    }

    /**
     * Alles, was das Frontend nach einem Speichern braucht: beide Adressen
     * und die künftigen API-Endpunkte (die hängen am Edit-Token, den das
     * Frontend beim Anlegen ja noch nicht kennt).
     *
     * @return array<string,mixed>
     */
    private function feedPayload(Feed $feed, Request $request): array
    {
        $base = $request->baseUrl();
        $api  = $base . '/api/feeds/' . $feed->editToken;

        return [
            'edit_token' => $feed->editToken,
            'feed_token' => $feed->feedToken,
            // Ohne .xml - kurz und trotzdem gültig; mit .xml funktioniert
            // dieselbe Adresse ebenfalls.
            'feed_url'   => $base . '/f/' . $feed->feedToken,
            'edit_url'   => $base . '/e/' . $feed->editToken,
            'api'        => [
                'update'  => $api,
                'preview' => $api . '/preview',
                'rotate'  => $api . '/rotate',
            ],
            'feed'       => $feed->toArray(),
        ];
    }
}
