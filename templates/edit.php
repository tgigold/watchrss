<?php
/**
 * Editor eines bestehenden Feeds (Zugang über den Token in der URL).
 *
 * @var WatchRss\View\Renderer  $this
 * @var WatchRss\Model\Feed     $feed
 * @var string                  $editToken   Klartext aus der URL (DB kennt nur den Hash)
 * @var bool                    $collapsed   Bereiche 1-3 eingeklappt (Cookie)
 * @var array<int,array>        $preview
 * @var array<int,string>       $ttlChoices
 * @var int                     $defaultTtl
 * @var int                     $maxChannels
 */
$state = [
    'editToken'   => $editToken,
    'maxChannels' => $maxChannels,
    'channels'    => array_map(
        static fn ($feedChannel): array => $feedChannel->toArray(),
        $feed->channels
    ),
    'urls'        => [
        // Ohne .xml - kurz; mit .xml funktioniert dieselbe Adresse ebenfalls.
        'feed' => $this->url('/f/' . $feed->feedToken),
        'edit' => $this->url('/e/' . $editToken),
    ],
    'endpoints'   => [
        'resolve' => $this->url('/api/resolve'),
        'update'  => $this->url('/api/feeds/' . $editToken),
        'preview' => $this->url('/api/feeds/' . $editToken . '/preview'),
        'rotate'  => $this->url('/api/feeds/' . $editToken . '/rotate'),
    ],
];
?>
<section class="hero hero--compact">
    <h1 class="h1"><?= $this->e($feed->title) ?></h1>
    <p class="lead">
        <?= count($feed->channels) ?> <?= count($feed->channels) === 1 ? 'Kanal' : 'Kanäle' ?> ·
        alle <?= (int) $feed->ttlMinutes ?> Minuten ·
        RSS zuletzt abgerufen: <?= $this->e($this->date($feed->lastServedAt)) ?>
    </p>
</section>

<?= $this->partial('builder', [
    'state'        => $state,
    'ttlChoices'   => $ttlChoices,
    'defaultTtl'   => $defaultTtl,
    'submitLabel'  => 'Änderungen speichern',
    'feedTitle'    => $feed->title,
    'prefixTitles' => $feed->prefixTitles,
    'isEdit'       => true,
    'isCollapsed'  => $collapsed,
]) ?>

<?= $this->partial('preview', ['items' => $preview]) ?>
