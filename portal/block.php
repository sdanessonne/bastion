<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — page d'information de blocage. Servie pour tout domaine filtré (qui
 * résout vers la passerelle). Identifie le domaine demandé (en-tête Host), retrouve
 * sa catégorie/motif dans la liste de blocage et l'explique à l'utilisateur.
 */
http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$host = strtolower(preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
$host = preg_replace('/:\d+$/', '', $host);

// Recherche du motif/catégorie (best-effort, lecture seule).
$category = '';
$env = @parse_ini_file('/etc/proxyfibre/admin.env');
$pdo = null;
if ($env && $host !== '') {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=' . ($env['DB_NAME'] ?? 'radius') . ';charset=utf8mb4',
            $env['DB_USER'] ?? 'radius', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    } catch (Throwable $e) { $pdo = null; }
}

// ── L'appareil lui-même n'est PAS un « site filtré ». Si le Host demandé est le nom
// de la passerelle (realm AD, nom court, IP LAN/DC, localhost), rediriger vers
// l'intranet Bastion (site interne légitime) au lieu d'afficher la page de blocage. ──
$gwIp = trim((string) @shell_exec("hostname -I 2>/dev/null | tr ' ' '\\n' | grep -m1 '^192\\.168\\.182\\.'")) ?: '192.168.182.1';
$realm = '';
if ($pdo) {
    try { $realm = strtolower((string) $pdo->query("SELECT v FROM pf_settings WHERE k='ad_realm' LIMIT 1")->fetchColumn()); } catch (Throwable $e) {}
}
$self = ['localhost', $gwIp, '192.168.182.1', '192.168.182.2'];
if ($realm !== '') {
    $self[] = $realm;                         // ex. bastion.pn.int
    $self[] = strtok($realm, '.');            // nom court, ex. bastion
    $self[] = 'www.' . $realm;
}
if ($host !== '' && in_array($host, $self, true)) {
    header('Location: http://' . $gwIp . ':2080/portal/intranet.php', true, 302);
    exit;
}

if ($pdo && $host !== '') {
    try {
        // Correspondance sur le domaine ou un domaine parent (ex. sous-domaine).
        $parts = explode('.', $host); $cands = [];
        for ($i = 0; $i < count($parts) - 1; $i++) { $cands[] = implode('.', array_slice($parts, $i)); }
        if ($cands) {
            $in = implode(',', array_fill(0, count($cands), '?'));
            $st = $pdo->prepare("SELECT category FROM pf_blocklist WHERE domain IN ($in) LIMIT 1");
            $st->execute($cands);
            $category = (string) ($st->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {}
}

$labels = [
    'manuel'       => "Ce site a été ajouté manuellement à la liste des sites interdits par l'administration.",
    'adulte'       => "Ce site diffuse du contenu réservé aux adultes, interdit sur le réseau.",
    'publicite'    => "Ce domaine sert de la publicité ou du pistage, bloqué pour votre sécurité.",
    'reseaux'      => "L'accès aux réseaux sociaux est restreint sur ce réseau professionnel.",
    'jeux'         => "Les sites de jeux et paris sont interdits sur ce réseau.",
    'streaming'    => "Les plateformes de streaming/vidéo sont restreintes sur ce réseau.",
    'malveillant'  => "Ce domaine est identifié comme dangereux (hameçonnage, logiciel malveillant).",
];
$reason = $labels[$category] ?? "L'accès à ce site est restreint par la politique de sécurité du réseau.";
$catLabel = $category !== '' ? ucfirst($category) : 'Politique du réseau';
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accès bloqué — Bastion</title>
<style>
  :root{--bg:#0b1120;--panel:#151f32;--line:#28374f;--text:#e6edf6;--muted:#8ea2bd;--accent:#38bdf8;--danger:#f87171}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--text);
    background:radial-gradient(1100px 600px at 50% -10%,#3a1526,#0b1120 65%);display:grid;place-items:center;padding:1.5rem}
  .card{width:100%;max-width:560px;background:var(--panel);border:1px solid var(--line);border-radius:18px;
    box-shadow:0 30px 80px rgba(0,0,0,.55);overflow:hidden;animation:pop .5s cubic-bezier(.16,1,.3,1)}
  @keyframes pop{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
  .head{background:linear-gradient(120deg,#7f1d1d,#3a1526);padding:1.6rem 1.8rem;display:flex;align-items:center;gap:1rem}
  .shield{font-size:2.6rem;filter:drop-shadow(0 4px 12px rgba(248,113,113,.5))}
  .head h1{margin:0;font-size:1.4rem;letter-spacing:.3px}
  .head .sub{color:#fecaca;font-size:.9rem;margin-top:.15rem}
  .body{padding:1.6rem 1.8rem}
  .dom{display:inline-block;font-family:ui-monospace,"Cascadia Code",monospace;background:var(--bg);border:1px solid var(--line);
    border-radius:8px;padding:.4rem .7rem;color:#fff;word-break:break-all;margin:.3rem 0 1.1rem}
  .why{background:rgba(248,113,113,.08);border-left:4px solid var(--danger);border-radius:8px;padding:.9rem 1.1rem;line-height:1.6}
  .why b{color:#fca5a5}
  .cat{display:inline-block;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;background:rgba(248,113,113,.18);
    color:#fca5a5;border-radius:20px;padding:.2rem .7rem;margin-bottom:.6rem}
  .note{color:var(--muted);font-size:.86rem;line-height:1.6;margin-top:1.2rem}
  .foot{border-top:1px solid var(--line);padding:1rem 1.8rem;display:flex;align-items:center;justify-content:space-between;
    color:var(--muted);font-size:.78rem;flex-wrap:wrap;gap:.5rem}
  .brand{display:flex;align-items:center;gap:.5rem;font-weight:600;color:var(--text)}
  .brand .b{color:var(--accent)}
</style>
</head>
<body>
  <main class="card">
    <div class="head">
      <div class="shield">🛡️</div>
      <div><h1>Accès bloqué</h1><div class="sub">Ce site est filtré par la passerelle Bastion</div></div>
    </div>
    <div class="body">
      <div class="cat">⛔ <?= $e($catLabel) ?></div>
      <p style="margin:.2rem 0 .2rem;color:var(--muted)">Vous avez tenté d'accéder à :</p>
      <div class="dom"><?= $e($host ?: 'site inconnu') ?></div>
      <div class="why"><b>Pourquoi ce blocage ?</b><br><?= $e($reason) ?></div>
      <p class="note">Si vous pensez que ce site devrait être autorisé dans le cadre de votre service,
      contactez l'administrateur du réseau en précisant l'adresse ci-dessus. Toute tentative d'accès est
      journalisée conformément à la réglementation en vigueur.</p>
    </div>
    <div class="foot">
      <span class="brand">🏰 <span class="b">Bastion</span> — contrôle d'accès réseau</span>
      <span><?= $e(date('d/m/Y H:i')) ?></span>
    </div>
  </main>
</body>
</html>
