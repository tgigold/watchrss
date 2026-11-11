<?php

declare(strict_types=1);

namespace WatchRss\Http;

/**
 * Wie eine POST-Route gegen Cross-Site-Request-Forgery geschützt ist.
 *
 * Zusätzlich gilt für jeden POST: Schickt der Browser einen Origin-Header,
 * muss er zur eigenen Adresse passen (sonst 403).
 */
enum Csrf: string
{
    /**
     * Nur Content-Type application/json (sonst 415). Fremde Seiten können
     * so einen Request nur mit CORS-Preflight senden - und den beantwortet
     * watchrss nicht.
     */
    case JsonBody = 'nur JSON-Body';

    /**
     * Das Formular trägt ein Geheimnis im POST-Body (ADMIN_TOKEN bzw.
     * Setup-Code), das eine fremde Seite nicht kennt. Nie aus der URL.
     */
    case SecretInBody = 'Geheimnis im POST-Body';
}
