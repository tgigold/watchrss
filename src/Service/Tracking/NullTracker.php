<?php

declare(strict_types=1);

namespace WatchRss\Service\Tracking;

/**
 * Tracker, der nichts tut. Wird benutzt, wenn kein Ziel konfiguriert ist.
 */
final class NullTracker implements TrackerInterface
{
    public function track(TrackingEvent $event): void
    {
        // absichtlich leer
    }
}
