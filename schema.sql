-- ---------------------------------------------------------------------------
-- watchrss - Datenbankschema
--
-- Zeichensatz: utf8mb4 (YouTube-Titel enthalten regelmäßig Emojis).
-- Engine:      InnoDB (Fremdschlüssel + Transaktionen).
--
-- Ausführen mit:  php bin/install.php
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- users
--
-- Aktuell unbenutzt: watchrss arbeitet in der ersten Ausbaustufe rein
-- token-basiert (wer den Edit-Token kennt, darf den Feed bearbeiten).
-- Die Tabelle existiert bereits, damit ein späteres Login nur noch
-- `feeds.user_id` befuellen muss - kein Schema-Umbau noetig.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(190)     NOT NULL,
    `password_hash` VARCHAR(255)     NOT NULL,
    `display_name`  VARCHAR(120)     NULL DEFAULT NULL,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- channels
--
-- Ein Datensatz je YouTube-Kanal - global, nicht pro Nutzer-Feed. Damit wird
-- ein Kanal, den 100 Leute abonniert haben, trotzdem nur einmal gepollt.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channels` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- YouTube-Channel-ID, immer "UC" + 22 Zeichen
    `channel_id`       VARCHAR(32)      NOT NULL,
    `title`            VARCHAR(255)     NOT NULL DEFAULT '',
    -- @handle ohne fuehrendes @ (kann fehlen, wenn über /channel/UC... aufgelöst)
    `handle`           VARCHAR(120)     NULL DEFAULT NULL,
    -- URL, über die der Kanal urspruenglich aufgelöst wurde (Debug/Anzeige)
    `source_url`       VARCHAR(512)     NULL DEFAULT NULL,
    `avatar_url`       VARCHAR(512)     NULL DEFAULT NULL,
    -- Conditional GET gegen den YouTube-Feed: spart Traffic und Rate-Limit
    `http_etag`        VARCHAR(190)     NULL DEFAULT NULL,
    `http_last_modified` VARCHAR(120)   NULL DEFAULT NULL,
    `last_fetched_at`  DATETIME         NULL DEFAULT NULL,
    `last_success_at`  DATETIME         NULL DEFAULT NULL,
    `last_status`      VARCHAR(32)      NOT NULL DEFAULT 'pending',
    `last_error`       VARCHAR(512)     NULL DEFAULT NULL,
    -- aufeinanderfolgende Fehlversuche -> exponentielles Backoff im Cron
    `failure_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_channels_channel_id` (`channel_id`),
    KEY `idx_channels_handle` (`handle`),
    -- Der Cron sucht nach "am laengsten nicht geholt"
    KEY `idx_channels_last_fetched` (`last_fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- videos
--
-- Spiegel der letzten Einträge aus dem YouTube-Kanalfeed. `is_short` wird
-- beim Import aus der Link-Struktur bestimmt (/shorts/ vs. /watch?v=).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `videos` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel_ref`   INT UNSIGNED    NOT NULL,
    `video_id`      VARCHAR(32)     NOT NULL,
    `title`         VARCHAR(512)    NOT NULL DEFAULT '',
    `description`   MEDIUMTEXT      NULL DEFAULT NULL,
    `link`          VARCHAR(512)    NOT NULL DEFAULT '',
    `thumbnail_url` VARCHAR(512)    NULL DEFAULT NULL,
    -- 1 = Short (/shorts/...), 0 = Longform (/watch?v=...)
    `is_short`      TINYINT(1)      NOT NULL DEFAULT 0,
    `published_at`  DATETIME        NOT NULL,
    `updated_at`    DATETIME        NULL DEFAULT NULL,
    `first_seen_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_videos_video_id` (`video_id`),
    -- Hauptzugriff: "die letzten N Videos dieser Kanäle, ggf. ohne Shorts"
    KEY `idx_videos_channel_published` (`channel_ref`, `published_at`),
    KEY `idx_videos_published` (`published_at`),
    CONSTRAINT `fk_videos_channel` FOREIGN KEY (`channel_ref`)
        REFERENCES `channels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- feeds
--
-- Ein vom Nutzer zusammengestellter Kombi-Feed.
--
-- Zwei getrennte Tokens (siehe Support\Token):
--   edit_token  geheim, steht nur im Bearbeiten-Link  -> faktisch das Passwort
--   feed_token  öffentlich, steht in der RSS-Adresse  -> darf geteilt werden
-- Sie werden unabhängig gewürfelt; aus dem einen folgt nicht der andere.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feeds` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- 32 Zeichen base62 - nicht erratbar, wandert nirgendwo hin
    `edit_token`       VARCHAR(64)      NOT NULL,
    -- 10 Zeichen ohne Verwechsler-Paare - kurz genug für eine hübsche URL
    `feed_token`       VARCHAR(32)      NOT NULL,
    `title`            VARCHAR(190)     NOT NULL DEFAULT 'watchrss',
    -- späteres Login: Feed einem Account zuordnen
    `user_id`          INT UNSIGNED     NULL DEFAULT NULL,
    -- Cache-Vorgabe für Reader (Cache-Control/ttl/sy:updatePeriod) in Minuten
    `ttl_minutes`      SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    `max_items`        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    -- Item-Titel mit Kanalnamen präfixen ("BMW: Titel")
    `prefix_titles`    TINYINT(1)       NOT NULL DEFAULT 1,
    `last_built_at`    DATETIME         NULL DEFAULT NULL,
    `last_served_at`   DATETIME         NULL DEFAULT NULL,
    `serve_count`      INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_feeds_edit_token` (`edit_token`),
    UNIQUE KEY `uniq_feeds_feed_token` (`feed_token`),
    KEY `idx_feeds_user` (`user_id`),
    CONSTRAINT `fk_feeds_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- feed_channels
--
-- n:m zwischen feeds und channels inkl. der Filter-Schalter je Kanal.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feed_channels` (
    `feed_id`        INT UNSIGNED NOT NULL,
    `channel_ref`    INT UNSIGNED NOT NULL,
    `include_long`   TINYINT(1)   NOT NULL DEFAULT 1,
    `include_shorts` TINYINT(1)   NOT NULL DEFAULT 1,
    `position`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`feed_id`, `channel_ref`),
    KEY `idx_feed_channels_channel` (`channel_ref`),
    CONSTRAINT `fk_feed_channels_feed` FOREIGN KEY (`feed_id`)
        REFERENCES `feeds` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_feed_channels_channel` FOREIGN KEY (`channel_ref`)
        REFERENCES `channels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
