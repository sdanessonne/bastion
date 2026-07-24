<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Assistant de première configuration.
 *
 * Checklist d'onboarding d'une passerelle fraîchement déployée : passe en revue, DANS
 * L'ORDRE, les réglages essentiels (domaine, mots de passe, DHCP, heure, ligne, sauvegarde,
 * premier agent), DÉTECTE ce qui est déjà fait et guide vers la page pour régler le reste.
 * Lecture seule : aucune action destructive, chaque étape renvoie à sa page dédiée.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';

$db = pf_db();

$steps = [];
function step(array &$s, string $title, string $status, string $detail, string $action = '', string $url = ''): void {
    // status : done | todo | warn | info
    $s[] = compact('title', 'status', 'detail', 'action', 'url');
}

// 1) Domaine Active Directory
$realm = strtoupper(trim((string) shell_exec('testparm -s --parameter-name=realm 2>/dev/null')));
$adUp  = (sys_units_active(['samba-ad-dc'])['samba-ad-dc'] ?? '') === 'active';
if ($realm !== '' && $adUp) {
    step($steps, 'Domaine Active Directory', 'done', "Domaine <strong>" . e($realm) . "</strong> actif. Les postes peuvent le rejoindre.", 'Gérer', '/ad.php');
} else {
    step($steps, 'Domaine Active Directory', 'warn', 'Le contrôleur de domaine n\'est pas actif — vérifiez le service.', 'Ouvrir', '/ad.php');
}

// 2) Mot de passe administrateur (console)
$weak = false;
try {
    $h = $db->query("SELECT password_hash FROM pf_admins WHERE username='admin'")->fetchColumn();
    if ($h) { foreach (['admin', 'password', 'changeme', '0000', '123456', 'bastion', 'proxyfibre', 'Admin123'] as $w) {
        if (password_verify($w, (string) $h)) { $weak = true; break; }
    } }
} catch (Throwable $e) {}
if ($weak) {
    step($steps, 'Mot de passe administrateur', 'todo', 'Le compte <code>admin</code> utilise un mot de passe trop courant — changez-le immédiatement.', 'Changer', '/profil.php');
} else {
    step($steps, 'Mot de passe administrateur', 'done', 'Le mot de passe de la console n\'est pas un mot de passe courant.', 'Modifier', '/profil.php');
}

// 3) Mot de passe système (SSH / console) — non détectable, rappel
step($steps, 'Mot de passe système (SSH)', 'info',
    'Non vérifiable automatiquement : assurez-vous que le compte système (accès SSH/console) a un mot de passe long et unique.',
    'Changer', '/systeme.php');

// 4) Service DHCP (attribution d'adresses aux postes)
$dhcpUp = (sys_units_active(['dnsmasq'])['dnsmasq'] ?? '') === 'active';
if ($dhcpUp) {
    step($steps, 'Attribution des adresses (DHCP)', 'done', 'Le service DHCP distribue les adresses au réseau. Vous pouvez réserver des IP fixes par poste.', 'Régler', '/dhcp.php');
} else {
    step($steps, 'Attribution des adresses (DHCP)', 'todo', 'Le service DHCP n\'est pas actif — les postes n\'obtiendront pas d\'adresse.', 'Ouvrir', '/dhcp.php');
}

// 5) Synchronisation de l'heure
$ntp = trim((string) shell_exec('timedatectl show -p NTPSynchronized --value 2>/dev/null'));
if ($ntp === 'yes') {
    step($steps, 'Synchronisation de l\'heure', 'done', 'L\'horloge est synchronisée — indispensable au domaine (Kerberos) et aux journaux.', 'Détails', '/systeme.php');
} else {
    step($steps, 'Synchronisation de l\'heure', 'warn', 'L\'horloge n\'est pas encore synchronisée — un écart > 5 min casse l\'authentification du domaine.', 'Vérifier', '/systeme.php');
}

// 6) Capacité de la ligne Internet (mesure de référence du débit)
$cap = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-speedtest state 2>/dev/null'), true) ?: [];
if (!empty($cap['down'])) {
    step($steps, 'Capacité de la ligne Internet', 'done',
        'Débit de référence mesuré (' . fmtBytes((int) $cap['down']) . '/s ⬇). Sert à afficher l\'usage en pourcentage.', 'Refaire', '/systeme.php');
} else {
    step($steps, 'Capacité de la ligne Internet', 'todo', 'Lancez une mesure du débit de la ligne pour calibrer la supervision réseau.', 'Mesurer', '/systeme.php');
}

// 7) Sauvegarde automatique
$autoBk = strpos((string) shell_exec('sudo /usr/local/sbin/proxyfibre-backup auto status 2>/dev/null'), 'enabled=enabled') !== false;
if ($autoBk) {
    step($steps, 'Sauvegarde automatique', 'done', 'Une sauvegarde périodique est planifiée (configuration + annuaire).', 'Gérer', '/sauvegarde.php');
} else {
    step($steps, 'Sauvegarde automatique', 'todo', 'Activez la sauvegarde automatique pour protéger la configuration et l\'annuaire.', 'Activer', '/sauvegarde.php');
}

// 8) Premier compte agent
$nAgents = 0;
try { $nAgents = (int) $db->query('SELECT COUNT(DISTINCT username) FROM radcheck')->fetchColumn(); } catch (Throwable $e) {}
if ($nAgents > 0) {
    step($steps, 'Comptes des agents', 'done', "$nAgents compte(s) agent créé(s). Les agents peuvent se connecter au portail.", 'Gérer', '/users.php');
} else {
    step($steps, 'Comptes des agents', 'todo', 'Aucun compte agent — créez le premier pour permettre la connexion au portail.', 'Créer', '/users.php');
}

// 9) Revue de sécurité (pointeur, non compté dans la progression)
step($steps, 'Revue de sécurité', 'info', 'Une fois l\'essentiel en place, passez la revue complète (2FA, chiffrement, certificat, minuteries).', 'Ouvrir', '/securite.php');

// Progression : étapes actionnables réglées (done) sur total actionnable (hors info).
$actionnables = array_filter($steps, fn($s) => $s['status'] !== 'info');
$done = count(array_filter($actionnables, fn($s) => $s['status'] === 'done'));
$total = count($actionnables);
$pct = $total ? (int) round(100 * $done / $total) : 0;

pf_header('Assistant de configuration', 'assistant.php');
?>
<style>
  .wz-hero{display:flex;align-items:center;gap:1.3rem;flex-wrap:wrap;padding:1.2rem 1.4rem;border-radius:14px;
    border:1px solid var(--line);background:linear-gradient(120deg,#14324f,#152238);margin-bottom:1.1rem}
  .wz-ring{--p:0;width:74px;height:74px;border-radius:50%;flex:none;display:grid;place-items:center;
    background:conic-gradient(var(--accent) calc(var(--p)*1%),var(--panel2) 0)}
  .wz-ring span{width:56px;height:56px;border-radius:50%;background:var(--panel);display:grid;place-items:center;font-weight:700;font-size:1.05rem}
  .wz-list{display:flex;flex-direction:column;gap:.55rem}
  .wz-item{display:flex;align-items:flex-start;gap:.9rem;padding:.85rem 1rem;border:1px solid var(--line);border-radius:11px;background:var(--bg)}
  .wz-item.todo{border-color:rgba(248,113,113,.4)} .wz-item.warn{border-color:rgba(234,179,8,.35)}
  .wz-num{flex:none;width:1.7rem;height:1.7rem;border-radius:50%;background:var(--panel2);color:var(--muted);
    display:grid;place-items:center;font-size:.8rem;font-weight:700}
  .wz-item.done .wz-num{background:rgba(74,222,128,.15);color:#4ade80}
  .wz-ic{font-size:1.15rem;line-height:1.5;flex:none;width:1.4rem;text-align:center}
  .wz-main{flex:1;min-width:0}.wz-t{font-weight:600;color:var(--text)}
  .wz-d{color:var(--muted);font-size:.87rem;line-height:1.5;margin-top:.12rem}
  .wz-act{flex:none;align-self:center}
</style>

<div class="wz-hero">
  <div class="wz-ring" style="--p:<?= $pct ?>"><span><?= $done ?>/<?= $total ?></span></div>
  <div style="flex:1;min-width:220px">
    <div style="font-size:1.15rem;font-weight:700;color:#fff">Bienvenue — mise en route de la passerelle</div>
    <p class="muted" style="margin:.35rem 0 0;line-height:1.6">Suivez ces étapes pour rendre Bastion opérationnel. Chaque point est
    <strong>détecté automatiquement</strong> ; cliquez pour régler ce qui reste. <?= $pct === 100 ? '🎉 <strong>Tout est prêt.</strong>' : '' ?></p>
  </div>
</div>

<section class="panel">
  <div class="panel-head"><h2>🧭 Étapes de configuration</h2></div>
  <div style="padding:1rem 1.2rem">
    <div class="wz-list">
      <?php $icons = ['done' => '✅', 'todo' => '⬜', 'warn' => '⚠️', 'info' => 'ℹ️']; $n = 0;
      foreach ($steps as $s): $isInfo = $s['status'] === 'info'; if (!$isInfo) { $n++; } ?>
        <div class="wz-item <?= $s['status'] ?>">
          <div class="wz-num"><?= $isInfo ? '·' : $n ?></div>
          <div class="wz-ic"><?= $icons[$s['status']] ?></div>
          <div class="wz-main">
            <div class="wz-t"><?= e($s['title']) ?></div>
            <div class="wz-d"><?= $s['detail'] ?></div>
          </div>
          <?php if ($s['action'] && $s['url']): ?>
            <div class="wz-act"><a class="btn-sm" href="<?= e($s['url']) ?>"><?= e($s['action']) ?> →</a></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php pf_footer(); ?>
