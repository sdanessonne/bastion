<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — Rapport de conformité imprimable (bilan périodique pour la hiérarchie). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$days = (int) ($_GET['days'] ?? 30);
if ($days < 1 || $days > 366) { $days = 30; }
$since = date('d/m/Y', time() - $days * 86400);

function q1(PDO $db, string $sql, array $a = []): int {
    try { $st = $db->prepare($sql); $st->execute($a); return (int) $st->fetchColumn(); } catch (Throwable $e) { return 0; }
}
$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

// ── Comptes ──
$nUsers  = q1($db, 'SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute="Cleartext-Password"');
$nAdmins = q1($db, 'SELECT COUNT(*) FROM pf_admins');
$nAd = 0;
if ($dcUp) { $nAd = count(array_filter(array_map('trim', explode("\n", (string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad user list 2>/dev/null'))), fn($l) => $l !== '' && stripos($l, 'dns-') !== 0 && !in_array($l, ['Administrator','Guest','krbtgt'], true))); }

// ── Activité (période) ──
$nConn = q1($db, 'SELECT COUNT(*) FROM pf_connlog WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$nWeb  = q1($db, 'SELECT COUNT(*) FROM pf_weblog WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$nGrp  = q1($db, 'SELECT COUNT(*) FROM pf_groups');
$nBlock = q1($db, 'SELECT COUNT(*) FROM pf_blocklist');

// ── Antivirus (période) ──
$avScans = q1($db, 'SELECT COUNT(*) FROM pf_avscan WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$avThreats = q1($db, 'SELECT COALESCE(SUM(GREATEST(infected,0)),0) FROM pf_avscan WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$avBaseDate = 0;
foreach (['daily.cld', 'daily.cvd'] as $f) { if (is_file("/var/lib/clamav/$f")) { $avBaseDate = max($avBaseDate, (int) filemtime("/var/lib/clamav/$f")); } }

// ── Audit console (période) ──
$nAudit = q1($db, 'SELECT COUNT(*) FROM pf_audit WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);

// ── GPO ──
$nGpo = 0;
if ($dcUp) { $nGpo = (int) trim((string) shell_exec("sudo /usr/local/sbin/proxyfibre-ad gpo list 2>/dev/null | grep -c 'display name'")); }

// ── Mise à jour Bastion + sauvegarde ──
$git = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate state 2>/dev/null'), true) ?: [];
$aJour = ((int) ($git['retard'] ?? 0)) === 0;
$verBastion = (string) ($git['local'] ?? '—');
$dlast = 0;
foreach (glob('/srv/backups/*.tar*') ?: [] as $b) { $dlast = max($dlast, (int) @filemtime($b)); }

$rgpdKeep = 365;

pf_header('Rapport de conformité', 'rapport.php');
?>
<style>
  .rep-tools{margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
  .rep{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:1.6rem 1.8rem;max-width:860px}
  .rep h1{font-size:1.4rem;margin:0}
  .rep .sub{color:var(--muted);margin:.2rem 0 1.2rem}
  .rep h2{font-size:1rem;border-bottom:1px solid var(--line);padding-bottom:.3rem;margin:1.4rem 0 .6rem}
  .rep .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.8rem}
  .rep .stat{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem}
  .rep .stat .v{font-size:1.5rem;font-weight:700;line-height:1}
  .rep .stat .l{color:var(--muted);font-size:.78rem;margin-top:.2rem}
  .rep .li{display:flex;justify-content:space-between;padding:.35rem 0;border-bottom:1px solid var(--line);font-size:.9rem}
  .rep .li:last-child{border:0}
  .rep .ok{color:#4ade80} .rep .warn{color:#eab308}
  .rep .sign{margin-top:1.6rem;color:var(--muted);font-size:.78rem;border-top:1px solid var(--line);padding-top:.8rem}
  @media print{.sidebar,.topbar,.rep-tools,.nav-backdrop{display:none!important}.content{margin:0!important}
    body{background:#fff!important}.rep{border:none;max-width:none;padding:0}
    .rep .stat,.rep .li{break-inside:avoid}}
</style>
<div class="rep-tools">
  <form method="get" style="display:flex;gap:.5rem;align-items:center;margin:0">
    <label class="muted small">Période :</label>
    <select name="days" onchange="this.form.submit()" style="padding:.4rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
      <?php foreach ([7=>'7 jours',30=>'30 jours',90=>'90 jours',366=>'1 an'] as $d => $l): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
  </form>
  <button type="button" class="btn" onclick="window.print()">🖨️ Imprimer / PDF</button>
  <span class="muted small">Astuce : « Imprimer » → « Enregistrer au format PDF ».</span>
</div>
<div class="rep">
  <div style="display:flex;align-items:center;gap:.7rem">
    <img src="/assets/bastion-icon.svg" alt="" style="width:34px;height:34px">
    <div><h1>Rapport de conformité — Bastion</h1>
      <div class="sub">Passerelle de contrôle d'accès au réseau · période du <?= e($since) ?> à aujourd'hui · édité le <?= e(date('d/m/Y à H:i')) ?> par <?= e($_SESSION['admin'] ?? '') ?></div></div>
  </div>

  <h2>Comptes &amp; droits</h2>
  <div class="grid">
    <div class="stat"><div class="v"><?= $nUsers ?></div><div class="l">accès Internet (portail)</div></div>
    <div class="stat"><div class="v"><?= $nAd ?></div><div class="l">comptes de domaine (AD)</div></div>
    <div class="stat"><div class="v"><?= $nAdmins ?></div><div class="l">administrateurs console</div></div>
    <div class="stat"><div class="v"><?= $nGrp ?></div><div class="l">groupes (quotas/horaires)</div></div>
  </div>

  <h2>Activité réseau (<?= $days ?> j)</h2>
  <div class="grid">
    <div class="stat"><div class="v"><?= number_format($nConn, 0, ',', ' ') ?></div><div class="l">connexions journalisées</div></div>
    <div class="stat"><div class="v"><?= number_format($nWeb, 0, ',', ' ') ?></div><div class="l">visites enregistrées</div></div>
    <div class="stat"><div class="v"><?= number_format($nBlock, 0, ',', ' ') ?></div><div class="l">domaines filtrés</div></div>
  </div>

  <h2>Antivirus (<?= $days ?> j)</h2>
  <div class="grid">
    <div class="stat"><div class="v"><?= $avScans ?></div><div class="l">analyses réalisées</div></div>
    <div class="stat"><div class="v" style="color:<?= $avThreats > 0 ? '#f87171' : '#4ade80' ?>"><?= $avThreats ?></div><div class="l">menaces détectées</div></div>
    <div class="stat"><div class="v" style="font-size:.95rem"><?= $avBaseDate ? e(date('d/m/Y', $avBaseDate)) : '—' ?></div><div class="l">base virale (MAJ)</div></div>
  </div>

  <h2>Sécurité &amp; exploitation</h2>
  <div class="li"><span>Stratégies de groupe (GPO) déployées</span><strong><?= $nGpo ?></strong></div>
  <div class="li"><span>Actions d'administration tracées (audit, <?= $days ?> j)</span><strong><?= $nAudit ?></strong></div>
  <div class="li"><span>Version de Bastion</span><strong><?= e($verBastion) ?> <span class="<?= $aJour ? 'ok' : 'warn' ?>"><?= $aJour ? '· à jour' : '· mise à jour disponible' ?></span></strong></div>
  <div class="li"><span>Dernière sauvegarde</span><strong><?= $dlast ? e(date('d/m/Y H:i', $dlast)) : '—' ?></strong></div>
  <div class="li"><span>Rétention légale des journaux</span><strong><?= $rgpdKeep ?> jours (RGPD)</strong></div>

  <div class="sign">Document généré automatiquement par Bastion — © 2026 Mickaël MONESTIER (Mle 110.480).
  Les journaux détaillés (connexions, navigation, audit) sont consultables et exportables depuis la Journalisation.</div>
</div>
<?php pf_footer(); ?>
