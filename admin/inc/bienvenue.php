<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Écran d'accueil affiché UNE FOIS, juste après une connexion réussie.
 *
 * ── POURQUOI CE N'EST PAS QU'UNE DÉCORATION ──────────────────────────────────
 * Il aurait été facile d'afficher « Bonjour » et une photo. L'occasion sert à autre
 * chose : c'est le seul moment où l'administrateur regarde vraiment l'écran, et donc
 * le seul moment où on peut lui montrer CE QUI S'EST PASSÉ SUR SON COMPTE EN SON
 * ABSENCE — date et adresse de la connexion précédente, et nombre de tentatives
 * échouées depuis. C'est la manière la plus simple de repérer un compte qu'on essaie
 * de forcer : personne ne va lire un journal tous les matins, mais tout le monde
 * remarque « 14 tentatives échouées depuis votre dernière connexion ».
 *
 * ── DEUX TEMPS, ET C'EST VOULU ───────────────────────────────────────────────
 * 1. bienvenue_preparer() est appelée À L'INSTANT de la connexion : c'est le seul
 *    moment où l'on peut encore lire la ligne PRÉCÉDENTE dans pf_login_attempts.
 *    Le résultat est rangé en session.
 * 2. bienvenue_afficher() le consomme sur la page suivante, puis l'efface — l'écran
 *    ne réapparaît pas à chaque navigation.
 *
 * Aucune de ces deux fonctions ne doit pouvoir empêcher une connexion : tout est
 * encapsulé, et l'absence de données produit simplement un accueil plus court.
 */

/**
 * Rassemble les informations d'accueil et les range en session.
 * À appeler APRÈS l'enregistrement de la tentative réussie : la connexion en cours est
 * alors la ligne la plus récente, et « OFFSET 1 » désigne bien la connexion PRÉCÉDENTE.
 */
function bienvenue_preparer(PDO $db, string $user): void
{
    $b = ['user' => $user, 'nom' => '', 'role' => '', 'totp' => false,
          'prec_ts' => '', 'prec_ip' => '', 'echecs' => 0];

    try {
        $st = $db->prepare('SELECT role, totp_enabled FROM pf_admins WHERE username = ?');
        $st->execute([$user]);
        if ($r = $st->fetch()) {
            $b['role'] = (string) ($r['role'] ?? '');
            $b['totp'] = !empty($r['totp_enabled']);
        }
    } catch (Throwable $e) {}

    // Identité lisible. Un compte d'administration est le matricule de l'agent préfixé
    // de « admin- » : on retire le préfixe pour retrouver sa fiche.
    $mat = preg_replace('/^admin-/', '', $user);
    try {
        $st = $db->prepare('SELECT nom, prenom FROM pf_user_profile WHERE username = ?');
        $st->execute([$mat]);
        if ($r = $st->fetch()) {
            $b['nom'] = trim(((string) $r['prenom']) . ' ' . mb_strtoupper((string) $r['nom']));
        }
    } catch (Throwable $e) {}
    $b['matricule'] = $mat !== $user ? $mat : '';

    // Connexion précédente, lue dans le JOURNAL D'AUDIT et non dans pf_login_attempts.
    // La nuance est importante : throttle_finish() marque une tentative « réussie » dès
    // que le MOT DE PASSE est bon, AVANT l'étape 2FA. Sur un compte protégé par double
    // authentification, une tentative interrompue au second facteur y figurerait donc
    // comme une réussite — et l'accueil annoncerait une connexion qui n'a jamais eu lieu.
    // L'entrée d'audit « login », elle, n'est écrite qu'une fois la session réellement
    // ouverte. « OFFSET 1 » saute la connexion en cours pour désigner la précédente.
    try {
        $st = $db->prepare("SELECT ts, ip FROM pf_audit
                            WHERE admin = ? AND action = 'login' ORDER BY ts DESC LIMIT 1 OFFSET 1");
        $st->execute([$user]);
        if ($r = $st->fetch()) {
            $b['prec_ts'] = (string) $r['ts'];
            $b['prec_ip'] = (string) ($r['ip'] ?? '');
            // Les ÉCHECS, eux, se comptent bien dans pf_login_attempts : c'est la table
            // des tentatives, et un échec de mot de passe y est enregistré tel quel.
            $st = $db->prepare('SELECT COUNT(*) FROM pf_login_attempts
                                WHERE username = ? AND ok = 0 AND ts > ?');
            $st->execute([$user, $b['prec_ts']]);
            $b['echecs'] = (int) $st->fetchColumn();
        }
    } catch (Throwable $e) {}

    $_SESSION['bienvenue'] = $b;
}

/** Rend l'écran d'accueil et le CONSOMME. Chaîne vide s'il n'y a rien à afficher. */
function bienvenue_afficher(): string
{
    if (empty($_SESSION['bienvenue'])) { return ''; }
    $b = $_SESSION['bienvenue'];
    unset($_SESSION['bienvenue']);   // consommé : ne réapparaît pas en naviguant

    $h = (int) date('G');
    $salut = $h < 5 ? 'Bonne nuit' : ($h < 18 ? 'Bonjour' : 'Bonsoir');
    $qui = $b['nom'] !== '' ? $b['nom'] : $b['user'];
    $roles = ['full' => 'Administration complète', 'lecture' => 'Lecture seule',
              'reseau' => 'Réseau', 'annuaire' => 'Annuaire', 'postes' => 'Postes'];
    $role = $roles[$b['role']] ?? $b['role'];

    // Date en toutes lettres : « 25 juillet à 19:05 » se lit mieux qu'un horodatage.
    $prec = '';
    if ($b['prec_ts'] !== '') {
        $t = strtotime($b['prec_ts']);
        $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
                 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $jour = (int) date('j', $t) === (int) date('j') && date('Y-m', $t) === date('Y-m')
              ? "aujourd'hui" : (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))
              ? 'hier' : 'le ' . date('j', $t) . ' ' . $mois[(int) date('n', $t)]);
        $prec = $jour . ' à ' . date('H:i', $t);
    }

    // Repli sur les initiales. « avatar_v » est vide tant qu'aucune photo n'a été
    // téléversée : afficher quand même la balise image donnerait l'icône de fichier
    // cassé du navigateur en plein milieu de l'écran d'accueil.
    $av = (string) ($_SESSION['avatar_v'] ?? '');
    $ini = mb_strtoupper(mb_substr($b['nom'] !== '' ? $b['nom'] : $b['user'], 0, 1));

    ob_start(); ?>
<div id="bienvenue" class="bienv" role="status" aria-live="polite">
  <div class="bienv-carte">
    <?php if ($av !== ''): ?>
      <img class="bienv-photo" src="/avatar.php?v=<?= e($av) ?>" alt="">
    <?php else: ?>
      <div class="bienv-photo bienv-ini" aria-hidden="true"><?= e($ini) ?></div>
    <?php endif; ?>
    <div class="bienv-salut"><?= e($salut) ?></div>
    <div class="bienv-nom"><?= e($qui) ?></div>
    <div class="bienv-meta">
      <?php if (!empty($b['matricule'])): ?><span>Matricule <?= e($b['matricule']) ?></span><?php endif; ?>
      <?php if ($role !== ''): ?><span><?= e($role) ?></span><?php endif; ?>
    </div>
    <div class="bienv-info">
      <?php if ($prec !== ''): ?>
        <div class="bienv-l"><span class="bienv-ic">🕘</span>
          Dernière connexion <strong><?= e($prec) ?></strong>
          <?php if ($b['prec_ip'] !== ''): ?><span class="muted">depuis <?= e($b['prec_ip']) ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="bienv-l"><span class="bienv-ic">✨</span> Première connexion à la console.</div>
      <?php endif; ?>
      <?php if ((int) $b['echecs'] > 0): ?>
        <?php // Le seul élément qui a le droit d'attirer l'œil : il peut signaler qu'on
              // essaie d'entrer sur ce compte. ?>
        <div class="bienv-l alerte"><span class="bienv-ic">⚠️</span>
          <strong><?= (int) $b['echecs'] ?></strong> tentative<?= $b['echecs'] > 1 ? 's' : '' ?>
          de connexion échouée<?= $b['echecs'] > 1 ? 's' : '' ?> depuis.
          <a href="/journal.php">Consulter le journal</a>
        </div>
      <?php endif; ?>
      <?php if (empty($b['totp'])): ?>
        <div class="bienv-l"><span class="bienv-ic">🔓</span>
          Double authentification <strong>désactivée</strong>.
          <a href="/profil.php">L'activer</a>
        </div>
      <?php endif; ?>
    </div>
    <button type="button" class="bienv-ok" id="bienvOk">Continuer</button>
  </div>
</div>
<script>
(function(){
  var d=document.getElementById('bienvenue'); if(!d) return;
  var b=document.getElementById('bienvOk');
  // Le bouton prend le focus : la fermeture au clavier marche sans avoir a tabuler,
  // et un lecteur d'ecran annonce l'ecran au lieu de le laisser passer inapercu.
  if(b) b.focus();
  var fini=false;
  function fermer(){
    if(fini) return; fini=true;
    d.classList.add('hide');
    setTimeout(function(){ if(d.parentNode) d.parentNode.removeChild(d); },400);
  }
  if(b) b.addEventListener('click',fermer);
  d.addEventListener('click',function(e){ if(e.target===d) fermer(); });   // clic hors carte
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'||e.key==='Enter') fermer();
  });
  // Fermeture automatique. Volontairement GENEREUSE quand il y a quelque chose a lire :
  // une alerte de tentatives echouees qui disparait en 3 s ne sert a rien.
  var lecture = d.querySelector('.bienv-l.alerte') ? 14000 : 7000;
  setTimeout(fermer, lecture);
})();
</script>
<?php
    return (string) ob_get_clean();
}
