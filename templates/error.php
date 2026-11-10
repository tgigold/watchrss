<?php
/**
 * Fehlerseite (404/500).
 *
 * @var WatchRss\View\Renderer $this
 * @var string                 $title
 * @var string                 $message
 */
?>
<section class="card card--centered">
    <h1 class="h1"><?= $this->e($title) ?></h1>
    <p class="lead"><?= $this->e($message) ?></p>
    <p><a class="btn btn--ghost" href="<?= $this->e($this->url('/')) ?>">Zur Startseite</a></p>
</section>
