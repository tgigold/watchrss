<?php
/**
 * Startseite: neuen Kombi-Feed zusammenstellen.
 *
 * @var WatchRss\View\Renderer $this
 * @var array<int,string>      $ttlChoices
 * @var int                    $defaultTtl
 * @var int                    $maxChannels
 */
$state = [
    'editToken'   => null,
    'maxChannels' => $maxChannels,
    'channels'    => [],
    'urls'        => ['feed' => '', 'edit' => ''],
    'endpoints'   => [
        'resolve' => $this->url('/api/resolve'),
        'create'  => $this->url('/api/feeds'),
    ],
];
?>
<section class="hero">
    <h1 class="h1">Deine YouTube-Kanäle.<br>Ein einziger RSS-Feed.</h1>
    <p class="lead">
        Kanäle eintragen, Shorts pro Kanal an- oder abschalten, fertigen RSS-Feed in den Reader kippen. Kein Account, dein Bearbeitungs-Link wird nach dem Speichern gezeigt.
    </p>
</section>

<?= $this->partial('builder', [
    'state'        => $state,
    'ttlChoices'   => $ttlChoices,
    'defaultTtl'   => $defaultTtl,
    'submitLabel'  => 'Feed erstellen',
    'feedTitle'    => '',
    'prefixTitles' => true,
    'isEdit'       => false,
]) ?>

<section class="card card--muted">
    <h2 class="h2">Wie es funktioniert</h2>
    <ol class="steps"><?php /*
        <li>
            <strong>Kanal-ID finden.</strong> watchrss ruft die Kanalseite ab und liest die
            <code>UC…</code>-ID aus dem Quelltext – daraus wird der offizielle Feed
            <code>youtube.com/feeds/videos.xml?channel_id=…</code> ermittelt.
        </li>
        <li>
            <strong>Regelmäßig einsammeln.</strong> Ein Cronjob holt alle eingetragenen Kanäle
            im Hintergrund ab und speichert neue Videos. Dein Feed ist dadurch sofort da,
            wenn der Reader anfragt – und enthält mehr als die 15 Einträge, die YouTube selbst liefert.
        </li>
        <li>
            <strong>Shorts erkennen.</strong> YouTube verrät das Format über die Video-Adresse:
            <code>/shorts/…</code> ist ein Short, <code>/watch?v=…</code> ein normales Video.
            Genau danach filtert watchrss – pro Kanal getrennt.
        </li>
        <li>
            <strong>Höflich bleiben.</strong> Der Feed gibt ein Intervall vor (<code>ttl</code>,
            <code>Cache-Control</code>, <code>ETag</code>). Reader, die sich daran halten,
            bekommen ein billiges <code>304 Not Modified</code>; wer im Sekundentakt pollt,
            bekommt <code>429</code> mit <code>Retry-After</code>.
        </li> */ ?>
        <li><strong>Kanal eintragen</strong> – Du gibst watchrss die Namen der YouTube-Kanäle, die du verfolgen willst – anschließend kannst du festlegen, ob du alle Inhalte, nur Longform oder nur Shorts als Updates erhalten willst.</li>
        <li><strong>RSS verfolgen</strong> – Du erhälst einen personalisierten RSS-Feed, in dem stets die neusten Beiträge deiner YouTube-Kanäle stehen.</li>
        <li><strong>Änderungen</strong> – Über deinen personalisierten Link kannst du jederzeit Änderungen vornehmen, bspw. neue Kanäle hinzufügen.</li>
        <li><strong>Fragen?</strong> – Du hast noch Fragen? <a href="https://gigold.me/blog/projekt-watchrss">Dann hier entlang</a>.
        <!--<li><strong>Aktualisierungen</strong> – Bleib fair: du kannst selbst entscheiden, wie oft du deinen persönlichen Feed checkst, watchrss pürft YouTube aber maximal aller 30 Minuten</li>-->
    </ol>
</section>
