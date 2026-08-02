<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion Admin — Supervision réseau en temps réel.
 *
 * Complète le tableau de bord (qui liste QUI est connecté + totaux cumulés) par la
 * dimension DÉBIT INSTANTANÉ : courbe glissante du débit WAN + « top talkers » (débit
 * live par poste/agent). Le débit par client n'existe nulle part côté serveur (le noyau
 * et OpenNDS ne publient que des compteurs cumulés) : la page fournit les compteurs +
 * un horodatage, et le NAVIGATEUR calcule les débits par différence entre deux sondages.
 */
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/audit.php';

// ── Point d'accès Wi-Fi : SSID + phrase secrète, modifiables ici ─────────────
// Ils étaient figés dans /etc/hostapd/hostapd.conf, donc inaccessibles au client :
// changer le nom du réseau ou renouveler la phrase demandait un accès SSH au serveur.
// La console écrit en base ; proxyfibre-wifi relit la base et régénère la
// configuration. Rien ne transite par la ligne de commande.
$wifi_flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'wifi') {
    csrf_check();
    $ssid  = trim((string) ($_POST['wifi_ssid'] ?? ''));
    $psk   = (string) ($_POST['wifi_psk'] ?? '');
    $canal = (int) ($_POST['wifi_channel'] ?? 6);
    $db    = pf_db();
    // Bornes de la norme 802.11 : hostapd refuserait de démarrer hors de celles-ci,
    // et le point d'accès disparaîtrait — souvent en emportant l'accès de celui qui
    // vient de valider. On refuse donc AVANT d'écrire, pas après.
    // Réseau ouvert : demandé explicitement, et jamais par défaut. On exige aussi que
    // la case « j'ai compris » soit cochée — un réseau sans phrase sur une antenne
    // pontée avec l'annuaire n'est pas une préférence d'affichage.
    $ouvert = !empty($_POST['wifi_open']);
    $dejaOuvert = !empty($wifi['ouvert']);
    if (strlen($ssid) < 1 || strlen($ssid) > 32) {
        $wifi_flash = ['Le nom du réseau doit faire 1 à 32 caractères.', 'err'];
    } elseif ($ouvert && !$dejaOuvert && empty($_POST['wifi_open_ok'])) {
        $wifi_flash = ['Réseau ouvert : cochez la confirmation pour appliquer.', 'err'];
    } elseif (!$ouvert && $psk === '' && $dejaOuvert) {
        $wifi_flash = ['Pour repasser en réseau protégé, indiquez une phrase secrète.', 'err'];
    } elseif ($psk !== '' && (strlen($psk) < 8 || strlen($psk) > 63)) {
        $wifi_flash = ['La phrase secrète doit faire 8 à 63 caractères (norme WPA2).', 'err'];
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $ssid . $psk)) {
        $wifi_flash = ['Caractère de contrôle refusé.', 'err'];
    } elseif ($canal < 1 || $canal > 13) {
        $wifi_flash = ['Canal invalide (1 à 13).', 'err'];
    } else {
        $up = $db->prepare('INSERT INTO pf_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        $up->execute(['wifi_ssid', $ssid]);
        $up->execute(['wifi_channel', (string) $canal]);
        $up->execute(['wifi_open', $ouvert ? '1' : '0']);
        // Phrase laissée vide = inchangée : on ne force pas à la retaper pour
        // renommer le réseau, et elle n'est jamais réaffichée dans la page.
        if ($psk !== '') $up->execute(['wifi_psk', $psk]);
        $out = trim((string) shell_exec('sudo /usr/local/sbin/proxyfibre-wifi apply 2>&1'));
        if (stripos($out, 'ECHEC') !== false || $out === '') {
            $wifi_flash = ['Le point d’accès n’a pas redémarré. ' . htmlspecialchars($out), 'err'];
        } else {
            audit('wifi.config', 'SSID ' . $ssid . ' · canal ' . $canal
                . ($ouvert ? ' · RESEAU OUVERT (sans chiffrement)' : ' · WPA2')
                . ($psk !== '' ? ' · phrase renouvelée' : ''));
            $wifi_flash = [$out, 'ok'];
        }
    }
}
// ── Endpoint JSON (?data=1) : débit WAN + compteurs cumulés par client + horodatage ──
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    session_write_close();   // ndsctl est lent (~1,7 s) : libérer le verrou de session

    $net    = sys_net_rate();
    $wanCap = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-speedtest state 2>/dev/null'), true) ?: [];

    $clients = [];
    foreach (nds_clients() as $mac => $c) {
        $user = '';
        if (!empty($c['custom']) && ($d = base64_decode($c['custom'], true)) && preg_match('/user=([^,]+)/', $d, $mm)) {
            $user = $mm[1];
        }
        $clients[] = [
            'mac'   => (string) $mac,
            'ip'    => (string) ($c['ip'] ?? ''),
            'user'  => $user,
            'auth'  => (($c['state'] ?? '') === 'Authenticated'),
            'dl'    => (int) ($c['download_this_session'] ?? 0),
            'ul'    => (int) ($c['upload_this_session'] ?? 0),
            'start' => (int) ($c['session_start'] ?? 0),
        ];
    }
    echo json_encode([
        't'       => microtime(true),
        'net'     => ['down' => $net['down'], 'up' => $net['up'], 'if' => $net['if'],
                      'capD' => (int) ($wanCap['down'] ?? 0), 'capU' => (int) ($wanCap['up'] ?? 0)],
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * ── Rôle physique des ports ──────────────────────────────────────────────────
 * Rien, nulle part, ne disait quel port du boîtier portait le WAN et lequel
 * portait le LAN. Lors du câblage du premier serveur, le réseau du service a été
 * branché sur le port LAN : son profil s'est activé seul, dnsmasq a démarré, et un
 * serveur DHCP a écouté quelques minutes sur un réseau de production. Aucun bail
 * n'est parti, mais personne n'aurait pu s'en apercevoir depuis la console.
 * Ces trois lignes coûtent peu et rendent l'erreur visible avant qu'elle nuise.
 */
function pf_ports_reseau(): array {
    $conf = [];
    foreach (@file('/etc/proxyfibre/net.env') ?: [] as $l) {
        if (preg_match('/^\s*(WAN_IF|LAN_IF)\s*=\s*"?([^"\s]+)/', $l, $m)) $conf[$m[1]] = $m[2];
    }
    $roles = [($conf['WAN_IF'] ?? '') => 'WAN', ($conf['LAN_IF'] ?? '') => 'LAN'];
    $out = [];
    foreach (glob('/sys/class/net/*') ?: [] as $p) {
        $if = basename($p);
        if ($if === 'lo' || str_starts_with($if, 'veth')) continue;
        $lien = trim((string) @file_get_contents("$p/carrier"));   // « 1 » = câble détecté
        $deb  = trim((string) @file_get_contents("$p/speed"));
        $ips  = [];
        foreach (explode("\n", (string) shell_exec('ip -4 -br addr show ' . escapeshellarg($if) . ' 2>/dev/null')) as $l) {
            if (preg_match_all('/\d+\.\d+\.\d+\.\d+\/\d+/', $l, $mm)) $ips = $mm[0];
        }
        // Une interface peut n'avoir ni adresse ni rôle propre et servir tout de même
        // le LAN : c'est le cas des membres d'un pont (câble + point d'accès Wi-Fi
        // réunis sous br-lan). Sans cette lecture, le Wi-Fi apparaissait « sans rôle »
        // alors qu'il porte le portail captif — exactement l'inverse de la réalité.
        $pont = @readlink("$p/master");
        $pont = $pont ? basename($pont) : '';
        $sansfil = is_dir("$p/wireless") || is_dir("$p/phy80211");
        $out[] = [
            'if'    => $if,
            'role'  => $roles[$if] ?? ($pont && ($roles[$pont] ?? '') ? $roles[$pont] : ''),
            'pont'  => $pont,
            'sansfil' => $sansfil,
            'lien'  => $lien === '1',
            'debit' => ($deb > 0) ? (int) $deb : 0,
            'ips'   => $ips,
        ];
    }
    usort($out, fn($a, $b) => [$b['role'] === 'WAN', $b['role'] === 'LAN'] <=> [$a['role'] === 'WAN', $a['role'] === 'LAN']);
    return $out;
}
$pf_ports = pf_ports_reseau();
// Le nom de l'interface LAN, relu ici : c'est lui qui désigne la carte à présenter
// comme « réseau des postes ». Sur cette passerelle c'est un pont ; ailleurs ce sera
// une carte ordinaire. La présentation ne doit dépendre ni de l'un ni de l'autre.
$pf_lan_if = '';
foreach (@file('/etc/proxyfibre/net.env') ?: [] as $l) {
    if (preg_match('/^\s*LAN_IF\s*=\s*"?([^"\s]+)/', $l, $m)) $pf_lan_if = $m[1];
}

// L'état du point d'accès est lu APRÈS la sortie du point d'entrée JSON : cette page
// se sonde toutes les deux secondes pour le débit, et un « sudo » par sondage serait
// payé pour rien — l'état du Wi-Fi n'est affiché que dans la page complète.
$wifi = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-wifi state 2>/dev/null'), true) ?: [];

// Le balayage n'est PAS fait au chargement : il oblige la carte à quitter son canal
// quelques centaines de millisecondes, ce qui donne un hoquet aux terminaux connectés.
// Payer cela à chaque affichage de page serait absurde. Il se déclenche à la demande.
$spectre = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'wifiscan') {
    csrf_check();
    $spectre = json_decode((string) shell_exec('sudo /usr/local/sbin/proxyfibre-wifi scan 2>/dev/null'), true) ?: null;
    if (function_exists('audit')) audit('wifi.scan', 'analyse du spectre 2,4 GHz');
}

pf_header('Supervision réseau', 'reseau.php');
?>
<section class="panel">
  <div class="panel-head"><h2>🔌 Ports réseau</h2></div>
  <div style="padding:1.1rem 1.2rem">
    <?php
    // Présentation PAR RÔLE et non par interface. Le tableau plat précédent alignait
    // trois lignes « LAN » — le pont et ses deux membres — annonçait « câble branché »
    // sur une interface virtuelle qui n'a pas de port, et entassait deux adresses dans
    // une cellule. Un pont n'est pas un port : le présenter comme tel embrouille.
    $parNom = [];
    foreach ($pf_ports as $p) $parNom[$p['if']] = $p;
    $wan = null; $lan = null; $libres = [];
    foreach ($pf_ports as $p) {
        if ($p['role'] === 'WAN' && !$p['pont'])      { $wan = $p; }
        elseif ($p['if'] === ($pf_lan_if ?? ''))       { $lan = $p; }
        elseif ($p['role'] === '' && !$p['pont'])      { $libres[] = $p; }
    }
    if (!$lan) foreach ($pf_ports as $p) if ($p['role'] === 'LAN' && !$p['pont']) { $lan = $p; break; }
    $membres = array_values(array_filter($pf_ports, fn($p) => $lan && $p['pont'] === $lan['if']));

    $etatLien = static function (array $p): string {
        if ($p['sansfil']) return $p['lien']
            ? '<span class="badge on">radio active</span>'
            : '<span class="badge warn">radio inactive</span>';
        return $p['lien']
            ? '<span class="badge on">câble branché</span>'
            : '<span class="badge warn">aucun câble</span>';
    };
    ?>

    <div style="display:grid;gap:.9rem">

      <?php if ($wan): ?>
      <div style="border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:10px;padding:.8rem 1rem;background:var(--bg)">
        <div style="display:flex;justify-content:space-between;gap:.8rem;flex-wrap:wrap;align-items:baseline">
          <b>WAN — accès Internet</b>
          <span class="muted small"><code><?= e($wan['if']) ?></code><?= $wan['debit'] ? ' · ' . $wan['debit'] . ' Mb/s' : '' ?></span>
        </div>
        <div style="margin-top:.45rem;display:flex;gap:.9rem;flex-wrap:wrap;align-items:center">
          <?= $etatLien($wan) ?>
          <span><?= $wan['ips'] ? e(implode(' · ', $wan['ips'])) : '<span class="muted">aucune adresse</span>' ?></span>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($lan): ?>
      <div style="border:1px solid var(--line);border-left:3px solid var(--accent2);border-radius:10px;padding:.8rem 1rem;background:var(--bg)">
        <div style="display:flex;justify-content:space-between;gap:.8rem;flex-wrap:wrap;align-items:baseline">
          <b>LAN — réseau des postes</b>
          <span class="muted small"><code><?= e($lan['if']) ?></code><?= $membres ? ' · pont' : '' ?></span>
        </div>
        <div style="margin-top:.45rem">
          <?php foreach ($lan['ips'] as $i => $ip): ?>
            <span><?= e($ip) ?></span>
            <span class="muted small"><?= $i === 0 ? '(passerelle, DHCP, DNS)' : '(annuaire)' ?></span><?= $i < count($lan['ips']) - 1 ? '<br>' : '' ?>
          <?php endforeach; ?>
          <?php if (!$lan['ips']): ?><span class="muted">aucune adresse — le LAN est inactif</span><?php endif; ?>
        </div>
        <?php if ($membres): ?>
          <div style="margin-top:.7rem;padding-top:.6rem;border-top:1px dashed var(--line)">
            <div class="muted small" style="margin-bottom:.4rem">Ce réseau réunit :</div>
            <?php foreach ($membres as $m): ?>
              <div style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.25rem 0">
                <code style="min-width:9rem"><?= e($m['if']) ?></code>
                <?= $etatLien($m) ?>
                <span class="muted small">
                  <?= $m['sansfil'] ? 'point d’accès Wi-Fi' : 'port filaire' ?>
                  <?= (!$m['sansfil'] && $m['debit']) ? ' · ' . $m['debit'] . ' Mb/s' : '' ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($libres): ?>
      <div style="border:1px solid var(--line);border-radius:10px;padding:.8rem 1rem;background:var(--bg)">
        <div class="muted small" style="margin-bottom:.35rem">Ports sans rôle attribué</div>
        <?php foreach ($libres as $p): ?>
          <div style="display:flex;gap:.7rem;align-items:center;flex-wrap:wrap;margin:.25rem 0">
            <code style="min-width:9rem"><?= e($p['if']) ?></code>
            <?= $etatLien($p) ?>
            <span class="muted small"><?= $p['ips'] ? e(implode(' · ', $p['ips'])) : '' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>

    <p class="muted small" style="margin:1rem 0 0;max-width:70ch">
      Le réseau <b>LAN</b> distribue les adresses <code>DHCP</code> et intercepte le DNS
      des postes. Il doit aller sur le <b>switch isolé du parc</b>, jamais sur un réseau
      déjà équipé d’un serveur DHCP : deux serveurs sur le même câble rendent les postes
      injoignables, et la panne est difficile à imputer.
    </p>
  </div>
</section>

<?php if (!empty($wifi['interface'])): ?>
<section class="panel">
  <div class="panel-head"><h2>📶 Point d’accès Wi-Fi</h2></div>
  <div style="padding:1.1rem 1.2rem">
    <?php if ($wifi_flash): ?>
      <div class="flash <?= $wifi_flash[1] === 'ok' ? 'ok' : 'err' ?>" role="alert" style="margin-bottom:.9rem"><?= e($wifi_flash[0]) ?></div>
    <?php endif; ?>
    <p class="muted small" style="margin:0 0 1rem">
      État : <b><?= $wifi['actif'] === 'active' ? 'en service' : e((string) $wifi['actif']) ?></b>
      · <?= (int) ($wifi['clients'] ?? 0) ?> terminal(aux) connecté(s)
      <?= !empty($wifi['pont']) ? '· relié au LAN par <code>' . e($wifi['pont']) . '</code>' : '' ?>
    </p>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.9rem;align-items:end;max-width:820px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="do" value="wifi">
      <label>Nom du réseau (SSID)
        <input name="wifi_ssid" maxlength="32" required value="<?= e((string) ($wifi['ssid'] ?? '')) ?>">
      </label>
      <label>Phrase secrète
        <input name="wifi_psk" type="password" minlength="8" maxlength="63" autocomplete="new-password"
               placeholder="<?= !empty($wifi['ouvert']) ? 'réseau ouvert — aucune phrase' : 'inchangée si laissée vide' ?>"
               <?= !empty($wifi['ouvert']) ? 'disabled' : '' ?> id="pskfield">
      </label>
      <label>Canal
        <select name="wifi_channel">
          <?php for ($c = 1; $c <= 13; $c++): ?>
            <option value="<?= $c ?>" <?= ((int) ($wifi['canal'] ?? 6) === $c) ? 'selected' : '' ?>><?= $c ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap">
        <button class="btn" type="submit">Appliquer</button>
        <button type="button" class="btn" id="btn-fiche"
                style="background:var(--panel2);color:var(--text)"
                title="Fiche PDF avec le QR de connexion, à afficher ou à remettre">📄 Fiche PDF</button>
      </div>

      <!-- Réseau ouvert : hors de la grille, sur toute la largeur, avec ce qu'il faut
           lire avant de cocher. Un tel choix ne se glisse pas entre deux champs. -->
      <div style="grid-column:1/-1;border:1px solid var(--line);border-left:3px solid #eab308;
                  border-radius:10px;padding:.75rem .9rem;margin-top:.3rem">
        <label style="display:flex;gap:.5rem;align-items:flex-start;font-size:.9rem;color:var(--text)">
          <input type="checkbox" name="wifi_open" value="1" id="wopen" <?= !empty($wifi['ouvert']) ? 'checked' : '' ?>
                 onchange="document.getElementById('pskfield').disabled=this.checked;document.getElementById('wopenbox').hidden=!this.checked||<?= !empty($wifi['ouvert']) ? 'true' : 'false' ?>">
          <span><b>Réseau ouvert</b> — aucune phrase secrète. Les agents se connectent
          directement, puis s’identifient sur le portail captif.</span>
        </label>
        <div id="wopenbox" hidden style="margin-top:.6rem;padding-top:.6rem;border-top:1px dashed var(--line)">
          <p class="muted small" style="margin:0 0 .5rem;max-width:70ch">
            Sans phrase, <b>le lien radio n’est pas chiffré</b> : tout ce qui ne passe pas
            en HTTPS se lit avec n’importe quel portable à portée. Et sur cette passerelle
            l’antenne est <b>pontée avec le réseau des postes</b> : un terminal obtient une
            adresse et atteint le contrôleur de domaine <b>avant</b> de s’authentifier. Le
            portail bloque la sortie vers Internet, pas les machines locales.
          </p>
          <label style="display:flex;gap:.5rem;align-items:center;font-size:.85rem;color:var(--text)">
            <input type="checkbox" name="wifi_open_ok" value="1">
            <span>J’ai lu ce que cela expose et je l’applique en connaissance de cause.</span>
          </label>
        </div>
      </div>
    </form>
    <!-- ── Spectre 2,4 GHz ─────────────────────────────────────────────────
         Choisir un canal « au hasard parmi ceux qui semblent libres » ne marche pas
         en 2,4 GHz : les canaux se CHEVAUCHENT. Un réseau sur le 6 gêne du 4 au 8.
         D'où une barre par canal montrant la gêne SUBIE, et non le simple décompte
         des réseaux qui s'y déclarent — les deux donnent des réponses différentes. -->
    <div style="border-top:1px solid var(--line);margin-top:1.2rem;padding-top:1rem">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
        <h3 style="margin:0;font-size:.95rem">📡 Occupation du spectre 2,4 GHz</h3>
        <form method="post" style="margin:0">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="do" value="wifiscan">
          <button class="btn" type="submit" style="padding:.45rem .9rem;font-size:.85rem">Analyser</button>
        </form>
      </div>

      <?php if ($spectre === null): ?>
        <p class="muted small" style="margin:.7rem 0 0;max-width:70ch">
          L’analyse n’est pas lancée automatiquement : elle oblige la carte à quitter son
          canal une fraction de seconde, ce qui donne un hoquet aux terminaux connectés.
          À faire à l’installation, ou quand le Wi-Fi se dégrade.
        </p>
      <?php else: ?>
        <?php
        $canaux    = $spectre['canaux'] ?? [];
        $conseille = (int) ($spectre['conseille'] ?? 0);
        $actuel    = (int) ($spectre['actuel'] ?? 0);
        $totalRes  = array_sum(array_column($canaux, 'reseaux'));
        ?>
        <div style="display:flex;align-items:flex-end;gap:.35rem;height:130px;margin:1rem 0 .3rem">
          <?php foreach ($canaux as $c): ?>
            <?php
            $h = max(3, (int) $c['charge']);
            $moi = ((int) $c['canal'] === $actuel);
            $best = ((int) $c['canal'] === $conseille);
            $col = $best ? '#22c55e' : ($moi ? 'var(--accent2)' : ($c['charge'] >= 60 ? '#ef4444' : 'var(--line)'));
            ?>
            <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%"
                 title="Canal <?= (int) $c['canal'] ?> — gêne <?= (int) $c['charge'] ?> % · <?= (int) $c['reseaux'] ?> réseau(x) déclaré(s)<?= $c['reseaux'] ? ', pic ' . (int) $c['pic'] . ' dBm' : '' ?>">
              <?php if ($c['reseaux']): ?>
                <span class="muted" style="font-size:.65rem"><?= (int) $c['reseaux'] ?></span>
              <?php endif; ?>
              <div style="width:100%;height:<?= $h ?>%;background:<?= $col ?>;border-radius:4px 4px 0 0;
                          <?= ($moi || $best) ? 'box-shadow:0 0 0 1px ' . $col : 'opacity:.75' ?>"></div>
              <span style="font-size:.7rem;margin-top:.25rem;<?= ($moi || $best) ? 'font-weight:700;color:' . $col : 'color:var(--muted)' ?>">
                <?= (int) $c['canal'] ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="muted small" style="display:flex;gap:1.1rem;flex-wrap:wrap;margin-bottom:.7rem">
          <span><span style="display:inline-block;width:.65rem;height:.65rem;background:var(--accent2);border-radius:2px"></span> canal actuel (<?= $actuel ?>)</span>
          <span><span style="display:inline-block;width:.65rem;height:.65rem;background:#22c55e;border-radius:2px"></span> conseillé (<?= $conseille ?>)</span>
          <span><span style="display:inline-block;width:.65rem;height:.65rem;background:#ef4444;border-radius:2px"></span> gêne forte</span>
          <span>le chiffre au-dessus d’une barre = réseaux déclarés sur ce canal</span>
        </div>

        <?php if ($conseille && $conseille !== $actuel): ?>
          <form method="post" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="do" value="wifi">
            <input type="hidden" name="wifi_ssid" value="<?= e((string) ($wifi['ssid'] ?? '')) ?>">
            <input type="hidden" name="wifi_channel" value="<?= $conseille ?>">
            <button class="btn" type="submit">Basculer sur le canal <?= $conseille ?></button>
            <span class="muted small">La phrase secrète n’est pas modifiée.</span>
          </form>
        <?php elseif ($conseille): ?>
          <p class="muted small" style="margin:0">Le canal <?= $actuel ?> est déjà le meilleur choix disponible.</p>
        <?php endif; ?>

        <p class="muted small" style="margin:.8rem 0 0;max-width:70ch">
          <?= $totalRes ?> réseau(x) voisin(s) détecté(s). La hauteur d’une barre mesure la
          gêne <b>subie</b> par ce canal, pas le nombre de réseaux qui s’y déclarent : en
          2,4 GHz un réseau occupe environ quatre canaux de part et d’autre du sien. Un
          canal sans aucun réseau déclaré peut donc être fortement gêné — c’est le cas de
          ses voisins immédiats. À égalité, 1, 6 et 11 sont préférés : ce sont les seuls
          qui ne se chevauchent pas entre eux.
        </p>
      <?php endif; ?>
    </div>

    <p class="muted small" style="margin:.9rem 0 0;max-width:70ch">
      Le réseau se coupe une seconde à l’application, le temps du redémarrage : les
      terminaux connectés se reconnectent seuls, sauf si la phrase a changé. Cette phrase
      protège le lien radio — l’identification de l’agent se fait ensuite sur le portail,
      exactement comme sur le câble.
    </p>
  </div>
</section>

<!-- ── Aperçu de la fiche ────────────────────────────────────────────────────
     Le cadre reste VIDE tant que la fenêtre n'est pas ouverte. Charger le PDF au
     chargement de la page le ferait fabriquer à chaque visite — et inscrirait une
     divulgation au journal d'audit à chaque fois, ce qui rendrait ce journal
     inexploitable pour ce qu'il sert : savoir qui a vu la phrase secrète. -->
<div class="modal-ov" id="fichemodal">
  <div class="modal" style="max-width:min(860px,94vw)" role="dialog" aria-modal="true" aria-labelledby="fichetitre">
    <div class="modal-head">
      <h2 id="fichetitre">📄 Fiche de connexion Wi-Fi</h2>
      <button type="button" class="btn" id="fiche-x" style="background:transparent;color:var(--muted);padding:.3rem .6rem"
              aria-label="Fermer">✕</button>
    </div>
    <div class="modal-body" style="padding:0">
      <iframe id="fiche-cadre" title="Aperçu de la fiche" style="width:100%;height:min(70vh,780px);border:0;display:block;background:#525659"></iframe>
    </div>
    <div style="display:flex;gap:.6rem;justify-content:flex-end;padding:1rem 1.3rem;border-top:1px solid var(--line);flex-wrap:wrap">
      <a class="btn" href="wifi-fiche.php" style="text-decoration:none">⬇ Télécharger</a>
      <button type="button" class="btn" id="fiche-close" style="background:var(--panel2);color:var(--text)">Fermer</button>
    </div>
  </div>
</div>
<script>
(function () {
  var ov = document.getElementById('fichemodal'),
      cadre = document.getElementById('fiche-cadre'),
      ouvrir = document.getElementById('btn-fiche');
  if (!ov || !ouvrir) return;
  function fermer() {
    ov.classList.remove('open');
    // On vide le cadre : le PDF porte la phrase secrète, il n'a pas à rester
    // affiché en arrière-plan d'une console qu'on laisse ouverte.
    cadre.removeAttribute('src');
  }
  ouvrir.addEventListener('click', function () {
    cadre.src = 'wifi-fiche.php?apercu=1&t=' + Date.now();
    ov.classList.add('open');
  });
  document.getElementById('fiche-x').addEventListener('click', fermer);
  document.getElementById('fiche-close').addEventListener('click', fermer);
  ov.addEventListener('click', function (e) { if (e.target === ov) fermer(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && ov.classList.contains('open')) fermer();
  });
})();
</script>
<?php endif; ?>
<?php ?>
<style>
  .net-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.8rem;margin-bottom:1rem}
  .net-kpi{border:1px solid var(--line);border-radius:12px;background:var(--bg);padding:.9rem 1.1rem}
  .net-kpi .lbl{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
  .net-kpi .val{font-size:1.5rem;font-weight:700;line-height:1.2;margin-top:.15rem}
  .net-kpi .val small{font-size:.85rem;font-weight:500;color:var(--muted)}
  .net-kpi.down .val{color:#38bdf8}.net-kpi.up .val{color:#a78bfa}
  .net-graph{width:100%;height:150px;display:block;border:1px solid var(--line);border-radius:12px;background:var(--bg)}
  .net-legend{display:flex;gap:1.2rem;font-size:.8rem;color:var(--muted);margin:.5rem 0 0}
  .net-legend b{display:inline-block;width:.7rem;height:.7rem;border-radius:2px;vertical-align:middle;margin-right:.3rem}
  .tt-bar{height:6px;border-radius:4px;background:var(--panel2);overflow:hidden;margin-top:.3rem}
  .tt-bar>span{display:block;height:100%;background:linear-gradient(90deg,var(--accent2),var(--accent));width:0;transition:width .8s ease}
  .tt-rate{font-variant-numeric:tabular-nums;font-weight:600}
  .net-off{color:var(--muted);text-align:center;padding:1.4rem}
</style>

<div class="net-kpis">
  <div class="net-kpi down"><div class="lbl">⬇ Débit descendant (WAN)</div><div class="val" id="k-down">—</div></div>
  <div class="net-kpi up"><div class="lbl">⬆ Débit montant (WAN)</div><div class="val" id="k-up">—</div></div>
  <div class="net-kpi"><div class="lbl">Postes connectés</div><div class="val" id="k-cli">—</div></div>
  <div class="net-kpi"><div class="lbl">Volume cumulé (sessions)</div><div class="val" id="k-vol">—</div></div>
</div>

<section class="panel">
  <div class="panel-head"><h2>📈 Débit WAN en direct <span class="muted small" id="net-if"></span></h2></div>
  <div style="padding:1rem 1.2rem">
    <canvas class="net-graph" id="net-graph" width="900" height="150"></canvas>
    <div class="net-legend"><span><b style="background:#38bdf8"></b>Descendant</span><span><b style="background:#a78bfa"></b>Montant</span>
      <span style="margin-left:auto" id="net-scale"></span></div>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>🏆 Top talkers — débit par poste</h2>
    <span class="muted small">actualisé toutes les 3 s</span></div>
  <div class="table-wrap" style="padding:.4rem .4rem 1rem">
    <table class="grid-table">
      <thead><tr><th>Agent / Poste</th><th>Adresse IP</th><th>⬇ Débit</th><th>⬆ Débit</th><th>Cumul session ⬇ / ⬆</th><th></th></tr></thead>
      <tbody id="tt-body"><tr><td colspan="6" class="net-off">Chargement…</td></tr></tbody>
    </table>
  </div>
</section>

<form id="pf-deauth" method="post" action="/index.php" style="display:none">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="deauth">
  <input type="hidden" name="mac" id="pf-deauth-mac" value="">
</form>

<script>
(function(){
  function fmtRate(bps){
    bps=Math.max(0,bps||0); var u=['o','Ko','Mo','Go'], i=0, v=bps;
    while(v>=1024 && i<u.length-1){ v/=1024; i++; }
    return (i? v.toFixed(1): Math.round(v))+' '+u[i]+'/s';
  }
  function fmtVol(n){
    n=Math.max(0,n||0); var u=['o','Ko','Mo','Go','To'], i=0;
    while(n>=1024 && i<u.length-1){ n/=1024; i++; }
    return (i? n.toFixed(1): Math.round(n))+' '+u[i];
  }
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

  var prev=null;                 // dernier échantillon { t, byMac:{mac:{dl,ul}} }
  var hist=[];                   // [{d,u}] pour la courbe (max 90 points)
  var HMAX=90;
  var cv=document.getElementById('net-graph'), cx=cv.getContext('2d');

  function drawGraph(){
    var W=cv.width, H=cv.height, pad=4;
    cx.clearRect(0,0,W,H);
    if(!hist.length) return;
    var max=1;
    hist.forEach(function(p){ max=Math.max(max,p.d,p.u); });
    // Échelle « jolie » (puissance de 2 en octets/s).
    document.getElementById('net-scale').textContent='échelle max ≈ '+fmtRate(max);
    function line(key,color){
      cx.beginPath(); cx.strokeStyle=color; cx.lineWidth=2;
      hist.forEach(function(p,i){
        var x=pad+(W-2*pad)*(i/(HMAX-1));
        var y=H-pad-(H-2*pad)*(p[key]/max);
        i? cx.lineTo(x,y): cx.moveTo(x,y);
      });
      cx.stroke();
      // remplissage léger
      cx.lineTo(pad+(W-2*pad)*((hist.length-1)/(HMAX-1)),H-pad); cx.lineTo(pad,H-pad); cx.closePath();
      cx.globalAlpha=.08; cx.fillStyle=color; cx.fill(); cx.globalAlpha=1;
    }
    line('u','#a78bfa'); line('d','#38bdf8');
  }

  function render(j){
    // KPI WAN (débit calculé côté serveur).
    document.getElementById('k-down').innerHTML=esc(fmtRate(j.net.down)).replace(/(\/s)$/,'<small>$1</small>');
    document.getElementById('k-up').innerHTML=esc(fmtRate(j.net.up)).replace(/(\/s)$/,'<small>$1</small>');
    document.getElementById('net-if').textContent=j.net.if? '· interface '+j.net.if : '';
    hist.push({d:j.net.down,u:j.net.up}); if(hist.length>HMAX) hist.shift();
    drawGraph();

    // Débit par client : delta des compteurs cumulés entre deux sondages.
    var auth=0, vol=0, rows=[];
    var cur={ t:j.t, byMac:{} };
    j.clients.forEach(function(c){
      if(c.auth) auth++; vol+=c.dl;
      var rd=0, ru=0;
      if(prev && prev.byMac[c.mac]){
        var dt=j.t-prev.t;
        if(dt>0.3){ rd=Math.max(0,(c.dl-prev.byMac[c.mac].dl)/dt); ru=Math.max(0,(c.ul-prev.byMac[c.mac].ul)/dt); }
      }
      cur.byMac[c.mac]={dl:c.dl,ul:c.ul};
      rows.push({c:c, rd:rd, ru:ru});
    });
    prev=cur;
    document.getElementById('k-cli').textContent=auth;
    document.getElementById('k-vol').textContent=fmtVol(vol);

    // Tri « top talkers » : débit descendant décroissant, puis cumul.
    rows.sort(function(a,b){ return (b.rd-a.rd) || (b.c.dl-a.c.dl); });
    var maxRate=1; rows.forEach(function(r){ maxRate=Math.max(maxRate,r.rd); });
    var tb=document.getElementById('tt-body');
    if(!rows.length){ tb.innerHTML='<tr><td colspan="6" class="net-off">Aucun poste connecté pour le moment.</td></tr>'; return; }
    tb.innerHTML=rows.map(function(r){
      var who=r.c.user? esc(r.c.user) : '<span class="muted">'+esc(r.c.mac)+'</span>';
      var badge=r.c.auth? '' : ' <span class="badge off">en attente</span>';
      return '<tr>'+
        '<td><strong>'+who+'</strong>'+badge+'<div class="tt-bar"><span style="width:'+Math.round(100*r.rd/maxRate)+'%"></span></div></td>'+
        '<td class="mono">'+esc(r.c.ip)+'</td>'+
        '<td class="tt-rate" style="color:#38bdf8">'+fmtRate(r.rd)+'</td>'+
        '<td class="tt-rate" style="color:#a78bfa">'+fmtRate(r.ru)+'</td>'+
        '<td class="muted">'+fmtVol(r.c.dl)+' / '+fmtVol(r.c.ul)+'</td>'+
        '<td>'+(r.c.auth? '<button type="button" class="btn-sm btn-danger" data-mac="'+esc(r.c.mac)+'" data-user="'+esc(r.c.user||r.c.ip)+'">Déconnecter</button>':'')+'</td>'+
      '</tr>';
    }).join('');
    Array.prototype.forEach.call(tb.querySelectorAll('button[data-mac]'), function(b){
      b.addEventListener('click', function(){
        if(confirm('Déconnecter '+b.getAttribute('data-user')+' ?')){
          document.getElementById('pf-deauth-mac').value=b.getAttribute('data-mac');
          document.getElementById('pf-deauth').submit();
        }
      });
    });
  }

  var timer=null, failed=0;
  function tick(){
    fetch('reseau.php?data=1', {headers:{'X-Requested-With':'fetch'}})
      .then(function(r){ return r.json(); })
      .then(function(j){ failed=0; render(j); })
      .catch(function(){ if(++failed>5){ document.getElementById('tt-body').innerHTML='<tr><td colspan="6" class="net-off">Supervision interrompue (serveur injoignable).</td></tr>'; } });
  }
  tick(); timer=setInterval(tick, 3000);
  document.addEventListener('visibilitychange', function(){
    if(document.hidden){ clearInterval(timer); timer=null; }
    else if(!timer){ tick(); timer=setInterval(tick,3000); }
  });
})();
</script>
<?php pf_footer(); ?>
