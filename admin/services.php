<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — état et pilotage des services système (liste blanche). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

// Services gérés : unit systemd => [nom, rôle, type].
//   type 'daemon'  : service permanent (état = actif/inactif)
//   type 'oneshot' : tâche ponctuelle (état = dernier résultat)
$SVCS = [
    'opennds'                  => ['Portail captif',        'OpenNDS — authentification & pare-feu client', 'daemon'],
    'freeradius'               => ['Authentification',      'FreeRADIUS — validation des comptes',          'daemon'],
    'mariadb'                  => ['Base de données',       'MariaDB — comptes, journaux, réglages',        'daemon'],
    'apache2'                  => ['Serveur web',           'Portail + console admin (PHP)',                'web'],
    'dnsmasq'                  => ['DHCP / DNS / PXE',      'Adressage LAN, résolution, amorçage réseau',   'daemon'],
    'chrony'                   => ['Serveur de temps',      'NTP — horloge de référence du réseau',         'daemon'],
    'proxyfibre-weblog'        => ['Historique navigation', 'Journalise les visites DNS des utilisateurs',  'daemon'],
    'samba-ad-dc'              => ['Active Directory',     'Contrôleur de domaine (Samba)',                'daemon'],
    'proxyfibre-kms'           => ['Activation KMS',       'Activation Windows / Office (vlmcsd)',          'daemon'],
    'clamav-daemon'            => ['Antivirus',            'Moteur ClamAV temps réel',                     'daemon'],
    'proxyfibre-walledgarden'  => ['Walled garden',        'Ouvre les serveurs de mise à jour / NTP',      'oneshot'],
];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'smtp') {
    csrf_check();
    // ── LE MOT DE PASSE NE PASSE PAS PAR LA LIGNE DE COMMANDE ────────────────
    // Un mot de passe en argument est lisible par n'importe qui dans « ps » le
    // temps de l'exécution, et il atterrit dans les journaux d'audit du système.
    // Les valeurs sont donc écrites sur l'ENTRÉE STANDARD du script, dans un
    // tuyau que seuls les deux processus partagent.
    $champs = [
        'host' => trim((string) ($_POST['smtp_host'] ?? '')),
        'port' => trim((string) ($_POST['smtp_port'] ?? '587')),
        'from' => trim((string) ($_POST['smtp_from'] ?? '')),
        'user' => trim((string) ($_POST['smtp_user'] ?? '')),
        'tls'  => ($_POST['smtp_tls'] ?? 'starttls') === 'ssl' ? 'ssl' : 'starttls',
    ];
    $mdp = (string) ($_POST['smtp_pass'] ?? '');

    if ($champs['host'] === '' || !filter_var($champs['from'], FILTER_VALIDATE_EMAIL)) {
        $flash = ["Serveur et adresse d'expédition sont obligatoires.", 'err'];
    } else {
        $lignes = '';
        foreach ($champs as $k => $v) { $lignes .= $k . '=' . str_replace("\n", '', $v) . "\n"; }
        // Champ laissé vide = on garde le mot de passe déjà enregistré. Sans
        // cela, corriger un simple numéro de port obligerait à le ressaisir, et
        // l'oublier écraserait en silence une configuration qui fonctionnait.
        $lignes .= $mdp !== '' ? 'pass=' . str_replace("\n", '', $mdp) . "\n" : "keeppass=1\n";

        $p = proc_open('sudo /usr/local/sbin/proxyfibre-mail config 2>&1',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        $r = '';
        if (is_resource($p)) {
            fwrite($tuyaux[0], $lignes);
            fclose($tuyaux[0]);
            $r = trim((string) stream_get_contents($tuyaux[1]));
            fclose($tuyaux[1]); fclose($tuyaux[2]);
            proc_close($p);
        }
        $ok = strpos($r, 'OK:') === 0;
        // Le journal d'audit retient le SERVEUR et l'expéditeur, jamais le mot
        // de passe : tracer qui a changé le relais est utile, le recopier non.
        audit('alerte.smtp', ($ok ? 'relais enregistré — ' : 'ÉCHEC — ')
            . $champs['host'] . ':' . $champs['port'] . ' · ' . $champs['from']);
        $flash = [$ok ? "Relais enregistré. Envoyez un message de test pour le vérifier réellement."
                      : ($r !== '' ? $r : 'Échec inconnu.'), $ok ? 'ok' : 'err'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'mailtest') {
    csrf_check();
    // ── LE SEUL CONTRÔLE QUI VAILLE ──────────────────────────────────────────
    // Un relais accepté par la configuration peut refuser à l'usage : mot de
    // passe changé, port filtré par la box, expéditeur rejeté par le fournisseur.
    // Rien de tout cela ne se voit avant d'avoir réellement envoyé.
    $dst = (string) (pf_db()->query("SELECT v FROM pf_settings WHERE k='alert_email'")->fetchColumn() ?: '');
    if (trim($dst) === '') {
        $flash = ["Enregistrez d'abord une adresse à prévenir.", 'err'];
    } else {
        $r = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-mail test '
            . escapeshellarg($dst) . ' 2>&1'));
        $ok = strpos($r, 'OK:') === 0;
        audit('alerte.test-courriel', $ok ? 'remis au relais — ' . $dst : 'ÉCHEC — ' . $r);
        $flash = [$ok ? "Message remis au relais pour {$dst}. Vérifiez la boîte de réception — "
                      . "un message accepté par le relais peut encore être rejeté plus loin."
                      : $r, $ok ? 'ok' : 'err'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alert_email'])) {
    csrf_check();
    $mail = trim((string) $_POST['alert_email']);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $flash = ['Adresse électronique invalide.', 'err'];
    } else {
        pf_db()->prepare("INSERT INTO pf_settings (k,v) VALUES ('alert_email',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
               ->execute([$mail]);
        $flash = [$mail === '' ? 'Notification par courriel désactivée.' : "Les alertes seront envoyées à {$mail}.", 'ok'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $svc    = (string) ($_POST['svc'] ?? '');
    $action = (string) ($_POST['do'] ?? '');
    if (!isset($SVCS[$svc])) {
        $flash = ['Service inconnu.', 'err'];
    } elseif (!in_array($action, ['start', 'stop', 'restart', 'reload'], true)) {
        $flash = ['Action invalide.', 'err'];
    } else {
        $out = shell_exec('sudo /usr/local/sbin/proxyfibre-service '
            . escapeshellarg($action) . ' ' . escapeshellarg($svc) . ' 2>&1');
        $verb = ['start' => 'démarré', 'stop' => 'arrêté', 'restart' => 'redémarré', 'reload' => 'rechargé'][$action];
        if ($svc === 'apache2') {
            $flash = ['Serveur web : redémarrage planifié (~2 s). La page va se recharger…', 'ok'];
        } else {
            $flash = ["Service « {$SVCS[$svc][0]} » {$verb}." . (trim((string) $out) !== '' ? ' — ' . trim((string) $out) : ''), 'ok'];
        }
    }
}

// ── Lecture de l'état courant ────────────────────────────────────────────────
function svc_state(string $unit, string $type): array {
    if ($type === 'oneshot') {
        $res = trim((string) shell_exec('systemctl show -p Result --value ' . escapeshellarg($unit) . ' 2>/dev/null'));
        $ok  = ($res === 'success' || $res === '');
        return [$ok ? 'ok' : 'ko', $ok ? 'Exécuté' : 'Échec', false];
    }
    $active = trim((string) shell_exec('systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null'));
    if ($active === 'active')     { return ['ok', 'Actif', true]; }
    if ($active === 'activating') { return ['warn', 'Démarrage…', true]; }
    if ($active === 'failed')     { return ['ko', 'En échec', false]; }
    return ['ko', 'Arrêté', false];
}
function svc_enabled(string $unit): string {
    $e = trim((string) shell_exec('systemctl is-enabled ' . escapeshellarg($unit) . ' 2>/dev/null'));
    return $e ?: 'inconnu';
}
$uptime = trim((string) shell_exec('uptime -p 2>/dev/null'));
$load   = trim((string) shell_exec("cat /proc/loadavg 2>/dev/null | awk '{print $1\", \"$2\", \"$3}'"));

$states = [];
$nbOk = 0;
foreach ($SVCS as $unit => [$name, $role, $type]) {
    $states[$unit] = svc_state($unit, $type);
    if ($states[$unit][0] === 'ok') { $nbOk++; }
}
$total = count($SVCS);

// Actualisation automatique (JS) + consultation du journal d'un service.
$auto     = ($_GET['auto'] ?? '') === '1';
$logsUnit = (string) ($_GET['logs'] ?? '');
if ($logsUnit !== '' && !isset($SVCS[$logsUnit])) { $logsUnit = ''; }
$logsTxt = '';
if ($logsUnit !== '') {
    $logsTxt = (string) shell_exec('sudo /usr/local/sbin/proxyfibre-service logs ' . escapeshellarg($logsUnit) . ' 80 2>&1');
    if (trim($logsTxt) === '') { $logsTxt = '(journal vide)'; }
}

pf_header('Services', 'services.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
$badge = fn(string $s) => $s === 'ok' ? 'on' : ($s === 'warn' ? 'warn' : 'off');
?>
<style>
  .svc-badge.warn{background:rgba(234,179,8,.15);color:#eab308;border-color:rgba(234,179,8,.35)}
  .svc-actions{display:flex;gap:.4rem;justify-content:flex-end;flex-wrap:wrap}
  .svc-meta{font-family:ui-monospace,monospace;font-size:.72rem}
</style>

<?php if ($auto && $logsUnit === ''): ?>
<script>setTimeout(function(){location.href='services.php?auto=1';}, 10000);</script>
<?php endif; ?>

<?php if ($logsUnit !== ''): ?>
<section class="panel">
  <div class="panel-head"><h2>📄 Journal — <?= e($SVCS[$logsUnit][0]) ?>
    <span class="muted svc-meta"><?= e($logsUnit) ?></span></h2>
    <span style="display:flex;gap:.4rem">
      <a class="btn-sm" href="services.php?logs=<?= urlencode($logsUnit) ?>">↻ Rafraîchir</a>
      <a class="btn-sm" href="services.php">✕ Fermer</a>
    </span>
  </div>
  <div style="padding:1.2rem">
    <pre style="margin:0;padding:1rem;background:#0b1120;color:#cbd5e1;border:1px solid var(--line);
      border-radius:10px;overflow:auto;max-height:420px;font-family:ui-monospace,monospace;
      font-size:.76rem;line-height:1.5"><?= e($logsTxt) ?></pre>
  </div>
</section>
<?php endif; ?>

<section class="cards">
  <div class="kpi"><div class="kpi-val"><?= $nbOk ?>/<?= $total ?></div><div class="kpi-lbl">Services opérationnels</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1.05rem;line-height:1.5"><?= e($uptime ?: '—') ?></div><div class="kpi-lbl">Disponibilité</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1.05rem"><?= e($load ?: '—') ?></div><div class="kpi-lbl">Charge (1/5/15 min)</div></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>État des services</h2>
    <span style="display:flex;align-items:center;gap:.9rem">
      <label class="muted small" style="display:inline-flex;align-items:center;gap:.4rem;cursor:pointer">
        <input type="checkbox" onchange="location.href='services.php?auto='+(this.checked?1:0)" <?= $auto ? 'checked' : '' ?>>
        Actualisation auto (10 s)
      </label>
      <a class="btn-sm" href="services.php<?= $auto ? '?auto=1' : '' ?>">↻ Actualiser</a>
    </span>
  </div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Service</th><th>État</th><th>Démarrage auto</th><th>Rôle</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($SVCS as $unit => [$name, $role, $type]):
        [$st, $lbl, $isActive] = $states[$unit];
        $en = svc_enabled($unit); ?>
      <tr>
        <td><strong><?= e($name) ?></strong><br><span class="muted svc-meta"><?= e($unit) ?></span></td>
        <td><span class="badge svc-badge <?= $badge($st) ?>"><?= e($lbl) ?></span></td>
        <td><span class="muted svc-meta"><?= e($en) ?></span></td>
        <td class="muted"><?= e($role) ?></td>
        <td>
          <div class="svc-actions">
            <a class="btn-sm" href="services.php?logs=<?= urlencode($unit) ?>" title="Voir le journal">📄 Journal</a>
            <?php if ($type === 'oneshot'): ?>
              <?= svc_btn($unit, 'start', '▶ Relancer') ?>
            <?php elseif ($type === 'web'): ?>
              <?= svc_btn($unit, 'restart', '↻ Redémarrer', 'Redémarrer le serveur web ? La console sera brièvement indisponible.') ?>
            <?php else: ?>
              <?= svc_btn($unit, 'restart', '↻ Redémarrer') ?>
              <?php if ($isActive): ?>
                <?= svc_btn($unit, 'stop', '■ Arrêter', "Arrêter « {$name} » ? Cette fonction sera interrompue.", true) ?>
              <?php else: ?>
                <?= svc_btn($unit, 'start', '▶ Démarrer') ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">
    Actions autorisées uniquement pour ces services (liste blanche côté serveur).
    Le redémarrage du <strong>serveur web</strong> est différé de 2 s pour ne pas couper la console.
  </p>
</section>

<?php
// Surveillance hors console : le bandeau du tableau de bord ne sert à rien si
// personne ne regarde l'écran. proxyfibre-watchdog.timer contrôle chaque minute et
// historise ici ; l'adresse ci-dessous reçoit en plus un courriel.
$alertMail = '';
$hist      = [];
$wdOn      = trim((string) shell_exec('systemctl is-active proxyfibre-watchdog.timer 2>/dev/null')) === 'active';
try {
    $alertMail = (string) (pf_db()->query("SELECT v FROM pf_settings WHERE k='alert_email'")->fetchColumn() ?: '');
    $hist = pf_db()->query("SELECT lvl,txt,opened_at,closed_at FROM pf_alerts ORDER BY id DESC LIMIT 8")->fetchAll();
} catch (Throwable $e) { /* table absente tant que le watchdog n'a pas tourné */ }
// L'état est demandé au script, qui vérifie AUSSI que le relais est renseigné :
// « msmtp installé » ne veut pas dire « capable d'envoyer ». Le modèle posé à
// l'installation contient des valeurs à remplacer ; le prendre pour une
// configuration valide produirait un « tout va bien » mensonger.
$mailEtat  = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-mail state 2>/dev/null'), true) ?: [];
$hasMta    = !empty($mailEtat['mta']);
$mailPret  = $hasMta && !empty($mailEtat['configure']);
$mailRelai = trim((string) ($mailEtat['host'] ?? ''));
?>
<section class="panel">
  <div class="panel-head"><h2>🔔 Surveillance et alertes</h2>
    <span class="badge <?= $wdOn ? 'on' : 'off' ?>"><?= $wdOn ? 'active — contrôle chaque minute' : 'inactive' ?></span>
  </div>
  <div style="padding:1.2rem">
    <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field" style="flex:1;min-width:18rem;margin:0">Adresse à prévenir en cas d'anomalie
        <input type="email" name="alert_email" value="<?= e($alertMail) ?>" placeholder="chef.poste@interieur.gouv.fr">
      </label>
      <button class="btn">Enregistrer</button>
    </form>
    <?php if ($alertMail !== '' && !$mailPret): ?>
      <div class="err" style="margin:.8rem 0 0">
        <strong>Cette adresse ne reçoit rien.</strong>
        <?php if (!$hasMta): ?>
          Aucun agent de messagerie n'est installé sur la passerelle.
        <?php else: ?>
          Le relais SMTP n'est pas renseigné dans <code>/etc/msmtprc</code> (le modèle contient
          encore ses valeurs à remplacer).
        <?php endif; ?>
        Les anomalies continuent d'être historisées ici et écrites dans le journal système,
        mais <strong>personne n'est prévenu</strong>.
      </div>
    <?php elseif ($alertMail !== '' && $mailPret): ?>
      <div class="ok" style="margin:.8rem 0 0">
        Envoi possible via <strong><?= e($mailRelai) ?></strong>.
        Un test réel reste la seule preuve : le relais peut refuser à l'usage.
      </div>
    <?php endif; ?>

    <details style="margin:.9rem 0 0" <?= $mailPret ? '' : 'open' ?>>
      <summary style="cursor:pointer;font-weight:600;font-size:.92rem">
        ⚙️ Serveur d'envoi (SMTP)
        <span class="muted small" style="font-weight:400">
          <?= $mailPret ? '— configuré : ' . e($mailRelai) : '— à renseigner, sans quoi rien ne part' ?>
        </span>
      </summary>
      <form method="post" style="margin:.8rem 0 0;display:grid;gap:.7rem;
                                 grid-template-columns:repeat(auto-fit,minmax(190px,1fr));align-items:end">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="smtp">
        <?php
        // ── LE CHOIX DU FOURNISSEUR ÉVITE L'ERREUR LA PLUS COURANTE ──────────
        // Serveur, port et chiffrement vont par TROIS : 587 avec STARTTLS, ou 465
        // avec SSL direct. Les mélanger donne un échec dont le message ne dit
        // rien d'utile (« connexion réinitialisée »), et l'on cherche du côté du
        // mot de passe. Autant les poser ensemble.
        ?>
        <label class="field" style="margin:0;grid-column:1/-1">Fournisseur
          <select id="smtpPreset" onchange="smtpPreremplir(this.value)">
            <option value="">— choisir pour pré-remplir, ou saisir à la main —</option>
            <optgroup label="Relais gratuits, prevus pour l'envoi automatique">
              <option value="brevo">Brevo — 300 messages/jour</option>
              <option value="mailjet">Mailjet — 200 messages/jour</option>
              <option value="smtp2go">SMTP2GO — 1 000 messages/mois</option>
            </optgroup>
            <option value="gmail">Gmail / Google Workspace</option>
            <option value="ms">Outlook.com / Microsoft 365</option>
            <option value="orange">Orange</option>
            <option value="free">Free</option>
            <option value="sfr">SFR</option>
            <option value="ovh">OVH</option>
          </select>
        </label>
        <div id="smtpAide" class="hint" style="grid-column:1/-1;margin:0;display:none"></div>
        <label class="field" style="margin:0">Serveur
          <input name="smtp_host" value="<?= e($mailRelai) ?>" placeholder="smtp.exemple.fr" required>
        </label>
        <label class="field" style="margin:0">Port
          <input name="smtp_port" type="number" min="1" max="65535"
                 value="<?= e((string) ($mailEtat['port'] ?? '587')) ?>">
        </label>
        <label class="field" style="margin:0">Chiffrement
          <select name="smtp_tls">
            <option value="starttls">STARTTLS (port 587)</option>
            <option value="ssl" <?= ((string) ($mailEtat['tls'] ?? '')) === 'ssl' ? 'selected' : '' ?>>SSL/TLS direct (port 465)</option>
          </select>
        </label>
        <label class="field" style="margin:0">Adresse d'expédition
          <input name="smtp_from" type="email" value="<?= e((string) ($mailEtat['from'] ?? '')) ?>"
                 placeholder="bastion@exemple.fr" required>
        </label>
        <label class="field" style="margin:0">Identifiant
          <input name="smtp_user" value="<?= e((string) ($mailEtat['user'] ?? '')) ?>"
                 placeholder="(vide = l'adresse d'expédition)">
        </label>
        <label class="field" style="margin:0">Mot de passe
          <input name="smtp_pass" type="password" autocomplete="new-password"
                 placeholder="<?= $mailPret ? 'inchangé si laissé vide' : 'requis' ?>">
        </label>
        <div><button class="btn">Enregistrer le relais</button></div>
      </form>
      <p class="hint" style="margin:.6rem 0 0">
        Le mot de passe n'est <strong>jamais réaffiché</strong> : laissez le champ vide pour conserver celui
        déjà enregistré. Il est écrit dans <code>/etc/msmtprc</code>, en lecture pour le seul compte root,
        et transmis par l'entrée standard — jamais en ligne de commande, où il serait visible dans
        <code>ps</code> et dans les journaux du système.<br>
        Pour une messagerie grand public (Gmail, Outlook…), utilisez un <strong>mot de passe
        d'application</strong>, jamais celui du compte. Et pour des alertes de sécurité d'une passerelle,
        une adresse institutionnelle vaut mieux qu'une boîte personnelle.
      </p>
      <script>
      // Réglages publics des fournisseurs courants. Aucun identifiant ici : ces
      // valeurs sont documentées par chaque opérateur et identiques pour tous.
      var SMTP_PRESETS = {
        // Relais transactionnels : concus pour l'envoi automatise, contrairement
        // a une boite personnelle qui peut bloquer un expediteur inhabituel.
        brevo:  {h:'smtp-relay.brevo.com',      p:587, t:'starttls',
                 a:"Compte gratuit sur <code>brevo.com</code>, puis <em>SMTP &amp; API</em> : l'identifiant et la "
                 + "cle SMTP y sont affiches. 300 messages par jour — tres au-dela de ce que Bastion emet, "
                 + "un message par anomalie.<br>"
                 + "<strong>Reserve :</strong> le contenu des alertes decrit l'etat du dispositif de filtrage et "
                 + "de journalisation. Il transiterait par un prestataire commercial : a valider avec votre SSI."},
        mailjet:{h:'in-v3.mailjet.com',         p:587, t:'starttls',
                 a:"Compte gratuit sur <code>mailjet.com</code>, rubrique <em>SMTP</em> : identifiant = cle API, "
                 + "mot de passe = cle secrete. 200 messages par jour.<br>"
                 + "<strong>Meme reserve</strong> que Brevo : les alertes passent par un tiers."},
        smtp2go:{h:'mail.smtp2go.com',          p:587, t:'starttls',
                 a:"Compte gratuit sur <code>smtp2go.com</code>, puis <em>Sending &gt; SMTP Users</em>. "
                 + "1 000 messages par mois, journaux conserves 5 jours seulement.<br>"
                 + "<strong>Meme reserve</strong> : les alertes passent par un tiers."},
        gmail:  {h:'smtp.gmail.com',            p:587, t:'starttls',
                 a:"<strong>Gmail exige un mot de passe d'application</strong> : le mot de passe de votre compte "
                 + "sera refusé. Activez d'abord la validation en deux étapes, puis créez un mot de passe "
                 + "d'application sur <code>myaccount.google.com/apppasswords</code> et collez-le ci-dessous "
                 + "(16 lettres, les espaces sont sans importance).<br>"
                 + "L'adresse d'expédition doit être <strong>celle du compte Gmail</strong> : Google réécrit "
                 + "toute autre adresse, et le message paraîtrait venir d'ailleurs que de ce qu'on a saisi."},
        ms:     {h:'smtp.office365.com',        p:587, t:'starttls',
                 a:"Microsoft a désactivé l'authentification par mot de passe simple sur beaucoup de locataires. "
                 + "Si l'envoi échoue malgré des identifiants justes, c'est probablement ce blocage : voyez "
                 + "l'administrateur du domaine."},
        orange: {h:'smtp.orange.fr',            p:465, t:'ssl',    a:"Identifiant = adresse Orange complète."},
        free:   {h:'smtp.free.fr',              p:465, t:'ssl',    a:"L'envoi par ce relais suppose une connexion depuis le réseau Free."},
        sfr:    {h:'smtp.sfr.fr',               p:465, t:'ssl',    a:"Identifiant = adresse SFR complète."},
        ovh:    {h:'ssl0.ovh.net',              p:587, t:'starttls', a:"Identifiant = adresse complète du compte de messagerie."}
      };
      function smtpPreremplir(k) {
        var d = SMTP_PRESETS[k], f = document.forms, aide = document.getElementById('smtpAide');
        if (!d) { if (aide) { aide.style.display = 'none'; } return; }
        var q = function (n) { return document.querySelector('[name="' + n + '"]'); };
        // Les champs sont REMPLIS, pas verrouillés : un service peut avoir un
        // relais interne qui ne ressemble à aucun de ces modèles.
        if (q('smtp_host')) { q('smtp_host').value = d.h; }
        if (q('smtp_port')) { q('smtp_port').value = d.p; }
        if (q('smtp_tls'))  { q('smtp_tls').value  = d.t; }
        if (aide) { aide.innerHTML = d.a; aide.style.display = ''; }
      }
      </script>
    </details>

    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin:.8rem 0 0">
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="do" value="mailtest">
        <button class="btn-sm" <?= $alertMail !== '' ? '' : 'disabled title="Enregistrez d\'abord une adresse"' ?>>
          ✉️ Envoyer un message de test
        </button>
      </form>
      <span class="muted small">Il part vers l'adresse ci-dessus et rapporte l'erreur exacte en cas d'échec.</span>
    </div>

    <p class="hint" style="margin:.7rem 0 0">Laisser vide pour ne pas envoyer de courriel. Les anomalies restent de toute
      façon historisées ici et écrites dans le journal système (<code>bastion-watchdog</code>), collectable par une
      supervision de site.
      <?php if (!$hasMta): ?><br>Installation de l'agent : <code>apt-get install msmtp-mta</code>, puis compléter
      <code>/etc/msmtprc</code>. Le mot de passe y figure en clair — le fichier doit rester en <code>600 root:root</code>,
      et pour une messagerie grand public il faut un <strong>mot de passe d'application</strong>, jamais celui du compte.<?php endif; ?>
    </p>

    <h3 style="margin:1.4rem 0 .6rem;font-size:.95rem">Dernières anomalies</h3>
    <?php if (!$hist): ?>
      <p class="muted small" style="margin:0">Aucune anomalie enregistrée — tout va bien depuis la mise en service.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="grid-table">
        <thead><tr><th>Niveau</th><th>Anomalie</th><th>Début</th><th>Fin</th></tr></thead>
        <tbody>
        <?php foreach ($hist as $h): ?>
          <tr>
            <td><span class="badge <?= $h['lvl'] === 'danger' ? 'off' : 'warn' ?>"><?= $h['lvl'] === 'danger' ? 'Alerte' : 'Avertis.' ?></span></td>
            <td><?= e($h['txt']) ?></td>
            <td class="muted small"><?= e(date('d/m/Y H:i', strtotime($h['opened_at']))) ?></td>
            <td class="muted small"><?= $h['closed_at']
                  ? e(date('d/m/Y H:i', strtotime($h['closed_at'])))
                  : '<strong style="color:#f87171">en cours</strong>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php
function svc_btn(string $unit, string $do, string $label, string $confirm = '', bool $danger = false): string {
    $cls = 'btn-sm' . ($danger ? ' btn-danger' : '');
    $onsub = $confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')"' : '';
    return '<form method="post" style="margin:0"' . $onsub . '>'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="svc" value="' . e($unit) . '">'
        . '<input type="hidden" name="do" value="' . e($do) . '">'
        . '<button class="' . $cls . '">' . e($label) . '</button></form>';
}
pf_footer();
