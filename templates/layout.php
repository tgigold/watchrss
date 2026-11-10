<?php
/**
 * Grundgerüst jeder HTML-Seite.
 *
 * @var WatchRss\View\Renderer $this
 * @var string                 $content         Gerenderter Seiteninhalt
 * @var string                 $appName
 * @var string|null            $pageTitle
 * @var string|null            $pageDescription
 * @var string|null            $shareTitle      Titel für Link-Vorschauen
 * @var string|null            $canonical       Kanonische Adresse dieser Seite
 * @var bool|null              $noindex
 */
$pageTitle       = $pageTitle ?? 'watchrss';
$pageDescription = $pageDescription
    ?? 'watchrss bündelt beliebig viele YouTube-Kanäle zu einem einzigen RSS-Feed – mit Shorts-Filter pro Kanal.';

// Link-Vorschauen (Slack, WhatsApp, Mastodon …) bekommen bewusst nie die
// Adresse der aktuellen Seite, sondern immer die Startseite: Editor- und
// Feed-Links enthalten Tokens, die nicht in einer Vorschaukarte auftauchen
// sollen. Aus demselben Grund ist $shareTitle standardmäßig generisch.
$canonical   = $canonical ?? $this->url('/');
$shareTitle  = $shareTitle ?? 'watchrss – YouTube-Kanäle als ein RSS-Feed';
// JPEG statt PNG: Die Vorlage ist fotografisch, PNG wäre hier zehnmal so
// groß. WhatsApp zeigt Vorschaubilder oberhalb von ~300 KB gar nicht erst an.
$shareImagePath = 'assets/opengraph.jpg';
$shareImage     = $this->asset($shareImagePath);
[$shareImageWidth, $shareImageHeight] = $this->imageSize($shareImagePath);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($pageTitle) ?></title>
    <meta name="description" content="<?= $this->e($pageDescription) ?>">
    <meta name="color-scheme" content="dark light">
    <link rel="canonical" href="<?= $this->e($canonical) ?>">
<?php if (!empty($noindex)) : ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>

    <!-- Open Graph / Link-Vorschauen -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="watchrss">
    <meta property="og:locale" content="de_DE">
    <meta property="og:title" content="<?= $this->e($shareTitle) ?>">
    <meta property="og:description" content="<?= $this->e($pageDescription) ?>">
    <meta property="og:url" content="<?= $this->e($canonical) ?>">
    <meta property="og:image" content="<?= $this->e($shareImage) ?>">
    <meta property="og:image:type" content="image/jpeg">
<?php if ($shareImageWidth > 0) : ?>
    <meta property="og:image:width" content="<?= (int) $shareImageWidth ?>">
    <meta property="og:image:height" content="<?= (int) $shareImageHeight ?>">
<?php endif; ?>
    <meta property="og:image:alt" content="watchrss.de – Deine YouTube-Lieblinge. Immer im Blick.">

    <!-- X/Twitter liest og:* als Rückfallebene, braucht aber den Kartentyp -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image:alt" content="watchrss.de – Deine YouTube-Lieblinge. Immer im Blick.">

    <link rel="icon" href="<?= $this->e($this->asset('assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $this->e($this->asset('assets/css/app.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Zum Inhalt springen</a>

<header class="site-header">
    <a class="wordmark" href="<?= $this->e($this->url('/')) ?>" aria-label="watchrss Startseite">
        <svg class="wordmark__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 11a9 9 0 0 1 9 9" />
            <path d="M4 4a16 16 0 0 1 16 16" />
            <circle cx="5" cy="19" r="1.6" />
        </svg>
        <span class="wordmark__text">watch<strong>rss</strong></span>
    </a>
    <p class="site-header__claim">YouTube-Kanäle → ein Feed</p>
</header>

<main id="main" class="site-main">
    <?= $content ?>
</main>

<footer class="site-footer">
    <p>
        <?= $this->e($appName ?? 'watchrss') ?> von Thomas Gigold &middot; <a href="https://gigold.me/impressum">Impressum</a> &middot; Datenschutz: Es werden keine Daten erfasst; kein Account, der Token ist dein Zugang
    </p>
</footer>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script src="<?= $this->e($this->asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
