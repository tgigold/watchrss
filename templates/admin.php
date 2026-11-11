<?php
/**
 * Wartungsseite: Migrationen.
 *
 * @var WatchRss\View\Renderer $this
 * @var bool                   $authorized
 * @var string|null            $error
 * @var string                 $token
 * @var string                 $rootPath
 * @var array{backup:?string,applied:list<array{version:int,name:string,result?:string}>}|null $result
 * @var list<array{version:int,name:string,description:string,applied_at:?string}> $migrations
 */
$pending = array_values(array_filter($migrations, static fn (array $m): bool => $m['applied_at'] === null));
$relative = static fn (?string $path): string => $path === null ? '' : ltrim(str_replace($rootPath, '', $path), '/');
?>
<section class="card">
    <div class="card__head">
        <h1 class="h2">Wartung</h1>
    </div>

    <?php if ($error !== null) : ?>
        <p class="errors" role="alert"><?= $this->e($error) ?></p>
    <?php endif; ?>

    <?php if (!$authorized) : ?>
        <form method="post" action="<?= $this->e($this->url('/admin')) ?>" class="settings">
            <label class="field">
                <span class="field__label">Admin-Token</span>
                <input type="password" name="token" autocomplete="current-password" required>
                <span class="field__hint">Steht als <code>ADMIN_TOKEN</code> in der <code>config.inc.php</code>.</span>
            </label>
            <input type="hidden" name="action" value="status">
            <div class="actions">
                <button type="submit" class="btn btn--primary">Status anzeigen</button>
            </div>
        </form>
    <?php else : ?>
        <?php if ($result !== null) : ?>
            <h2 class="h2">Ergebnis</h2>
            <?php if ($result['backup'] !== null) : ?>
                <p>Backup: <code><?= $this->e($relative($result['backup'])) ?></code></p>
            <?php endif; ?>
            <?php if ($result['applied'] === []) : ?>
                <p>Keine Migration ausgeführt.</p>
            <?php else : ?>
                <ul>
                    <?php foreach ($result['applied'] as $entry) : ?>
                        <li>
                            <code><?= sprintf('%04d', (int) $entry['version']) ?>_<?= $this->e($entry['name']) ?></code>
                            <?php if (isset($entry['result'])) : ?> – <?= $this->e($entry['result']) ?><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <h2 class="h2">Migrationen</h2>
        <?php if ($migrations === []) : ?>
            <p>Keine Migrationen gefunden.</p>
        <?php else : ?>
            <ul>
                <?php foreach ($migrations as $migration) : ?>
                    <li>
                        <code><?= sprintf('%04d', (int) $migration['version']) ?></code>
                        <?= $this->e($migration['description']) ?> –
                        <?= $migration['applied_at'] === null
                            ? '<strong>offen</strong>'
                            : 'ausgeführt ' . $this->e($this->date($migration['applied_at'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($pending !== []) : ?>
            <form method="post" action="<?= $this->e($this->url('/admin')) ?>">
                <input type="hidden" name="token" value="<?= $this->e($token) ?>">
                <input type="hidden" name="action" value="migrate">
                <p class="field__hint">Vorher wird automatisch ein Backup nach <code>var/backups/</code> geschrieben.</p>
                <div class="actions">
                    <button type="submit" class="btn btn--primary">
                        <?= count($pending) ?> offene Migration<?= count($pending) === 1 ? '' : 'en' ?> ausführen
                    </button>
                </div>
            </form>
        <?php else : ?>
            <p>Die Datenbank ist auf dem aktuellen Stand.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
