<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$message = '';
$error = '';
$ran = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run') {
    if (!ir_cron_authorized()) {
        $error = 'Cron-Key fehlt oder ist falsch (config cron_key).';
    } else {
        @set_time_limit(120);
        $ran = ir_run_all();
        $message = $ran['ok'] ? 'Aktualisierung fertig.' : 'Aktualisierung mit Fehlern.';
    }
}

$status = ir_read_status();
$sources = ir_sources();
$byId = [];
foreach (($status['sources'] ?? []) as $row) {
    if (is_array($row) && isset($row['id'])) {
        $byId[(string) $row['id']] = $row;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>iCal → RSS Bridge</title>
    <style>
        :root { color-scheme: light; }
        body { font-family: system-ui, sans-serif; max-width: 52rem; margin: 1.5rem auto; padding: 0 1rem 3rem; line-height: 1.45; background: #f7f7f5; color: #1a1a1a; }
        h1 { font-size: 1.4rem; margin-bottom: .2rem; }
        .muted { color: #666; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: .5rem; padding: 1rem 1.1rem; margin: 1rem 0; }
        table { width: 100%; border-collapse: collapse; font-size: .95rem; }
        th, td { text-align: left; padding: .45rem .35rem; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #666; font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
        code { font-size: .9em; word-break: break-all; }
        .ok { color: #0a7a32; }
        .err { color: #a11; }
        button { font: inherit; padding: .4rem .75rem; cursor: pointer; border-radius: .35rem; border: 1px solid #1f4b99; background: #1f4b99; color: #fff; }
        a { color: #1f4b99; }
    </style>
</head>
<body>
    <p class="muted"><a href="../">← Website-RSS-Monitor</a></p>
    <h1>iCal → RSS Bridge</h1>
    <p class="muted">Holt ICS-Kalender per Cron und schreibt statische RSS-Feeds für Fever/Reeder.</p>

    <?php if ($message !== ''): ?>
        <p class="<?= !empty($ran['ok']) ? 'ok' : 'err' ?>"><?= ir_h($message) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="err"><?= ir_h($error) ?></p>
    <?php endif; ?>

    <div class="card">
        <p>
            Letzter Lauf:
            <strong><?= ir_h((string) ($status['last_run'] ?? '—')) ?></strong>
        </p>
        <form method="post">
            <input type="hidden" name="action" value="run">
            <button type="submit">Jetzt aktualisieren</button>
        </form>
        <?php if ((string) ir_cfg('cron_key', '') !== ''): ?>
            <p class="muted">Hinweis: <code>cron_key</code> ist gesetzt — manueller Lauf hier nur, wenn der Key in der URL steht (<code>?key=…</code>).</p>
        <?php endif; ?>
        <p class="muted" style="margin:.7rem 0 0">CLI: <code>php cron.php</code></p>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Quelle</th>
                    <th>Status</th>
                    <th>RSS</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$sources): ?>
                <tr><td colspan="3" class="muted">Keine Quellen in <code>sources.json</code>.</td></tr>
            <?php endif; ?>
            <?php foreach ($sources as $src):
                $st = $byId[$src['id']] ?? null;
                $feed = ir_feed_url($src['id']);
                $exists = is_file(ir_feed_path($src['id']));
                ?>
                <tr>
                    <td>
                        <strong><?= ir_h($src['name']) ?></strong><br>
                        <span class="muted"><code><?= ir_h($src['id']) ?></code></span><br>
                        <span class="muted"><a href="<?= ir_h(ir_normalize_ical_url($src['ical_url'])) ?>"><?= ir_h($src['ical_url']) ?></a></span>
                    </td>
                    <td>
                        <?php if ($st === null): ?>
                            <span class="muted">noch nicht gelaufen</span>
                        <?php elseif (!empty($st['ok'])): ?>
                            <span class="ok">OK — <?= (int) ($st['events'] ?? 0) ?> Events</span>
                        <?php else: ?>
                            <span class="err"><?= ir_h((string) ($st['error'] ?? 'Fehler')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($exists): ?>
                            <a href="<?= ir_h($feed) ?>"><code><?= ir_h($feed) ?></code></a>
                        <?php else: ?>
                            <span class="muted"><code><?= ir_h($feed) ?></code> (noch nicht erzeugt)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
