<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/** Bastion Admin — sauvegarde et restauration (base + configuration + médias + AD). */
require_once __DIR__ . '/inc/auth.php';

function bk(...$args): string {
    $cmd = 'sudo /usr/local/sbin/proxyfibre-backup';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
    return (string) shell_exec($cmd . ' 2>&1');
}
function bk_parse(string $raw): array {
    $out = [];
    foreach (explode("\n", $raw) as $l) { if (preg_match('/^(\w+)=(.*)$/', $l, $m)) { $out[$m[1]] = $m[2]; } }
    return $out;
}

// ── Téléchargement (avant tout affichage) ────────────────────────────────────
if (isset($_GET['dl'])) {
    $name = basename((string) $_GET['dl']);
    if (preg_match('/^bastion-[0-9-]+\.tar\.gz(\.gpg)?$/', $name)) {
        $path = trim(bk('path', $name));
        if (is_file($path)) {
            header('Content-Type: ' . (substr($name, -4) === '.gpg' ? 'application/octet-stream' : 'application/gzip'));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    exit('Sauvegarde introuvable.');
}

// ── API JSON (statut de progression, lancement, planification) ───────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = (string) $_GET['api'];
    if ($api === 'status') {
        $s = bk_parse(bk('status'));
        echo json_encode([
            'state'  => $s['state']  ?? 'idle',
            'op'     => $s['op']     ?? '',
            'pct'    => (int) ($s['pct'] ?? 0),
            'step'   => $s['step']   ?? '',
            'result' => $s['result'] ?? '',
        ]);
        exit;
    }
    if ($api === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $op   = in_array($_POST['op'] ?? '', ['restore', 'verify'], true) ? (string) $_POST['op'] : 'create';
        $name = basename((string) ($_POST['name'] ?? ''));
        $out  = trim(bk('start', $op, $name));
        echo json_encode(['ok' => $out === 'started', 'msg' => $out]);
        exit;
    }
    if ($api === 'auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $sub = ($_POST['sub'] ?? '') === 'enable' ? 'enable' : 'disable';
        $out = trim(bk('auto', $sub));
        echo json_encode(['ok' => in_array($out, ['active', 'desactive'], true), 'msg' => $out]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'requête invalide']);
    exit;
}

require_once __DIR__ . '/inc/layout.php';

$flash = null;
$newpass = null;    // phrase secrète fraîchement générée (affichée une fois)
$revealed = null;   // phrase secrète ré-affichée sur demande
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete') {
        bk('delete', (string) ($_POST['name'] ?? ''));
        $flash = ['Sauvegarde supprimée.', 'ok'];
    } elseif ($do === 'keygen') {
        $r = trim(bk('key', 'gen'));
        if ($r !== '' && $r !== 'existe' && $r !== 'echec') {
            $newpass = $r;
            if (function_exists('audit')) { audit('backup.key.gen'); }   // jamais la phrase elle-même
            $flash = ['Chiffrement activé. Notez la phrase secrète ci-dessous — elle ne sera plus affichée ainsi.', 'ok'];
        } else {
            $flash = [$r === 'existe' ? 'Une clé de chiffrement existe déjà.' : 'Échec de génération de la clé.', 'warn'];
        }
    } elseif ($do === 'keyshow') {
        $revealed = trim(bk('key', 'show'));
        if (function_exists('audit')) { audit('backup.key.show'); }
    }
}
// État du chiffrement.
$keySt = bk_parse(bk('key', 'status'));
$encOn = ($keySt['key'] ?? '') === 'yes';

$rows = [];
foreach (explode("\n", bk('list')) as $l) {
    $p = explode("\t", $l);
    if (count($p) >= 3) { $rows[] = ['name' => $p[0], 'size' => (int) $p[1], 'date' => $p[2]]; }
}
usort($rows, fn($a, $b) => strcmp($b['name'], $a['name']));
$fmt = function ($n) { $u = ['o','Ko','Mo','Go']; $i = 0; while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; } return number_format($n, $i ? 1 : 0, ',', ' ') . ' ' . $u[$i]; };

// État de la sauvegarde automatique (timer systemd).
$auto = bk_parse(bk('auto', 'status'));
$autoOn = ($auto['enabled'] ?? '') === 'enabled';
$autoNext = trim((string) ($auto['next'] ?? ''));   // déjà mis en forme par le script

pf_header('Sauvegarde', 'sauvegarde.php');
if ($flash) { pf_flash($flash[0], $flash[1]); }
?>
<!-- ── Chiffrement des sauvegardes ── -->
<section class="panel">
  <div class="panel-head"><h2>🔐 Chiffrement des sauvegardes</h2>
    <span class="badge <?= $encOn ? 'on' : 'off' ?>"><?= $encOn ? 'Actif · AES-256' : 'Inactif' ?></span>
  </div>
  <div style="padding:1.1rem 1.2rem">
    <?php if (!$encOn): ?>
      <p class="muted small" style="margin:0 0 .9rem">⚠️ <strong>Vos sauvegardes ne sont pas chiffrées.</strong>
      Chaque archive contient l'annuaire Active Directory — <strong>empreintes de mots de passe</strong> et
      <strong>clés de récupération BitLocker</strong> — ainsi que toute la base. Une archive qui fuite exposerait
      le secret le plus sensible du commissariat.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="keygen">
        <button class="btn">🔒 Activer le chiffrement (AES-256)</button>
      </form>
    <?php else: ?>
      <p class="muted small" style="margin:0 0 .9rem">✅ Les nouvelles sauvegardes sont chiffrées en <strong>AES-256</strong>.
      Il vous faut la <strong>phrase secrète</strong> pour restaurer sur une autre machine : conservez-la
      <strong>hors de la passerelle</strong> (coffre, gestionnaire de mots de passe).</p>
      <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="keyshow">
        <button class="btn-sm">👁 Afficher la phrase secrète</button>
      </form>
    <?php endif; ?>
    <?php if ($newpass !== null || $revealed !== null): $pw = $newpass ?? $revealed; ?>
      <div class="passbox">
        <p style="margin:0 0 .5rem"><strong>Phrase secrète de chiffrement</strong> — notez-la et conservez-la en lieu sûr, <em>hors de la passerelle</em> :</p>
        <code class="passval" id="passval"><?= e($pw) ?></code>
        <button type="button" class="btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('passval').textContent)">Copier</button>
        <p class="muted small" style="margin:.6rem 0 0">Sans cette phrase, une sauvegarde chiffrée est <strong>irrécupérable</strong> — Bastion ne peut pas la retrouver à votre place.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ── Sauvegarde automatique ── -->
<section class="panel">
  <div class="panel-head"><h2>🗓️ Sauvegarde automatique</h2>
    <span class="badge <?= $autoOn ? 'on' : 'off' ?>" id="autobadge"><?= $autoOn ? 'Activée' : 'Désactivée' ?></span>
  </div>
  <div style="padding:1.1rem 1.2rem;display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap">
    <p class="muted small" style="margin:0;flex:1;min-width:220px">Une sauvegarde complète est créée
      <strong>chaque semaine</strong> (lundi vers 02h30) et les <strong>8 dernières</strong> sont conservées
      automatiquement.<?php if ($autoOn && $autoNext): ?><br>Prochaine exécution : <strong><?= e($autoNext) ?></strong>.<?php endif; ?></p>
    <label class="switch" title="Activer / désactiver">
      <input type="checkbox" id="autotoggle" <?= $autoOn ? 'checked' : '' ?>>
      <span class="slider"></span>
    </label>
  </div>
</section>

<!-- ── Sauvegardes manuelles ── -->
<section class="panel">
  <div class="panel-head"><h2>💾 Sauvegardes</h2>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button class="btn-sm" id="btnverify" title="Restaure la dernière sauvegarde dans un espace jetable pour prouver qu'elle est vraiment récupérable (non destructif)">🧪 Tester la restauration</button>
      <button class="btn" id="btncreate">➕ Créer une sauvegarde</button>
    </div>
  </div>
  <p class="muted small" style="padding:.2rem 1.2rem 0">Chaque sauvegarde contient la <strong>base de données</strong>
  (comptes, groupes, filtrage, journaux, intranet, réglages), la <strong>configuration</strong>, les <strong>médias</strong>
  de l'intranet et une <strong>sauvegarde du domaine Active Directory</strong>. La création peut prendre ~30 s.</p>
  <div class="table-wrap">
  <table class="grid-table">
    <thead><tr><th>Sauvegarde</th><th>Date</th><th>Taille</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="4" class="muted center">Aucune sauvegarde. Créez-en une ci-dessus.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td class="mono svc-meta"><?= e($r['name']) ?><?php if (substr($r['name'], -4) === '.gpg'): ?> <span title="Chiffrée AES-256" style="color:var(--accent)">🔐</span><?php endif; ?></td>
        <td class="muted"><?= e($r['date']) ?></td>
        <td><?= e($fmt($r['size'])) ?></td>
        <td class="row-actions">
          <a class="btn-sm" href="/sauvegarde.php?dl=<?= urlencode($r['name']) ?>">⬇ Télécharger</a>
          <button class="btn-sm btn-danger btnrestore" data-name="<?= e($r['name']) ?>">Restaurer</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette sauvegarde ?')">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="do" value="delete">
            <input type="hidden" name="name" value="<?= e($r['name']) ?>"><button class="btn-sm">Suppr.</button></form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small" style="padding:0 1.2rem 1rem">💡 Téléchargez régulièrement une sauvegarde hors de la
  passerelle. La restauration recharge la base, la configuration et les médias ; le domaine AD se restaure à part
  (<code>samba-tool domain backup restore</code>).</p>
</section>

<!-- ── Fenêtre de progression (jauge) ── -->
<div class="modal-ov" id="progmodal">
  <div class="modal" style="max-width:440px" role="dialog" aria-modal="true">
    <div class="modal-head"><h2 id="progtitle"><span class="spin"></span>Sauvegarde en cours…</h2></div>
    <div class="modal-body">
      <div class="gauge-wrap">
        <div class="gauge"><div class="gauge-bar run" id="gbar" style="width:5%"></div></div>
        <div class="gauge-info"><span class="gauge-step" id="gstep">Démarrage…</span><span id="gpct">5 %</span></div>
      </div>
      <p class="muted small" id="gnote" style="margin:1rem 0 0">Ne fermez pas cette fenêtre.</p>
      <div id="gdone" style="display:none;margin-top:1rem;text-align:right">
        <button class="btn" onclick="location.reload()">Fermer</button>
      </div>
    </div>
  </div>
</div>

<style>
  .switch{position:relative;display:inline-block;width:52px;height:28px;flex:none}
  .switch input{opacity:0;width:0;height:0}
  .switch .slider{position:absolute;inset:0;background:var(--bg);border:1px solid var(--line);border-radius:99px;
    cursor:pointer;transition:background .2s ease,border-color .2s ease}
  .switch .slider::before{content:"";position:absolute;height:20px;width:20px;left:3px;top:3px;background:var(--muted);
    border-radius:50%;transition:transform .22s cubic-bezier(.16,1,.3,1),background .2s ease}
  .switch input:checked + .slider{background:rgba(56,189,248,.25);border-color:var(--accent)}
  .switch input:checked + .slider::before{transform:translateX(24px);background:var(--accent)}
  .passbox{margin-top:1rem;padding:.9rem 1rem;border:1px solid var(--accent);border-radius:10px;background:rgba(56,189,248,.06)}
  .passval{display:inline-block;font-size:1.05rem;letter-spacing:.04em;padding:.35rem .6rem;background:var(--bg);
    border:1px solid var(--line);border-radius:6px;user-select:all;word-break:break-all;margin-right:.4rem}
</style>
<script>
(function(){
  var CSRF=<?= json_encode(csrf_token()) ?>;
  var ov=document.getElementById('progmodal'), bar=document.getElementById('gbar'),
      step=document.getElementById('gstep'), pct=document.getElementById('gpct'),
      title=document.getElementById('progtitle'), note=document.getElementById('gnote'),
      done=document.getElementById('gdone'), poll=null;

  function show(op){
    title.innerHTML='<span class="spin"></span>'+(op==='verify'?'Test de restauration en cours…':(op==='restore'?'Restauration en cours…':'Sauvegarde en cours…'));
    bar.className='gauge-bar run'; bar.style.width='5%'; pct.textContent='5 %';
    step.textContent='Démarrage…'; note.style.display=''; done.style.display='none';
    ov.classList.add('open');
  }
  function update(s){
    bar.style.width=Math.max(5,s.pct)+'%'; pct.textContent=s.pct+' %'; step.textContent=s.step||'…';
    if(s.state==='done'){
      clearInterval(poll); poll=null;
      bar.className='gauge-bar'; bar.style.width='100%'; pct.textContent='100 %';
      if(s.op==='verify'){
        var okv=/base=ok/.test(s.result||'')&&/ad=ok/.test(s.result||'');
        title.innerHTML=okv?'✅ Sauvegarde restaurable':'⚠ Vérification incomplète';
        step.textContent='Résultat : '+(s.result||'—');
        note.textContent=okv?'La base et l\'Active Directory se restaurent correctement.':'Un composant n\'a pas pu être restauré — vérifiez la sauvegarde.';
        done.style.display='block';
      } else {
        title.innerHTML='✅ Terminé'; step.textContent=(s.op==='restore'?'Restauration effectuée.':'Sauvegarde créée : '+s.result);
        note.textContent=(s.op==='restore'?'Reconnectez-vous si besoin.':'La liste va se rafraîchir.');
        done.style.display='block';
        setTimeout(function(){location.reload();}, s.op==='restore'?2500:1400);
      }
    } else if(s.state==='error'){
      clearInterval(poll); poll=null;
      bar.className='gauge-bar err'; title.innerHTML='⚠ Échec';
      step.textContent='Erreur : '+(s.result||s.step); done.style.display='block';
    }
  }
  function track(op){
    show(op);
    poll=setInterval(function(){
      fetch('/sauvegarde.php?api=status',{headers:{'Accept':'application/json'}})
        .then(function(r){return r.json();}).then(update).catch(function(){});
    },1000);
  }
  function start(op,name){
    var b=new URLSearchParams(); b.set('csrf',CSRF); b.set('op',op); if(name)b.set('name',name);
    fetch('/sauvegarde.php?api=start',{method:'POST',body:b}).then(function(r){return r.json();})
      .then(function(res){ if(res.ok){track(op);} else {alert('Impossible de lancer : '+(res.msg||'occupé'));} })
      .catch(function(){alert('Erreur réseau.');});
  }

  document.getElementById('btncreate').addEventListener('click',function(){ start('create'); });
  var btnv=document.getElementById('btnverify');
  if(btnv) btnv.addEventListener('click',function(){ start('verify'); });
  [].forEach.call(document.querySelectorAll('.btnrestore'),function(b){
    b.addEventListener('click',function(){
      if(confirm('RESTAURER cette sauvegarde ? Les données actuelles (base, config, médias) seront REMPLACÉES.'))
        start('restore', b.dataset.name);
    });
  });

  // Bascule de la sauvegarde automatique.
  var tog=document.getElementById('autotoggle'), badge=document.getElementById('autobadge');
  tog.addEventListener('change',function(){
    var sub=tog.checked?'enable':'disable';
    var b=new URLSearchParams(); b.set('csrf',CSRF); b.set('sub',sub);
    tog.disabled=true;
    fetch('/sauvegarde.php?api=auto',{method:'POST',body:b}).then(function(r){return r.json();})
      .then(function(res){
        tog.disabled=false;
        if(res.ok){ badge.textContent=tog.checked?'Activée':'Désactivée'; badge.className='badge '+(tog.checked?'on':'off');
          if(tog.checked) setTimeout(function(){location.reload();},600); }
        else { tog.checked=!tog.checked; alert('Échec : '+(res.msg||'')); }
      }).catch(function(){tog.disabled=false;tog.checked=!tog.checked;alert('Erreur réseau.');});
  });

  // Reprise de l'affichage si une opération tourne déjà (rechargement de page).
  fetch('/sauvegarde.php?api=status').then(function(r){return r.json();}).then(function(s){
    if(s.state==='running'){ track(s.op||'create'); }
  }).catch(function(){});
})();
</script>
<?php pf_footer(); ?>
