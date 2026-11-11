<?php
/**
 * Geführtes Setup.
 *
 * @var WatchRss\View\Renderer $this
 * @var string|null            $error
 * @var bool                   $done
 * @var list<array{label:string,ok:bool}> $checks
 * @var string                 $codeFile
 * @var array<string,string>   $values
 * @var string|null            $siteUrl
 * @var array<string,string>|null $secrets
 * @var array{tables:list<string>,backup:?string,applied:list<array{version:int,name:string,result:string}>}|null $result
 */
?>
<section class="card">
    <div class="card__head">
        <h1 class="h2">watchrss einrichten</h1>
    </div>

    <?php if ($error !== null) : ?>
        <p class="errors" role="alert"><?= $this->e($error) ?></p>
    <?php endif; ?>

    <?php if ($done) : ?>
        <p class="lead">Fertig – watchrss ist eingerichtet. Das Setup ist jetzt gesperrt.</p>

        <h2 class="h2">Einmalig notieren</h2>
        <p>Diese Werte stehen in der <code>config.inc.php</code> und werden hier nur dieses eine Mal angezeigt.</p>
        <ul>
            <li>Wartung (<code>/admin</code>): <code><?= $this->e($secrets['ADMIN_TOKEN'] ?? '') ?></code></li>
            <li>Cron-Adresse (alle 10 Minuten aufrufen lassen):<br>
                <code><?= $this->e(($siteUrl ?? '') . '/cron/' . ($secrets['CRON_TOKEN'] ?? '')) ?></code></li>
        </ul>

        <h2 class="h2">Datenbank</h2>
        <p>Tabellen: <?= $this->e(implode(', ', $result['tables'] ?? [])) ?></p>
        <?php if (($result['applied'] ?? []) !== []) : ?>
            <p>Migrationen:
                <?php foreach ($result['applied'] as $entry) : ?>
                    <code><?= sprintf('%04d', (int) $entry['version']) ?></code>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <p><a class="btn btn--primary" href="<?= $this->e(($siteUrl ?? '') . '/') ?>">Zur Startseite</a></p>
    <?php else : ?>
        <p class="lead">
            Den Setup-Code findest du per FTP in <code><?= $this->e($codeFile) ?></code>.
            So ist sichergestellt, dass nur einrichtet, wer Zugriff auf den Server hat.
        </p>

        <h2 class="h2">Voraussetzungen</h2>
        <ul>
            <?php foreach ($checks as $check) : ?>
                <li><?= $check['ok'] ? '✓' : '✗' ?> <?= $this->e($check['label']) ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="post" action="<?= $this->e($this->url('/setup')) ?>" class="settings">
            <label class="field">
                <span class="field__label">Setup-Code</span>
                <input type="text" name="setup_code" autocomplete="off" required placeholder="XXXX-XXXX-XXXX">
            </label>

            <label class="field">
                <span class="field__label">Öffentliche Adresse</span>
                <input type="url" name="base_url" required value="<?= $this->e($values['base_url'] ?? '') ?>">
                <span class="field__hint">Ohne Schrägstrich am Ende, z. B. https://watchrss.de</span>
            </label>

            <label class="field">
                <span class="field__label">Datenbank-Host</span>
                <input type="text" name="db_host" required value="<?= $this->e($values['db_host'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field__label">Port</span>
                <input type="number" name="db_port" min="1" max="65535" value="<?= $this->e($values['db_port'] ?? '3306') ?>">
            </label>
            <label class="field">
                <span class="field__label">Datenbankname</span>
                <input type="text" name="db_name" required value="<?= $this->e($values['db_name'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field__label">Benutzer</span>
                <input type="text" name="db_user" required autocomplete="off" value="<?= $this->e($values['db_user'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field__label">Passwort</span>
                <input type="password" name="db_pass" autocomplete="new-password">
            </label>

            <div class="actions">
                <button type="submit" class="btn btn--primary">Einrichten</button>
            </div>
        </form>
    <?php endif; ?>
</section>
