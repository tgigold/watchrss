<?php

declare(strict_types=1);

namespace WatchRss\Service\Tracking;

/**
 * Ein Ziel, an das Seitenaufrufe gemeldet werden.
 *
 * Als Schnittstelle, damit die Anwendung nicht wissen muss, ob überhaupt
 * getrackt wird: Ist Matomo nicht konfiguriert, kommt ein NullTracker zum
 * Einsatz und alle Aufrufer bleiben unverändert. Ein zweites Ziel (Logdatei,
 * Plausible, …) wäre eine weitere Implementierung, sonst nichts.
 */
interface TrackerInterface
{
    /**
     * Meldet einen Aufruf. Darf niemals eine Exception werfen und niemals
     * den Request scheitern lassen - Statistik ist nie wichtiger als die
     * eigentliche Antwort.
     */
    public function track(TrackingEvent $event): void;
}
