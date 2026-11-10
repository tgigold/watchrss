<?php
/**
 * Vorschau der Einträge, die aktuell im Feed stehen.
 *
 * @var WatchRss\View\Renderer  $this
 * @var array<int,array<string,mixed>> $items
 */
?>
<section class="card" id="preview">
    <div class="card__head">
        <h2 class="h2">Aktuell im Feed</h2>
        <span class="counter"><?= count($items) ?> <?= count($items) === 1 ? 'Eintrag' : 'Einträge' ?></span>
    </div>

    <?php if ($items === []) : ?>
        <p class="empty">
            Noch nichts abgeholt. Neu hinzugefügte Kanäle werden sofort einmal geladen –
            danach übernimmt der Cronjob.
        </p>
    <?php else : ?>
        <ul class="preview-list">
            <?php foreach ($items as $item) : ?>
                <li class="preview-item">
                    <?php if (!empty($item['thumbnail_url'])) : ?>
                        <img class="preview-item__thumb" src="<?= $this->e($item['thumbnail_url']) ?>"
                             alt="" loading="lazy" width="160" height="90">
                    <?php endif; ?>
                    <div class="preview-item__body">
                        <a class="preview-item__title" href="<?= $this->e($item['link']) ?>"
                           target="_blank" rel="noopener noreferrer"><?= $this->e($item['title']) ?></a>
                        <p class="preview-item__meta">
                            <span class="badge badge--<?= $item['is_short'] ? 'short' : 'long' ?>">
                                <?= $this->e($item['format']) ?>
                            </span>
                            <span><?= $this->e($item['channel_title']) ?></span>
                            <span><?= $this->e($this->date($item['published_at'])) ?></span>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
