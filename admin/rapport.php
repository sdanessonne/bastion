<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Rapport de conformité (bilan périodique destiné à la hiérarchie).
 *
 * ── CE QUI A CHANGÉ, ET POURQUOI ─────────────────────────────────────────────
 * La version précédente alignait des compteurs — « 12 comptes, 340 connexions,
 * 8 GPO » — et affirmait en bas de page « Rétention légale : 365 jours (RGPD) ».
 * Cette dernière ligne était ÉCRITE EN DUR. Aucune donnée n'était consultée : le
 * rapport annonçait la conformité au lieu de la constater. Si la purge
 * automatique s'était arrêtée six mois plus tôt, le document aurait continué à
 * afficher exactement la même phrase rassurante.
 *
 * C'est le défaut le plus grave qu'un rapport de conformité puisse avoir : il
 * remplace un contrôle par une déclaration, et il est ensuite classé comme s'il
 * valait constat.
 *
 * Le rapport est donc bâti sur des CONTRÔLES. Chacun mesure une valeur réelle,
 * la compare à un seuil, et rend un avis : conforme, à surveiller, ou non
 * conforme. Un contrôle qui n'a pas pu être exécuté le dit — il ne compte jamais
 * comme conforme.
 *
 * ── CE QUE CE DOCUMENT N'EST PAS ─────────────────────────────────────────────
 * Une auto-évaluation technique, pas une certification juridique. Bastion peut
 * constater que les journaux les plus anciens ont onze mois ; il ne peut pas
 * dire si cette durée est celle qui s'impose au service. La qualification
 * juridique appartient au responsable de traitement, et le document le dit.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 366], true)) { $days = 30; }
$since = date('d/m/Y', time() - $days * 86400);

function q1(PDO $db, string $sql, array $a = []): ?int {
    // null ≠ 0 : « la requête a échoué » et « il n'y en a aucun » ne veulent pas
    // dire la même chose. Les confondre ferait passer une base injoignable pour
    // un service au repos — et le contrôle correspondant pour un contrôle réussi.
    try { $st = $db->prepare($sql); $st->execute($a); $v = $st->fetchColumn();
          return $v === false ? null : (int) $v; } catch (Throwable $e) { return null; }
}
function qs(PDO $db, string $sql, array $a = []): ?string {
    try { $st = $db->prepare($sql); $st->execute($a); $v = $st->fetchColumn();
          return ($v === false || $v === null) ? null : (string) $v; } catch (Throwable $e) { return null; }
}
function nb(?int $v): string { return $v === null ? '—' : number_format($v, 0, ',', ' '); }

/**
 * Écart par rapport à la période précédente de même durée.
 * Un chiffre seul ne dit rien : 340 connexions, est-ce beaucoup ? La seule
 * référence dont on dispose sans rien inventer est la même durée juste avant.
 */
function evol(?int $a, ?int $b): string {
    if ($a === null || $b === null || $b === 0) { return ''; }
    $p = (int) round(($a - $b) / $b * 100);
    if ($p === 0) { return '<span class="ev">= stable</span>'; }
    return '<span class="ev">' . ($p > 0 ? '▲ +' : '▼ ') . $p . ' %</span>';
}

$dcUp = trim((string) shell_exec('systemctl is-active samba-ad-dc 2>/dev/null')) === 'active';

// ─────────────────────────────────────────────────────────────────────────────
//  MESURES
// ─────────────────────────────────────────────────────────────────────────────

// Comptes et droits
$nUsers  = q1($db, 'SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute="Cleartext-Password"');
$nAdmins = q1($db, 'SELECT COUNT(*) FROM pf_admins');
$nGrp    = q1($db, 'SELECT COUNT(*) FROM pf_groups');
$nAd = null;
if ($dcUp) {
    $l = array_filter(array_map('trim', explode("\n", (string) shell_exec('sudo /usr/local/sbin/proxyfibre-ad user list 2>/dev/null'))),
        fn($x) => $x !== '' && stripos($x, 'dns-') !== 0 && !in_array($x, ['Administrator', 'Guest', 'krbtgt'], true));
    $nAd = count($l);
}

// Activité : période courante ET période précédente de MÊME durée
$nConn  = q1($db, 'SELECT COUNT(*) FROM pf_connlog WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$nConnP = q1($db, 'SELECT COUNT(*) FROM pf_connlog WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY) AND ts < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days * 2, $days]);
$nWeb   = q1($db, 'SELECT COUNT(*) FROM pf_weblog  WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$nWebP  = q1($db, 'SELECT COUNT(*) FROM pf_weblog  WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY) AND ts < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days * 2, $days]);
$nBlock = q1($db, 'SELECT COUNT(*) FROM pf_blocklist');
$nAudit = q1($db, 'SELECT COUNT(*) FROM pf_audit WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$nEchec = q1($db, 'SELECT COUNT(*) FROM radpostauth WHERE reply="Access-Reject" AND authdate >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);

// Antivirus
$avScans   = q1($db, 'SELECT COUNT(*) FROM pf_avscan WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$avThreats = q1($db, 'SELECT COALESCE(SUM(GREATEST(infected,0)),0) FROM pf_avscan WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
$avBase = 0;
foreach (['daily.cld', 'daily.cvd'] as $f) {
    if (is_file("/var/lib/clamav/$f")) { $avBase = max($avBase, (int) filemtime("/var/lib/clamav/$f")); }
}

// ── Conservation des journaux : MESURÉE, pas déclarée ───────────────────────
// On lit la trace la PLUS ANCIENNE réellement présente en base. C'est le seul
// moyen de savoir si la purge fait son travail : le réglage dit ce qui devrait
// arriver, la donnée dit ce qui arrive.
$retenu = (int) (qs($db, "SELECT v FROM pf_settings WHERE k='log_retention_days' LIMIT 1") ?? 365);
if ($retenu < 30 || $retenu > 1825) { $retenu = 365; }
$plusVieux = null;
foreach ([qs($db, 'SELECT MIN(ts) FROM pf_weblog'), qs($db, 'SELECT MIN(ts) FROM pf_connlog')] as $d) {
    if ($d !== null && ($t = strtotime($d)) !== false) { $plusVieux = $plusVieux === null ? $t : min($plusVieux, $t); }
}
$ageMax = $plusVieux === null ? null : (int) floor((time() - $plusVieux) / 86400);

// Purge : sa dernière exécution est datée par le marquage des scellés.
$dernPurge = qs($db, 'SELECT MAX(purged_at) FROM pf_log_seal');
$cronPurge = is_file('/etc/cron.d/proxyfibre-purge');

// Intégrité : une journée d'activité sans scellé est un trou dans la chaîne —
// et c'est précisément ce qu'un scellement sert à rendre impossible.
$joursScelles = q1($db, 'SELECT COUNT(*) FROM pf_log_seal WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$days]);
$joursActifs  = q1($db, 'SELECT COUNT(DISTINCT DATE(ts)) FROM pf_connlog WHERE ts >= DATE_SUB(NOW(), INTERVAL ? DAY) AND ts < CURDATE()', [$days]);
$nonSignes    = q1($db, 'SELECT COUNT(*) FROM pf_log_seal WHERE signature IS NULL AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$days]);

// Sauvegarde
$dlast = 0;
foreach (glob('/srv/backups/*.tar*') ?: [] as $b) { $dlast = max($dlast, (int) @filemtime($b)); }

// Mise à jour de Bastion
$git    = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-selfupdate state 2>/dev/null'), true) ?: [];
$retard = isset($git['retard']) ? (int) $git['retard'] : null;
$verBastion = (string) ($git['local'] ?? '—');

// Certificat du portail : expiré, il coupe l'accès au portail, donc
// l'identification, donc la journalisation. La conformité tombe avec lui.
$certFin = null;
$cOut = (string) shell_exec("openssl x509 -enddate -noout -in /etc/proxyfibre/bastion.crt 2>/dev/null");
if (preg_match('/notAfter=(.+)/', $cOut, $m) && ($t = strtotime(trim($m[1]))) !== false) { $certFin = $t; }

// Espace disque : un disque plein arrête l'écriture des journaux SANS rien
// interrompre d'autre. Le service paraît fonctionner, et plus rien n'est tracé.
$diskPct = null; $diskLibre = '—';
if (preg_match('~(\d+)%\s+(\S+)~', trim((string) shell_exec("df -Ph / 2>/dev/null | awk 'NR==2{print \$5\" \"\$4}'")), $m)) {
    $diskPct = (int) $m[1]; $diskLibre = $m[2];
}

$filtreActif = trim((string) shell_exec('systemctl is-active dnsmasq 2>/dev/null')) === 'active';

$nGpo = null;
if ($dcUp) {
    $g = trim((string) shell_exec("sudo /usr/local/sbin/proxyfibre-ad gpo list 2>/dev/null | grep -c 'display name'"));
    $nGpo = $g === '' ? null : (int) $g;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CONTRÔLES — chacun rend un avis motivé
// ─────────────────────────────────────────────────────────────────────────────
/**
 * @param string $etat 'ok' | 'warn' | 'ko' | 'na'
 * « na » = contrôle non exécutable. Il ne compte JAMAIS comme conforme : un
 * contrôle qu'on n'a pas pu faire ne prouve rien, et l'afficher en vert serait
 * exactement la fausse assurance que ce rapport doit cesser de produire.
 */
function ctrl(string $obj, string $etat, string $mesure, string $suite = ''): array {
    return ['obj' => $obj, 'etat' => $etat, 'mesure' => $mesure, 'suite' => $suite];
}
$K = [];

// 1. Conservation
if ($ageMax === null) {
    $K[] = ctrl('Conservation des journaux', 'na', 'aucune trace en base — vérification impossible',
        "Vérifier que la journalisation fonctionne : sans journaux, aucune obligation de traçabilité n'est remplie.");
} elseif ($ageMax > $retenu + 2) {
    $K[] = ctrl('Conservation des journaux', 'ko',
        "trace la plus ancienne : {$ageMax} j, au-delà des {$retenu} j retenus",
        "Des données personnelles sont conservées plus longtemps que la durée fixée. Lancer « proxyfibre-purge-logs » et contrôler la tâche planifiée.");
} else {
    $K[] = ctrl('Conservation des journaux', 'ok',
        "trace la plus ancienne : {$ageMax} j (durée retenue : {$retenu} j)");
}

// 2. Purge automatique
$agePurge = $dernPurge ? (int) floor((time() - strtotime($dernPurge)) / 86400) : null;
if (!$cronPurge) {
    $K[] = ctrl('Purge automatique', 'ko', 'aucune tâche planifiée',
        "Sans elle, la durée de conservation dérive sans que rien ne le signale.");
} elseif ($agePurge === null) {
    $K[] = ctrl('Purge automatique', 'warn', 'planifiée, jamais exécutée à ce jour',
        "Attendu sur une installation récente ; à recontrôler lorsque la durée de conservation sera atteinte.");
} elseif ($agePurge > 2) {
    $K[] = ctrl('Purge automatique', 'warn', "dernière exécution il y a {$agePurge} j",
        "La tâche est quotidienne : un tel écart évoque un échec silencieux.");
} else {
    $K[] = ctrl('Purge automatique', 'ok', 'exécutée le ' . date('d/m/Y', strtotime($dernPurge)));
}

// 3. Intégrité
if ($joursScelles === null || $joursActifs === null) {
    $K[] = ctrl('Intégrité des journaux', 'na', 'état des scellés illisible');
} elseif ($joursActifs > 0 && $joursScelles < $joursActifs) {
    $K[] = ctrl('Intégrité des journaux', 'ko',
        "{$joursScelles} journée(s) scellée(s) pour {$joursActifs} journée(s) d'activité",
        "Une journée non scellée ne peut plus être présentée comme non altérée. Contrôler la tâche de scellement.");
} elseif (($nonSignes ?? 0) > 0) {
    $K[] = ctrl('Intégrité des journaux', 'warn', "{$joursScelles} journée(s) scellée(s), dont {$nonSignes} sans signature",
        "Le scellé existe mais n'est pas signé : la chaîne tient, la preuve d'origine est plus faible.");
} else {
    $K[] = ctrl('Intégrité des journaux', 'ok', "{$joursScelles} journée(s) scellée(s) et signée(s)");
}

// 4. Sauvegarde
$ageSauv = $dlast ? (int) floor((time() - $dlast) / 86400) : null;
if ($ageSauv === null) {
    $K[] = ctrl('Sauvegarde', 'ko', 'aucune sauvegarde trouvée',
        "Une panne de disque entraînerait la perte des journaux, donc de la traçabilité elle-même.");
} elseif ($ageSauv > 7) {
    $K[] = ctrl('Sauvegarde', 'warn', "la plus récente date de {$ageSauv} j", 'Relancer une sauvegarde.');
} else {
    $K[] = ctrl('Sauvegarde', 'ok', 'du ' . date('d/m/Y', $dlast));
}

// 5. Base antivirale
$ageAv = $avBase ? (int) floor((time() - $avBase) / 86400) : null;
if ($ageAv === null) {
    $K[] = ctrl('Base antivirale', 'na', 'base introuvable');
} elseif ($ageAv > 7) {
    $K[] = ctrl('Base antivirale', 'ko', "mise à jour il y a {$ageAv} j",
        "Une base ancienne laisse passer les menaces récentes tout en affichant « analyse terminée ».");
} elseif ($ageAv > 2) {
    $K[] = ctrl('Base antivirale', 'warn', "mise à jour il y a {$ageAv} j");
} else {
    $K[] = ctrl('Base antivirale', 'ok', 'à jour (' . date('d/m/Y', $avBase) . ')');
}

// 6. Menaces
if ($avThreats === null) {
    $K[] = ctrl('Menaces détectées', 'na', 'relevé indisponible');
} elseif ($avThreats > 0) {
    $K[] = ctrl('Menaces détectées', 'warn', "{$avThreats} sur la période (" . nb($avScans) . ' analyse(s))',
        'Vérifier le traitement de chaque détection dans la page Antivirus.');
} else {
    $K[] = ctrl('Menaces détectées', 'ok', 'aucune sur ' . nb($avScans) . ' analyse(s)');
}

// 7. Version
if ($retard === null) {
    $K[] = ctrl('Version de Bastion', 'na', 'état des mises à jour indisponible');
} elseif ($retard > 0) {
    $K[] = ctrl('Version de Bastion', 'warn', "{$retard} correctif(s) en attente (version {$verBastion})",
        'Appliquer la mise à jour depuis la page Système.');
} else {
    $K[] = ctrl('Version de Bastion', 'ok', "à jour (version {$verBastion})");
}

// 8. Certificat
if ($certFin === null) {
    $K[] = ctrl('Certificat du portail', 'na', 'certificat illisible');
} else {
    $j = (int) floor(($certFin - time()) / 86400);
    if ($j < 0) {
        $K[] = ctrl('Certificat du portail', 'ko', 'EXPIRÉ depuis ' . abs($j) . ' j',
            "Le portail n'est plus joignable en HTTPS : plus d'identification, donc plus de journalisation.");
    } elseif ($j < 30) {
        $K[] = ctrl('Certificat du portail', 'warn', "expire dans {$j} j", 'Renouveler avant échéance.');
    } else {
        $K[] = ctrl('Certificat du portail', 'ok', 'valide jusqu\'au ' . date('d/m/Y', $certFin));
    }
}

// 9. Espace disque
if ($diskPct === null) {
    $K[] = ctrl('Espace disque', 'na', 'occupation illisible');
} elseif ($diskPct >= 90) {
    $K[] = ctrl('Espace disque', 'ko', "{$diskPct} % occupé ({$diskLibre} libre)",
        "Un disque plein arrête l'écriture des journaux sans rien interrompre d'autre : le service paraît fonctionner et plus rien n'est tracé.");
} elseif ($diskPct >= 80) {
    $K[] = ctrl('Espace disque', 'warn', "{$diskPct} % occupé ({$diskLibre} libre)");
} else {
    $K[] = ctrl('Espace disque', 'ok', "{$diskPct} % occupé ({$diskLibre} libre)");
}

// 10. Filtrage
$K[] = $filtreActif
    ? ctrl('Filtrage de contenu', 'ok', nb($nBlock) . ' domaine(s) bloqué(s), service actif')
    : ctrl('Filtrage de contenu', 'ko', 'service DNS arrêté', "Aucun filtrage n'est appliqué tant qu'il est arrêté.");

// 11. Traçabilité des actions d'administration
if ($nAudit === null) {
    $K[] = ctrl("Traçabilité des actions d'administration", 'na', "journal d'audit illisible");
} elseif ($nAudit === 0) {
    $K[] = ctrl("Traçabilité des actions d'administration", 'warn', 'aucune action tracée sur la période',
        "Plausible si la console n'a pas servi ; à confirmer, car un journal vide et un journal cassé se ressemblent.");
} else {
    $K[] = ctrl("Traçabilité des actions d'administration", 'ok', nb($nAudit) . ' action(s) tracée(s)');
}

$nOk = $nWarn = $nKo = $nNa = 0;
foreach ($K as $k) {
    if ($k['etat'] === 'ok') { $nOk++; } elseif ($k['etat'] === 'warn') { $nWarn++; }
    elseif ($k['etat'] === 'ko') { $nKo++; } else { $nNa++; }
}
$verdict = $nKo > 0 ? 'ko' : (($nWarn > 0 || $nNa > 0) ? 'warn' : 'ok');
$LIB = ['ok' => 'Conforme', 'warn' => 'À surveiller', 'ko' => 'Non conforme', 'na' => 'Non vérifié'];
$PIC = ['ok' => '✔', 'warn' => '⚠', 'ko' => '✖', 'na' => '?'];

pf_header('Rapport de conformité', 'rapport.php');
?>
<style>
  .rep-tools{margin-bottom:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
  .rep{background:var(--panel);border:1px solid var(--line);border-radius:14px;
       padding:2rem 2.2rem;max-width:900px;margin:0 auto}
  .rep .entete{display:flex;align-items:flex-start;gap:.9rem;border-bottom:2px solid var(--line);padding-bottom:1rem}
  .rep h1{font-size:1.35rem;margin:0;letter-spacing:-.01em}
  .rep .sub{color:var(--muted);font-size:.82rem;margin:.25rem 0 0;line-height:1.55}
  .rep h2{font-size:.75rem;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);
          margin:1.8rem 0 .7rem;padding-bottom:.3rem;border-bottom:1px solid var(--line);font-weight:700}

  /* Verdict d'ensemble : la première chose lue, et souvent la seule. */
  .verdict{display:flex;align-items:center;gap:1.1rem;margin-top:1.2rem;padding:1rem 1.2rem;
           border-radius:12px;border:1px solid;flex-wrap:wrap}
  .verdict.ok{background:rgba(74,222,128,.08);border-color:rgba(74,222,128,.35)}
  .verdict.warn{background:rgba(234,179,8,.08);border-color:rgba(234,179,8,.35)}
  .verdict.ko{background:rgba(248,113,113,.09);border-color:rgba(248,113,113,.4)}
  .verdict .pastille{font-size:1.8rem;line-height:1}
  .verdict .t{font-weight:700;font-size:1.05rem}
  .verdict .d{color:var(--muted);font-size:.84rem;margin-top:.15rem}
  .compte{margin-left:auto;display:flex;gap:.45rem;flex-wrap:wrap}
  .compte b{padding:.3rem .65rem;border-radius:8px;font-size:.77rem;
            border:1px solid var(--line);background:var(--bg);font-weight:600}

  .ctrl{width:100%;border-collapse:collapse;font-size:.87rem}
  .ctrl th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.6px;
           color:var(--muted);padding:.4rem .5rem;border-bottom:1px solid var(--line);font-weight:700}
  .ctrl td{padding:.55rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
  .ctrl tr:last-child td{border-bottom:0}
  .ctrl .objet{font-weight:600;width:32%}
  .ctrl .avis{width:8.6rem;white-space:nowrap;font-weight:600}
  .a-ok{color:#4ade80} .a-warn{color:#eab308} .a-ko{color:#f87171} .a-na{color:var(--muted)}
  .suite{display:block;color:var(--muted);font-size:.8rem;margin-top:.25rem;line-height:1.5}

  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.7rem}
  .stat{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:.75rem .9rem}
  .stat .v{font-size:1.4rem;font-weight:700;line-height:1.15}
  .stat .l{color:var(--muted);font-size:.76rem;margin-top:.25rem;line-height:1.4}
  .ev{font-size:.7rem;color:var(--muted);font-weight:600;margin-left:.35rem}

  .reserve{margin-top:1.6rem;padding:.85rem 1rem;border-left:3px solid var(--line);
           background:var(--bg);border-radius:0 8px 8px 0;font-size:.8rem;color:var(--muted);line-height:1.65}
  .visa{margin-top:1.8rem;display:grid;grid-template-columns:1fr 1fr;gap:2.5rem}
  .visa .case{border-top:1px solid var(--line);padding-top:.5rem;font-size:.77rem;color:var(--muted)}
  .visa .ligne{height:2.6rem}
  .pied{margin-top:1.4rem;border-top:1px solid var(--line);padding-top:.7rem;
        color:var(--muted);font-size:.73rem;line-height:1.6}

  /* ── IMPRESSION ────────────────────────────────────────────────────────────
     Ce document est fait pour être imprimé, visé et classé. Un bloc coupé par
     un saut de page devient illisible dans un classeur : un contrôle dont
     l'avis part à la page suivante ne veut plus rien dire. */
  @media print{
    @page{size:A4;margin:14mm 13mm 16mm}
    .sidebar,.topbar,.rep-tools,.nav-backdrop{display:none!important}
    .content{margin:0!important;padding:0!important}
    body{background:#fff!important;color:#000!important;font-size:10.5pt}
    .rep{border:none;max-width:none;padding:0;background:#fff!important}
    .rep h1,.ctrl td,.stat .v{color:#000!important}
    .rep h2{color:#333!important;border-bottom-color:#999}
    .ctrl th,.ctrl td{border-bottom-color:#ccc}
    .stat,.verdict,.reserve{background:#fff!important;border-color:#999!important}
    .a-ok{color:#15803d!important} .a-warn{color:#a16207!important} .a-ko{color:#b91c1c!important}
    .ctrl tr,.stat,.verdict,.visa,.reserve{break-inside:avoid;page-break-inside:avoid}
    .rep h2{break-after:avoid;page-break-after:avoid}
    .visa{break-before:avoid}
    /* L'en-tête du tableau se répète en haut de chaque page : sinon la seconde
       page présente une colonne d'avis sans dire de quoi elle parle. */
    .ctrl thead{display:table-header-group}
  }
</style>

<div class="rep-tools">
  <form method="get" style="display:flex;gap:.5rem;align-items:center;margin:0">
    <label class="muted small">Période :</label>
    <select name="days" onchange="this.form.submit()" style="padding:.4rem;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:8px">
      <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 366 => '1 an'] as $d => $l): ?>
        <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <button type="button" class="btn" onclick="window.print()">🖨️ Imprimer / PDF</button>
  <span class="muted small">« Imprimer » → « Enregistrer au format PDF » pour archiver.</span>
</div>

<div class="rep">
  <div class="entete">
    <img src="/assets/bastion-icon.svg" alt="" style="width:38px;height:38px;flex:none">
    <div>
      <h1>Rapport de conformité — Bastion</h1>
      <p class="sub">
        Passerelle de contrôle d'accès au réseau<br>
        Période du <strong><?= e($since) ?></strong> au <strong><?= e(date('d/m/Y')) ?></strong>
        (<?= $days ?> jours) · édité le <?= e(date('d/m/Y à H:i')) ?> par <?= e($_SESSION['admin'] ?? '') ?>
      </p>
    </div>
  </div>

  <div class="verdict <?= $verdict ?>">
    <span class="pastille"><?= $PIC[$verdict] ?></span>
    <div>
      <div class="t"><?= $verdict === 'ok' ? 'Aucun écart constaté'
                       : ($verdict === 'ko' ? 'Écarts à corriger' : 'Points à surveiller') ?></div>
      <div class="d"><?= count($K) ?> contrôles automatiques réalisés sur la période.</div>
    </div>
    <div class="compte">
      <b class="a-ok">✔ <?= $nOk ?> conformes</b>
      <?php if ($nWarn): ?><b class="a-warn">⚠ <?= $nWarn ?> à surveiller</b><?php endif; ?>
      <?php if ($nKo): ?><b class="a-ko">✖ <?= $nKo ?> non conformes</b><?php endif; ?>
      <?php if ($nNa): ?><b class="a-na">? <?= $nNa ?> non vérifiés</b><?php endif; ?>
    </div>
  </div>

  <h2>Contrôles</h2>
  <table class="ctrl">
    <thead><tr><th class="objet">Objet du contrôle</th><th class="avis">Avis</th><th>Constat</th></tr></thead>
    <tbody>
      <?php
      // Les écarts d'abord : un rapport se lit rarement en entier, et ce qui
      // appelle une action ne doit pas dépendre de la patience du lecteur.
      $ordre = ['ko' => 0, 'warn' => 1, 'na' => 2, 'ok' => 3];
      usort($K, fn($a, $b) => $ordre[$a['etat']] <=> $ordre[$b['etat']]);
      foreach ($K as $k): ?>
        <tr>
          <td class="objet"><?= e($k['obj']) ?></td>
          <td class="avis a-<?= $k['etat'] ?>"><?= $PIC[$k['etat']] ?> <?= $LIB[$k['etat']] ?></td>
          <td><?= e($k['mesure']) ?>
            <?php if ($k['suite'] !== ''): ?><span class="suite">→ <?= e($k['suite']) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Comptes et droits</h2>
  <div class="grid">
    <div class="stat"><div class="v"><?= nb($nUsers) ?></div><div class="l">accès Internet (portail)</div></div>
    <div class="stat"><div class="v"><?= nb($nAd) ?></div><div class="l">comptes de domaine<?= $dcUp ? '' : ' (annuaire arrêté)' ?></div></div>
    <div class="stat"><div class="v"><?= nb($nAdmins) ?></div><div class="l">administrateurs de la console</div></div>
    <div class="stat"><div class="v"><?= nb($nGrp) ?></div><div class="l">groupes (quotas et horaires)</div></div>
    <div class="stat"><div class="v"><?= nb($nGpo) ?></div><div class="l">stratégies de groupe déployées</div></div>
  </div>

  <h2>Activité sur la période — écart avec les <?= $days ?> jours précédents</h2>
  <div class="grid">
    <div class="stat"><div class="v"><?= nb($nConn) ?><?= evol($nConn, $nConnP) ?></div><div class="l">connexions au portail</div></div>
    <div class="stat"><div class="v"><?= nb($nWeb) ?><?= evol($nWeb, $nWebP) ?></div><div class="l">visites journalisées</div></div>
    <div class="stat"><div class="v"><?= nb($nEchec) ?></div><div class="l">authentifications refusées</div></div>
    <div class="stat"><div class="v"><?= nb($avScans) ?></div><div class="l">analyses antivirus</div></div>
  </div>

  <div class="reserve">
    <strong>Portée de ce document.</strong> Auto-évaluation technique produite par l'outil à partir de
    ses propres relevés : elle constate l'état du dispositif, elle ne vaut pas certification juridique.
    Bastion peut établir que les traces les plus anciennes ont <?= $ageMax === null ? '—' : $ageMax ?> jours ;
    il ne lui appartient pas de dire si cette durée est celle qui s'impose au service. La qualification
    du traitement, la durée de conservation applicable et l'information des personnes relèvent du
    responsable de traitement. Les journaux détaillés sont consultables et exportables depuis la page
    Journalisation.
  </div>

  <div class="visa">
    <div class="case"><div class="ligne"></div>Rédacteur — <?= e($_SESSION['admin'] ?? '') ?>, le <?= e(date('d/m/Y')) ?></div>
    <div class="case"><div class="ligne"></div>Visa du chef de service — date et signature</div>
  </div>

  <div class="pied">
    Document généré automatiquement par Bastion <?= e($verBastion) ?> — © 2026 Mickaël MONESTIER (Mle 110.480).
    Édition du <?= e(date('d/m/Y à H:i')) ?>. Toute réédition ultérieure reflétera l'état du dispositif à sa date.
  </div>
</div>
<?php pf_footer(); ?>
