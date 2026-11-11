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
use WatchRss\Support\Input;

/**
 * JSON-Schnittstelle für das Frontend.
 *
 * Bewusst getrennt von den HTML-Seiten: das Frontend ist damit austauschbar
 * und die gleiche API lässt sich später für ein Login oder eine App nutzen.
 */
final class ApiController
{
    /** Obergrenze für die Freitext-Eingabe (100 Kanäle mit langer URL). */
    private const MAX_INPUT_LENGTH = 20000;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * 422 mit Feldnamen und Typen (nie Inhalten), damit sich falsch
     * verdrahtete Clients schnell finden lassen.
     */
    private function validationError(ValidationException $e): Response
    {
        $payload = ['error' => $e->getMessage()];
        if ($e->fields !== []) {
            $payload['fields'] = $e->fields;
        }

        return Response::json($payload, 422);
    }

    /**
     * POST /api/resolve - Freitext-Eingabe zu Kanälen auflösen.
     * Body: {"input": "youtube.com/bmw\n@mkbhd"}
     */
    public function resolve(Request $request): Response
    {
        try {
            $input = (string) Input::string($request->body, 'input', '', self::MAX_INPUT_LENGTH);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

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
            $created = $this->app->feedService()->create($request->body);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ResolveException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        // Einzige Stelle, an der der Server den Edit-Token von sich aus
        // herausgibt - gespeichert ist nur sein Hash.
        return Response::json($this->feedPayload($created['feed'], $created['editToken'], $request), 201);
    }

    /**
     * POST /api/feeds/{edit_token} - bestehenden Feed ändern.
     *
     * @param array<string,string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $token = $params['token'] ?? '';
        $feed  = $this->app->feeds()->findByEditToken($token);
        if ($feed === null) {
            return Response::json(['error' => 'Feed nicht gefunden.'], 404);
        }

        try {
            $feed = $this->app->feedService()->update($feed, $request->body);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ResolveException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        return Response::json($this->feedPayload($feed, $token, $request));
    }

    /**
     * GET /api/feeds/{edit_token}/preview - aktuelle Einträge des Feeds.
     *
     * @param array<string,string> $params
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
     *
     * @param array<string,string> $params
     */
    public function rotate(Request $request, array $params): Response
    {
        $token = $params['token'] ?? '';
        $feed  = $this->app->feeds()->findByEditToken($token);
        if ($feed === null || $feed->id === null) {
            return Response::json(['error' => 'Feed nicht gefunden.'], 404);
        }

        $this->app->feeds()->rotateFeedToken($feed->id);

        $updated = $this->app->feeds()->findById($feed->id);
        if ($updated === null) {
            return Response::json(['error' => 'Feed konnte nicht neu geladen werden.'], 500);
        }

        return Response::json($this->feedPayload($updated, $token, $request));
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
     * $editToken ist der Klartext-Token: beim Anlegen frisch erzeugt, sonst
     * der aus der Anfrage-URL. Aus der Datenbank lässt er sich nicht lesen.
     *
     * @return array<string,mixed>
     */
    private function feedPayload(Feed $feed, string $editToken, Request $request): array
    {
        $base = $request->baseUrl();
        $api  = $base . '/api/feeds/' . $editToken;

        return [
            'edit_token' => $editToken,
            'feed_token' => $feed->feedToken,
            // Ohne .xml - kurz und trotzdem gültig; mit .xml funktioniert
            // dieselbe Adresse ebenfalls.
            'feed_url'   => $base . '/f/' . $feed->feedToken,
            'edit_url'   => $base . '/e/' . $editToken,
            'api'        => [
                'update'  => $api,
                'preview' => $api . '/preview',
                'rotate'  => $api . '/rotate',
            ],
            'feed'       => $feed->toArray(),
        ];
    }
}
