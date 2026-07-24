<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — paramétrage complet du serveur PXE (menu d'installation réseau). */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
$db = pf_db();

// ── Modèle : réglages par défaut (mêmes valeurs que menu.php) ────────────────
$DEF = [
    'pxe_menu_title'      => 'Bastion  -  Installation par le reseau',
    'pxe_timeout'         => '60',
    'pxe_login_timeout'   => '30',
    'pxe_default'         => 'local',
    'pxe_protected'       => '1',
    'pxe_debian_enabled'  => '1', 'pxe_debian_label'  => '[  Debian     ]  Installation reseau',
    'pxe_ubuntu_enabled'  => '1', 'pxe_ubuntu_label'  => '[  Ubuntu     ]  26.04 Desktop',
    'pxe_windows_enabled' => '1', 'pxe_windows_label' => '[  Windows 11 ]  25H2',
    'pxe_local_enabled'   => '1', 'pxe_local_label'   => 'Demarrer sur le disque local',
    'pxe_shell_enabled'   => '1', 'pxe_shell_label'   => 'Console iPXE (avance)',
    'pxe_debian_args'     => 'vga=788 --- quiet',
    'pxe_ubuntu_args'     => 'boot=casper ip=dhcp url=http://{IP}:2080/iso/ubuntu.iso ---',
    'pxe_menu_subtitle'   => '',
    'pxe_custom_enabled'  => '0', 'pxe_custom_label'  => '[  Autre      ]  Systeme personnalise',
    'pxe_custom_kernel'   => '', 'pxe_custom_initrd' => '', 'pxe_custom_args' => '',
];
$ENTRIES = [
    'debian'  => ['Debian', 'Installation réseau (netboot)', true],
    'ubuntu'  => ['Ubuntu', 'Desktop en direct (casper + ISO)', true],
    'windows' => ['Windows 11', 'WinPE via wimboot', false],
    'local'   => ['Disque local', 'Démarrer sur le système déjà installé', false],
    'shell'   => ['Console iPXE', 'Invite avancée (dépannage)', false],
];
$NAMES = ['debian' => 'Debian', 'ubuntu' => 'Ubuntu', 'windows' => 'Windows 11', 'local' => 'Disque local', 'shell' => 'Console iPXE'];
$ALL   = array_keys($ENTRIES);
$strip = fn($s) => trim(str_replace(["\r", "\n"], ' ', (string) $s));

$BANNER = '/var/www/html/boot/menu-bg.png';
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'upload_banner') {
        // Remplace la bannière de fond du menu PXE (PNG conseillé en 1024×768).
        $f = $_FILES['banner'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['Aucun fichier reçu (ou upload trop volumineux).', 'err'];
        } elseif ($f['size'] > 3 * 1024 * 1024) {
            $flash = ['Image trop lourde (max 3 Mo).', 'err'];
        } else {
            $info = @getimagesize($f['tmp_name']);
            if (!$info || $info[2] !== IMAGETYPE_PNG) {
                $flash = ['Format invalide : fournissez une image PNG.', 'err'];
            } elseif (@file_put_contents($BANNER, file_get_contents($f['tmp_name'])) === false) {
                $flash = ["Écriture impossible dans $BANNER (permissions).", 'err'];
            } else {
                @chmod($BANNER, 0644);
                $flash = ["Bannière mise à jour ({$info[0]}×{$info[1]} px)." .
                    ($info[0] != 1024 || $info[1] != 768 ? ' Conseillé : 1024×768.' : ''), 'ok'];
            }
        }
    } else {
        $up = $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        // Textes (titre, libellés, paramètres noyau) — sans saut de ligne.
        foreach (['pxe_menu_title', 'pxe_menu_subtitle', 'pxe_debian_label', 'pxe_ubuntu_label', 'pxe_windows_label',
                  'pxe_local_label', 'pxe_shell_label', 'pxe_debian_args', 'pxe_ubuntu_args',
                  'pxe_custom_label', 'pxe_custom_kernel', 'pxe_custom_initrd', 'pxe_custom_args'] as $k) {
            $up->execute([$k, $strip($_POST[$k] ?? $DEF[$k])]);
        }
        $up->execute(['pxe_custom_enabled', isset($_POST['pxe_custom_enabled']) ? '1' : '0']);
        // Délai (0–3600 s), entrée par défaut, protection.
        $up->execute(['pxe_timeout', (string) max(0, min(3600, (int) ($_POST['pxe_timeout'] ?? 60)))]);
        $up->execute(['pxe_login_timeout', (string) max(0, min(3600, (int) ($_POST['pxe_login_timeout'] ?? 30)))]);
        $def = preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['pxe_default'] ?? 'local')));
        $up->execute(['pxe_default', in_array($def, $ALL, true) ? $def : 'local']);
        $up->execute(['pxe_protected', isset($_POST['pxe_protected']) ? '1' : '0']);
        // Cases « activé » par entrée.
        foreach ($ALL as $k) { $up->execute(["pxe_{$k}_enabled", isset($_POST["pxe_{$k}_enabled"]) ? '1' : '0']); }

        $flash = ['Paramètres PXE enregistrés — pris en compte immédiatement (aucun redémarrage requis).', 'ok'];
    }
}

// ── Chargement des réglages (défauts + surcharges base) ──────────────────────
$S = $DEF;
try { foreach ($db->query("SELECT k,v FROM pf_settings WHERE k LIKE 'pxe\\_%'") as $r) { $S[$r['k']] = $r['v']; } }
catch (Throwable $e) { /* défauts */ }
$val = fn($k) => e($S[$k] ?? ($DEF[$k] ?? ''));
$on  = fn($k) => ($S[$k] ?? '1') === '1';

// ── État des fichiers de démarrage (avec le DÉTAIL des fichiers manquants) ────
$B = '/var/www/html/boot'; $I = '/srv/pxe/iso';
$REQ = [
    'debian'  => ["$B/debian/linux", "$B/debian/initrd.gz"],
    'ubuntu'  => ["$B/ubuntu/vmlinuz", "$B/ubuntu/initrd", "$I/ubuntu.iso"],
    'windows' => ["$B/wimboot", "$B/win11/boot.wim", "$B/win11/BCD", "$B/win11/bootmgr", "$B/win11/boot.sdi"],
    'local'   => [], 'shell' => [],
];
$ready = []; $missing = [];
foreach ($REQ as $k => $files) {
    $miss = array_values(array_filter($files, fn($f) => !is_file($f)));
    $missing[$k] = array_map(fn($f) => str_replace(["$B/", "$I/"], ['', 'iso/'], $f), $miss);
    $ready[$k] = ($k === 'local' || $k === 'shell') ? true : empty($miss);
}
$banner  = is_file("$B/menu-bg.png");
$dnsOn   = trim((string) shell_exec('systemctl is-active dnsmasq 2>/dev/null')) === 'active';
$lanIps  = trim((string) shell_exec("hostname -I 2>/dev/null"));
$lanIp   = '192.168.182.1';
foreach (preg_split('/\s+/', $lanIps) as $ip) { if (str_starts_with($ip, '192.168.182.')) { $lanIp = $ip; break; } }

// Synthèse pour l'en-tête.
$osReady   = (int) $ready['debian'] + (int) $ready['ubuntu'] + (int) $ready['windows'];
$curDefault = $S['pxe_default'] ?? 'local';
$defReady   = $ready[$curDefault] ?? true;         // l'entrée par défaut est-elle amorçable ?
$defEnabled = $on("pxe_{$curDefault}_enabled");    // …et activée ?

// Aperçu en direct : rendu réel du menu iPXE (endpoint local, sans login).
$ctx     = stream_context_create(['http' => ['timeout' => 3]]);
$preview = @file_get_contents('http://127.0.0.1:2080/boot/menu.php?preview=1', false, $ctx);
if ($preview === false || $preview === '') { $preview = '(aperçu indisponible — le service portail répond-il sur le port 2080 ?)'; }

pf_header('Serveur PXE', 'pxe.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<style>
  .pxe-entry{display:grid;grid-template-columns:190px 1fr;gap:.6rem 1rem;align-items:center;
    padding:.9rem 0;border-top:1px solid var(--line)}
  .pxe-entry:first-of-type{border-top:none}
  .pxe-entry .who{display:flex;align-items:center;gap:.6rem}
  .pxe-entry input[type=text]{width:100%;padding:.55rem .7rem;background:var(--bg);color:var(--text);
    border:1px solid var(--line);border-radius:8px;font-size:.85rem}
  .pxe-entry .args{grid-column:2}
  .pxe-entry .args input{font-family:ui-monospace,monospace;font-size:.8rem}
  .pxe-entry label.sw{display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:500}
  .field{display:grid;gap:.35rem;margin-bottom:1rem;font-size:.82rem;color:var(--muted)}
  .field input,.field select{padding:.6rem .7rem;background:var(--bg);color:var(--text);
    border:1px solid var(--line);border-radius:8px;font-size:.95rem}
  .row3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:1rem}
  .hint{color:var(--muted);font-size:.78rem}
  .miss{color:#fca5a5;font-size:.76rem;margin-top:.25rem}
  .miss code{background:rgba(248,113,113,.12);color:#fca5a5;padding:.05rem .3rem;border-radius:4px}
  details.pxe-help{border:1px solid var(--line);border-radius:12px;background:var(--bg);margin-bottom:1rem}
  details.pxe-help>summary{cursor:pointer;padding:.8rem 1.1rem;font-weight:600;list-style:none;display:flex;align-items:center;gap:.5rem}
  details.pxe-help>summary::-webkit-details-marker{display:none}
  details.pxe-help>summary::before{content:"▸";color:var(--muted)}
  details.pxe-help[open]>summary::before{content:"▾"}
  details.pxe-help .body{padding:.2rem 1.1rem 1rem;color:var(--muted);font-size:.86rem;line-height:1.6}
  @media(max-width:720px){.row3{grid-template-columns:1fr}.pxe-entry{grid-template-columns:1fr}.pxe-entry .args{grid-column:1}}
</style>

<!-- État en un coup d'œil -->
<section class="cards">
  <div class="kpi"><div class="kpi-val" style="color:<?= $dnsOn ? '#4ade80' : '#f87171' ?>"><?= $dnsOn ? 'Actif' : 'Arrêté' ?></div><div class="kpi-lbl">Service PXE (TFTP/DHCP)</div></div>
  <div class="kpi"><div class="kpi-val" style="color:<?= $osReady === 3 ? '#4ade80' : ($osReady ? '#eab308' : '#f87171') ?>"><?= $osReady ?>/3</div><div class="kpi-lbl">Systèmes prêts à installer</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1rem"><?= e($NAMES[$curDefault] ?? $curDefault) ?></div><div class="kpi-lbl">Entrée par défaut</div></div>
  <div class="kpi"><div class="kpi-val" style="font-size:1rem;color:<?= $on('pxe_protected') ? '#4ade80' : '#94a3b8' ?>"><?= $on('pxe_protected') ? '🔒 Protégé' : 'Ouvert' ?></div><div class="kpi-lbl">Accès au menu</div></div>
</section>

<?php if ($osReady === 0): ?>
  <div class="flash err">Aucun système n'est prêt : aucune installation réseau ne peut aboutir. Ajoutez les fichiers de démarrage
  (voir « État du serveur de démarrage » en bas).</div>
<?php elseif (!$defReady || !$defEnabled): ?>
  <div class="flash" style="margin-bottom:1rem">⚠️ L'entrée par défaut <strong><?= e($NAMES[$curDefault] ?? $curDefault) ?></strong>
  est <?= !$defEnabled ? 'désactivée' : 'incomplète' ?> : au bout du délai, le poste risque de ne rien pouvoir amorcer.
  Choisissez une entrée par défaut prête (souvent <em>Disque local</em>).</div>
<?php endif; ?>

<details class="pxe-help">
  <summary>❔ Comment amorcer un poste en réseau (PXE)</summary>
  <div class="body">
    <ol style="margin:.2rem 0 0;padding-left:1.2rem">
      <li>Brancher le poste sur le <strong>réseau du commissariat</strong> (le même LAN que la passerelle).</li>
      <li>Au démarrage, ouvrir le <strong>menu d'amorçage</strong> (souvent <code>F12</code>, parfois <code>F9</code>/<code>F8</code>/<code>Échap</code>) et choisir le <strong>démarrage réseau / PXE / IPv4</strong>.</li>
      <li>Sinon, activer « <em>Network / PXE Boot</em> » dans le BIOS/UEFI et le placer en tête de l'ordre d'amorçage.</li>
      <li>Le poste récupère l'amorceur iPXE, puis affiche ce menu (protégé par identifiants si l'option est cochée).</li>
    </ol>
    <p style="margin:.6rem 0 0">Menu servi par <code>http://<?= e($lanIp) ?>:2080/boot/menu.php</code>. En VM VirtualBox :
    carte réseau en « accès par pont » ou « réseau interne » sur le LAN, et amorçage réseau activé.</p>
  </div>
</details>

<form method="post">
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="save">

<section class="panel">
  <div class="panel-head"><h2>⚙️ Réglages du menu</h2>
    <span class="badge <?= $dnsOn ? 'on' : 'off' ?>">TFTP/DHCP <?= $dnsOn ? 'actif' : 'inactif' ?></span>
  </div>
  <div style="padding:1.2rem">
    <div class="row3">
      <label class="field">Titre du menu
        <input type="text" name="pxe_menu_title" value="<?= $val('pxe_menu_title') ?>">
      </label>
      <label class="field">Délai avant défaut (s)
        <input type="number" name="pxe_timeout" min="0" max="3600" value="<?= (int) ($S['pxe_timeout'] ?? 60) ?>">
      </label>
      <label class="field">Entrée par défaut
        <select name="pxe_default">
          <?php foreach ($NAMES as $k => $n): ?>
            <option value="<?= $k ?>" <?= $curDefault === $k ? 'selected' : '' ?>><?= e($n) ?><?= $ready[$k] ? '' : ' — incomplet' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label class="field" style="margin-top:.2rem">Sous-titre du menu <span class="muted small">(optionnel — affiché sous le titre)</span>
      <input type="text" name="pxe_menu_subtitle" value="<?= $val('pxe_menu_subtitle') ?>">
    </label>
    <label class="sw" style="display:inline-flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.3rem">
      <input type="checkbox" name="pxe_protected" id="pxe_protected" <?= $on('pxe_protected') ? 'checked' : '' ?>>
      <span>Protéger le menu par les identifiants administrateur</span>
    </label>
    <p class="hint" style="margin:.4rem 0 0">Si activé, l'installation réseau demande un nom d'utilisateur et un mot de passe
    de la console (table <code>pf_admins</code>) avant d'afficher le menu.</p>
    <div id="loginto-row" style="<?= $on('pxe_protected') ? '' : 'display:none' ?>">
      <label class="field" style="max-width:24rem;margin-top:.9rem">Démarrage sur le disque si personne ne s'identifie (s)
        <input type="number" name="pxe_login_timeout" min="0" max="3600" value="<?= (int) ($S['pxe_login_timeout'] ?? 30) ?>">
      </label>
      <p class="hint" style="margin:.4rem 0 0">Un décompte s'affiche sur l'écran d'identification ; à zéro, le poste démarre sur
      son disque local. Sans ce délai, un poste amorcé en réseau sans personne devant lui resterait bloqué indéfiniment.
      Appuyer sur une touche annule le décompte. <code>0</code> = attendre indéfiniment.</p>
    </div>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>🖥️ Entrées du menu</h2></div>
  <div style="padding:.4rem 1.2rem 1.2rem">
    <?php foreach ($ENTRIES as $k => [$name, $desc, $hasArgs]): ?>
      <div class="pxe-entry">
        <div class="who">
          <label class="sw"><input type="checkbox" name="pxe_<?= $k ?>_enabled" <?= $on("pxe_{$k}_enabled") ? 'checked' : '' ?>>
            <span><?= e($name) ?></span></label>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.3rem">
            <span class="badge <?= $ready[$k] ? 'on' : 'off' ?>"><?= $ready[$k] ? 'Prêt' : 'Fichiers manquants' ?></span>
            <span class="hint"><?= e($desc) ?></span>
          </div>
          <input type="text" name="pxe_<?= $k ?>_label" value="<?= $val("pxe_{$k}_label") ?>" placeholder="Libellé affiché dans le menu">
          <?php if (!empty($missing[$k])): ?>
            <div class="miss">Manque : <?php foreach ($missing[$k] as $i => $m): ?><?= $i ? ' · ' : '' ?><code><?= e($m) ?></code><?php endforeach; ?></div>
          <?php endif; ?>
        </div>
        <?php if ($hasArgs): ?>
          <div class="args">
            <input type="text" name="pxe_<?= $k ?>_args" value="<?= $val("pxe_{$k}_args") ?>" placeholder="Paramètres du noyau">
            <span class="hint"><code>{IP}</code> = adresse de la passerelle (remplacée automatiquement).</span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="pxe-entry" style="border-top:2px solid var(--line)">
      <div class="who">
        <label class="sw"><input type="checkbox" name="pxe_custom_enabled" <?= $on('pxe_custom_enabled') ? 'checked' : '' ?>>
          <span>Entrée personnalisée</span></label>
      </div>
      <div>
        <div class="hint" style="margin-bottom:.4rem">Amorcez n'importe quel système : URL du noyau et de l'initrd (<code>{IP}</code> = passerelle, remplacé automatiquement).</div>
        <input type="text" name="pxe_custom_label" value="<?= $val('pxe_custom_label') ?>" placeholder="Libellé affiché dans le menu" style="margin-bottom:.4rem">
        <input type="text" name="pxe_custom_kernel" value="<?= $val('pxe_custom_kernel') ?>" placeholder="URL du noyau (ex. http://{IP}:2080/iso/autre/vmlinuz)" style="font-family:ui-monospace,monospace;font-size:.8rem;margin-bottom:.4rem">
        <input type="text" name="pxe_custom_initrd" value="<?= $val('pxe_custom_initrd') ?>" placeholder="URL de l'initrd (optionnel)" style="font-family:ui-monospace,monospace;font-size:.8rem">
      </div>
      <div class="args">
        <input type="text" name="pxe_custom_args" value="<?= $val('pxe_custom_args') ?>" placeholder="Paramètres du noyau (optionnel)">
      </div>
    </div>

    <p class="hint" style="margin-top:.8rem">⚠️ La console iPXE (BIOS) affiche mal les accents : préférez des libellés en
    caractères simples. Windows 11 utilise <code>wimboot</code> (BCD/boot.wim) et n'a pas de paramètres noyau.</p>
  </div>
  <div class="form-actions" style="padding:0 1.2rem 1.2rem"><button class="btn">💾 Enregistrer les paramètres</button></div>
</section>
</form>

<div class="split">
  <section class="panel">
    <div class="panel-head"><h2>👁️ Aperçu du menu généré</h2></div>
    <div style="padding:1.2rem">
      <pre style="margin:0;padding:1rem;background:#0b1120;color:#cbd5e1;border:1px solid var(--line);
        border-radius:10px;overflow:auto;max-height:340px;font-family:ui-monospace,monospace;
        font-size:.78rem;line-height:1.5"><?= e($preview) ?></pre>
      <p class="muted small" style="margin:.6rem 0 0">Rendu réel servi aux clients (reflète les réglages
      <strong>enregistrés</strong> — enregistrez pour rafraîchir). La partie login n'apparaît pas ici (aperçu authentifié).</p>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>🖼️ Bannière de fond</h2>
      <span class="badge <?= $banner ? 'on' : 'off' ?>"><?= $banner ? 'Présente' : 'Absente' ?></span>
    </div>
    <div style="padding:1.2rem">
      <?php if ($banner): ?>
        <img src="/pxe-banner.png?t=<?= @filemtime($BANNER) ?: 0 ?>"
             alt="Bannière PXE" style="width:100%;max-width:420px;border:1px solid var(--line);border-radius:10px;display:block">
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="stack" style="margin-top:1rem">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_banner">
        <label class="field">Remplacer la bannière <span class="muted small">(PNG, 1024×768 conseillé, max 3 Mo)</span>
          <input type="file" name="banner" accept="image/png" required>
        </label>
        <div class="form-actions"><button class="btn">⬆️ Téléverser</button></div>
        <p class="hint">Le menu PXE réserve le haut (logo) et le bas de l'écran ; le centre accueille la liste.
        L'image s'affiche au prochain démarrage réseau d'un client.</p>
      </form>
    </div>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>📦 État du serveur de démarrage</h2></div>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Élément</th><th>État</th><th>Détail</th></tr></thead>
    <tbody>
      <tr><td><strong>Service TFTP / DHCP (dnsmasq)</strong></td>
        <td><span class="badge <?= $dnsOn ? 'on' : 'off' ?>"><?= $dnsOn ? 'Actif' : 'Inactif' ?></span></td>
        <td class="muted">amorçage iPXE (undionly.kpxe) puis <code>boot.ipxe → menu.php</code></td></tr>
      <tr><td><strong>Bannière graphique</strong></td>
        <td><span class="badge <?= $banner ? 'on' : 'off' ?>"><?= $banner ? 'Présente' : 'Absente' ?></span></td>
        <td class="muted"><code>/boot/menu-bg.png</code></td></tr>
      <?php foreach (['debian' => 'Debian (linux + initrd.gz)', 'ubuntu' => 'Ubuntu (vmlinuz + initrd + ISO)', 'windows' => 'Windows 11 (wimboot + boot.wim)'] as $k => $lbl): ?>
      <tr><td><strong><?= e($NAMES[$k]) ?></strong></td>
        <td><span class="badge <?= $ready[$k] ? 'on' : 'off' ?>"><?= $ready[$k] ? 'Prêt' : 'Incomplet' ?></span></td>
        <td class="muted"><?php if ($ready[$k]): ?><?= e($lbl) ?><?php else: ?>Manque : <?php foreach ($missing[$k] as $i => $m): ?><?= $i ? ', ' : '' ?><code><?= e($m) ?></code><?php endforeach; ?><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">
    Les fichiers manquants s'ajoutent via <code>setup-pxe.sh</code> (Debian/Ubuntu) ou l'intégration d'une ISO (Windows).
    Menu servi par <code>http://<?= e($lanIp) ?>:2080/boot/menu.php</code>.
  </p>
</section>

<script>
  // Le délai « démarrage sur disque » ne concerne que le menu PROTÉGÉ : on le masque sinon.
  (function () {
    var pc = document.getElementById('pxe_protected'), lr = document.getElementById('loginto-row');
    if (pc && lr) { pc.addEventListener('change', function () { lr.style.display = pc.checked ? '' : 'none'; }); }
  })();
</script>
<?php pf_footer(); ?>
