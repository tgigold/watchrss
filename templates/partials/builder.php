<?php
/**
 * Der eigentliche Feed-Baukasten - identisch auf Startseite und im Editor.
 *
 * Die Kanalliste wird von assets/js/app.js aus dem JSON-State unten
 * gerendert; das Markup hier liefert nur die Hülle.
 *
 * @var WatchRss\View\Renderer $this
 * @var array<string,mixed>    $state       Startzustand für das JavaScript
 * @var array<int,string>      $ttlChoices  Minuten => Beschriftung
 * @var int                    $defaultTtl
 * @var string                 $submitLabel
 * @var string                 $feedTitle
 * @var bool                   $prefixTitles
 * @var bool                   $isEdit
 */
?>
<form class="builder" id="builder" autocomplete="off" novalidate>

    <section class="card" aria-labelledby="step-1">
        <div class="card__head">
            <span class="step">1</span>
            <h2 class="h2" id="step-1">Kanäle eintragen</h2>
        </div>
        <p class="hint">
            Eine Eingabe pro Zeile – oder durch Komma getrennt. Erlaubt ist alles,
            was auf einen Kanal zeigt: <code>youtube.com/bmw</code>, <code>@mkbhd</code>,
            eine <code>/channel/UC…</code>-Adresse, eine Channel-ID oder der Link
            auf ein beliebiges Video des Kanals.
        </p>

        <div class="input-row">
            <label class="visually-hidden" for="channel-input">YouTube-Kanäle</label>
            <textarea id="channel-input" name="input" rows="4" spellcheck="false"
                      placeholder="https://www.youtube.com/bmw&#10;@mkbhd&#10;UCYwrS5QvBY_JbSdbINLey6Q"></textarea>
            <button type="button" class="btn btn--primary" id="resolve-btn">
                <span class="btn__label">Kanäle prüfen</span>
                <span class="spinner" hidden aria-hidden="true"></span>
            </button>
        </div>

        <ul class="errors" id="resolve-errors" hidden></ul>
    </section>

    <section class="card" aria-labelledby="step-2">
        <div class="card__head">
            <span class="step">2</span>
            <h2 class="h2" id="step-2">Auswahl &amp; Filter</h2>
            <span class="counter" id="channel-counter" aria-live="polite"></span>
        </div>
        <p class="hint">
            Pro Kanal entscheidest du, was im kombinierten Feed landet.
            <strong>Longform</strong> sind normale Videos, <strong>Shorts</strong> die
            Hochkant-Clips. Beides aus = Kanal bleibt gespeichert, liefert aber nichts.
        </p>

        <ul class="channels" id="channel-list"></ul>

        <p class="empty" id="channel-empty">Noch keine Kanäle – trage oben welche ein.</p>
    </section>

    <section class="card" aria-labelledby="step-3">
        <div class="card__head">
            <span class="step">3</span>
            <h2 class="h2" id="step-3">Feed-Einstellungen</h2>
        </div>

        <div class="settings">
            <label class="field">
                <span class="field__label">Titel des Feeds</span>
                <input type="text" id="feed-title" name="title" maxlength="190"
                       value="<?= $this->e($feedTitle) ?>" placeholder="watchrss">
            </label>

            <label class="field">
                <span class="field__label">Aktualisierungs-Intervall</span>
                <select id="feed-ttl" name="ttl_minutes">
                    <?php foreach ($ttlChoices as $minutes => $label) : ?>
                        <option value="<?= (int) $minutes ?>"<?= (int) $minutes === (int) $defaultTtl ? ' selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!--<span class="field__hint">
                    Wird dem Reader als <code>ttl</code> und per <code>Cache-Control</code>
                    mitgegeben. Wer deutlich häufiger abruft, bekommt <code>429</code>.
                </span>-->
            </label>

            <label class="field field--switch">
                <input type="checkbox" id="feed-prefix" <?= $prefixTitles ? 'checked' : '' ?>>
                <span class="field__label">Kanalname vor den Videotitel setzen</span>
                <span class="field__hint">„BMW: Titel des Videos“ – hilfreich, wenn der Reader den Autor nicht anzeigt.</span>
            </label>
        </div>
    </section>

    <div class="actions">
        <button type="submit" class="btn btn--primary btn--lg" id="save-btn">
            <span class="btn__label"><?= $this->e($submitLabel) ?></span>
            <span class="spinner" hidden aria-hidden="true"></span>
        </button>
        <?php if ($isEdit) : ?>
            <a class="btn btn--ghost" href="<?= $this->e($this->url('/')) ?>">Neuen Feed anlegen</a>
        <?php endif; ?>
    </div>

    <section class="card card--result" id="result" <?= $isEdit ? '' : 'hidden' ?>>
        <div class="card__head">
            <h2 class="h2">Dein Feed</h2>
        </div>

        <div class="field">
            <span class="field__label">
                <span class="tag tag--public">öffentlich</span>
                RSS-Adresse – die kommt in den Reader
            </span>
            <div class="copy-row">
                <input type="text" id="feed-url" readonly value="<?= $this->e($state['urls']['feed'] ?? '') ?>">
                <button type="button" class="btn btn--ghost" data-copy="#feed-url">Kopieren</button>
            </div>
            <span class="field__hint">
                Darf geteilt werden – sie verrät nur, welche Kanäle im Feed stecken, und gibt keinen Zugriff auf die Einstellungen.<br><button type="button" class="linkbtn" id="rotate-btn">Neue Adresse erzeugen</button> macht sie ungültig, falls sie doch irgendwo gelandet ist, wo sie nicht hingehört.
            </span>
        </div>

        <div class="field">
            <span class="field__label">
                <span class="tag tag--secret">geheim</span>
                Bearbeiten-Link – dein einziger Zugang zu diesem Feed
            </span>
            <div class="copy-row">
                <input type="text" id="edit-url" readonly value="<?= $this->e($state['urls']['edit'] ?? '') ?>">
                <button type="button" class="btn btn--ghost" data-copy="#edit-url">Kopieren</button>
            </div>
            <span class="field__hint">
                Wer diesen Link hat, kann den Feed ändern. Also: speichern, aber nicht weitergeben.
            </span>
        </div>
    </section>
</form>

<script type="application/json" id="watchrss-state"><?= $this->json($state) ?></script>
