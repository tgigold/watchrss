<?php

declare(strict_types=1);

namespace WatchRss\Service;

use WatchRss\Support\FileCache;

/**
 * Abrufbremse für die generierten Feeds.
 *
 * Hintergrund: Ein RSS-Reader lässt sich nicht *zwingen*, ein Intervall
 * einzuhalten - der Feed liegt schließlich als normale URL im Netz. Was
 * möglich ist, ist eine Staffelung aus drei Mitteln:
 *
 *   1. Bitten:   <ttl> und sy:updatePeriod im XML (macht FeedBuilder)
 *   2. Standard: Cache-Control/Expires + ETag/Last-Modified. Reader, die
 *                sich an HTTP halten, fragen dann nur noch bedingt an und
 *                bekommen ein sehr billiges 304 (macht FeedController)
 *   3. Durchsetzen: wer trotzdem immer wieder denselben Inhalt komplett
 *                herunterlädt, bekommt 429 mit Retry-After - das verstehen
 *                alle gängigen Reader und warten entsprechend (diese Klasse)
 *
 * Zwei Regeln halten Stufe 3 fair:
 *
 *   - Gebremst wird nur, solange sich am Feed nichts geändert hat. Sobald ein
 *     neues Video drin ist (= neuer ETag), ist der nächste Abruf immer frei.
 *     Niemand verpasst dadurch eine Aktualisierung.
 *   - Innerhalb eines Zeitfensters sind ein paar Abrufe frei (Burst). Damit
 *     kann man seinen Feed im Browser anschauen, ohne sich auszusperren -
 *     eine Polling-Schleife im Sekundentakt ist das Kontingent dagegen
 *     sofort los.
 *
 * Gezählt wird pro Token + Client (IP-Hash), nicht global: zwei Personen
 * mit demselben Feed blockieren sich nicht gegenseitig.
 */
final class FeedThrottle
{
    public function __construct(
        private readonly FileCache $cache,
        /** Anteil der TTL, der als Zeitfenster dient. 0 schaltet die Bremse ab. */
        private readonly float $ratio = 0.5,
        /** So viele volle Antworten sind je Fenster frei. */
        private readonly int $burst = 3,
        /** Harte Obergrenze an Requests pro Stunde (Missbrauchsschutz). */
        private readonly int $abuseLimitPerHour = 60,
        /** So viele unbekannte Feed-Tokens darf eine IP pro Stunde anfragen. */
        private readonly int $unknownLimitPerHour = 20,
    ) {
    }

    public static function fromConfig(FileCache $cache): self
    {
        return new self(
            $cache,
            (float) FEED_THROTTLE_RATIO,
            defined('FEED_THROTTLE_BURST') ? (int) FEED_THROTTLE_BURST : 3,
            (int) FEED_ABUSE_LIMIT_PER_HOUR,
            defined('FEED_UNKNOWN_LIMIT_PER_HOUR') ? (int) FEED_UNKNOWN_LIMIT_PER_HOUR : 20,
        );
    }

    /**
     * Darf dieser Client jetzt einen vollständigen Feed bekommen?
     *
     * @param  string $etag Aktueller ETag des Feeds - ändert er sich, ist der
     *                      Abruf immer erlaubt.
     * @return int|null     Sekunden bis zum nächsten erlaubten Abruf,
     *                      oder null wenn der Abruf erlaubt ist.
     */
    public function retryAfter(string $token, string $clientIp, int $ttlSeconds, string $etag): ?int
    {
        $client = $this->clientKey($token, $clientIp);

        // (a) Harte Obergrenze pro Stunde - greift unabhängig vom Inhalt.
        if ($this->abuseLimitPerHour > 0) {
            $count = (int) ($this->cache->get($this->hourKey($client)) ?? '0');
            if ($count >= $this->abuseLimitPerHour) {
                return 3600 - (time() % 3600);
            }
        }

        // (b) Wiederholtes Herunterladen desselben Inhalts
        $window = $this->window($ttlSeconds);
        if ($window <= 0) {
            return null; // Bremse abgeschaltet
        }

        $state = $this->state($client);
        if ($state === null || ($state['e'] ?? '') !== $etag) {
            return null; // nichts bekannt oder neuer Inhalt -> freie Fahrt
        }

        $elapsed = time() - (int) $state['w'];
        if ($elapsed >= $window || (int) $state['n'] < $this->burst) {
            return null; // Fenster abgelaufen oder Kontingent übrig
        }

        return max(1, $window - $elapsed);
    }

    /**
     * Hält fest, dass dieser Client gerade einen vollen Feed erhalten hat.
     * Bedingte Anfragen mit 304 werden bewusst nicht gezählt - die sind
     * genau das Verhalten, das wir belohnen wollen.
     */
    public function recordFullResponse(string $token, string $clientIp, int $ttlSeconds, string $etag): void
    {
        $client = $this->clientKey($token, $clientIp);
        $window = $this->window($ttlSeconds);
        $state  = $this->state($client);

        if ($state === null || ($state['e'] ?? '') !== $etag || (time() - (int) $state['w']) >= $window) {
            // Neues Fenster: erster Abruf dieses Inhalts.
            $state = ['w' => time(), 'n' => 1, 'e' => $etag];
        } else {
            $state['n'] = (int) $state['n'] + 1;
        }

        $this->cache->set(
            'throttle:' . $client,
            (string) json_encode($state),
            max(300, $window * 2),
        );

        if ($this->abuseLimitPerHour > 0) {
            $key   = $this->hourKey($client);
            $count = (int) ($this->cache->get($key) ?? '0');
            $this->cache->set($key, (string) ($count + 1), 3600);
        }
    }

    /**
     * Bremse gegen das Durchprobieren von Feed-Adressen.
     *
     * Der öffentliche Token ist kurz (10 Zeichen). Das ist rechnerisch sicher
     * - 3 · 10^17 Möglichkeiten -, aber es gibt keinen Grund, jemandem
     * beliebig viele Fehlversuche zu erlauben. Gezählt wird nur die IP, nicht
     * der Token: es geht ja gerade um unbekannte Tokens.
     *
     * @return int|null Sekunden bis zum nächsten Versuch, null wenn erlaubt
     */
    public function unknownTokenRetryAfter(string $clientIp): ?int
    {
        if ($this->unknownLimitPerHour <= 0) {
            return null;
        }

        $count = (int) ($this->cache->get($this->missKey($clientIp)) ?? '0');

        return $count >= $this->unknownLimitPerHour ? 3600 - (time() % 3600) : null;
    }

    /** Hält einen Fehlversuch fest (angefragter Feed existiert nicht). */
    public function recordUnknownToken(string $clientIp): void
    {
        if ($this->unknownLimitPerHour <= 0) {
            return;
        }

        $key   = $this->missKey($clientIp);
        $count = (int) ($this->cache->get($key) ?? '0');
        $this->cache->set($key, (string) ($count + 1), 3600);
    }

    private function missKey(string $clientIp): string
    {
        return 'miss:' . substr(hash('sha256', $clientIp), 0, 16) . ':' . floor(time() / 3600);
    }

    /** Länge des Zeitfensters in Sekunden (0 = Bremse aus). */
    private function window(int $ttlSeconds): int
    {
        return $this->ratio > 0 ? (int) round($ttlSeconds * $this->ratio) : 0;
    }

    /**
     * @return array{w:int,n:int,e:string}|null
     */
    private function state(string $client): ?array
    {
        $raw = $this->cache->get('throttle:' . $client);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['w'], $decoded['n']) ? $decoded : null;
    }

    private function hourKey(string $client): string
    {
        return 'abuse:' . $client . ':' . floor(time() / 3600);
    }

    /**
     * IP nie im Klartext ablegen - der Hash reicht zum Zählen völlig aus.
     */
    private function clientKey(string $token, string $clientIp): string
    {
        return $token . ':' . substr(hash('sha256', $token . '|' . $clientIp), 0, 16);
    }
}
