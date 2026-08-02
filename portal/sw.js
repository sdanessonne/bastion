/* Bastion — service worker de l'intranet (installabilité + cache + mode hors-ligne). */
const CACHE = 'bastion-intranet-v3';
const OFFLINE = '/portal/intranet/offline.html';
const SHELL = [OFFLINE, '/portal/assets/bastion-icon.svg', '/portal/assets/icon-192.png'];

/* ── CE QUI NE DOIT JAMAIS ÊTRE MIS EN CACHE ──────────────────────────────────
 * La version précédente conservait TOUTE réponse réussie. Cela incluait
 * « account.php » — consommation, quotas, matricule — et « photo.php », la photo
 * de l'agent. Ce cache SURVIT À LA DÉCONNEXION.
 *
 * Sur un téléphone de service partagé, un agent pouvait donc se voir servir
 * depuis le cache le tableau de bord du précédent. Et l'en-tête
 * « Cache-Control: private » posé côté serveur n'y changeait rien : un service
 * worker ne le lit pas, il décide seul. C'est ce qui rend l'oubli dangereux —
 * la protection habituelle ne s'applique tout simplement pas ici.
 *
 * Le doute profite à l'exclusion : une page non mise en cache coûte un
 * aller-retour réseau, une page mise en cache à tort coûte une fuite. */
const JAMAIS = [
  '/portal/account.php',   // consommation, quotas, identité de l'agent
  '/portal/photo.php',     // photographie de l'agent
  '/portal/fas.php',       // page d'identification
  '/portal/logout.php',    // déconnexion
  '/portal/moi.php',       // identité affichée dans l'en-tête (nom, photo)
  '/portal/ca.crt.php',    // certificat d'autorité (sans objet hors ligne)
];

/* Contenu PUBLIC : identique pour tous les agents, donc conservable même avec des
 * paramètres. C'est exactement ce qu'on veut pouvoir lire hors ligne — une note de
 * service, une procédure. Les exclure au seul motif qu'ils portent un « ?slug= »
 * viderait le mode hors ligne de son intérêt. */
var PUBLIC = ['/portal/intranet/page.php', '/portal/intranet/uploads/',
              '/portal/intranet/actualite.php', '/portal/intranet/actualites.php'];

function prive(url) {
  var u = new URL(url);
  if (JAMAIS.indexOf(u.pathname) !== -1) { return true; }
  for (var i = 0; i < PUBLIC.length; i++) {
    if (u.pathname === PUBLIC[i] || u.pathname.indexOf(PUBLIC[i]) === 0) { return false; }
  }
  // Pour tout le reste, un paramètre signale une réponse qui VARIE d'un agent à
  // l'autre : servir depuis le cache celle destinée à un autre serait pire que ne
  // rien servir. Le doute profite donc à l'exclusion.
  return u.search !== '';
}

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

/* Purge demandée par la page à la déconnexion. Vider entièrement le cache est le
 * seul moyen d'être sûr qu'aucune page personnelle ne subsiste : même avec la
 * liste d'exclusion, une version ANTÉRIEURE du service worker a pu en conserver
 * sur les appareils déjà équipés. */
self.addEventListener('message', function (e) {
  if (e.data === 'purge') {
    e.waitUntil(caches.keys().then(function (ks) {
      return Promise.all(ks.map(function (k) { return caches.delete(k); }));
    }).then(function () {
      // On remet la coquille hors-ligne : sans elle, l'agent qui perd le réseau
      // après s'être déconnecté tomberait sur l'écran d'erreur du navigateur.
      return caches.open(CACHE).then(function (c) { return c.addAll(SHELL); });
    }));
  }
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') { return; }

  if (prive(e.request.url)) {
    // Ni écriture ni lecture du cache. Hors ligne, on rend la page hors-ligne
    // plutôt qu'une donnée périmée qui pourrait ne pas être la sienne.
    e.respondWith(fetch(e.request).catch(function () {
      return e.request.mode === 'navigate' ? caches.match(OFFLINE) : Response.error();
    }));
    return;
  }

  e.respondWith(
    fetch(e.request).then(function (r) {
      if (r && r.status === 200 && r.type === 'basic') {
        var copy = r.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copy); });
      }
      return r;
    }).catch(function () {
      return caches.match(e.request).then(function (m) {
        if (m) { return m; }
        if (e.request.mode === 'navigate') { return caches.match(OFFLINE); }
        return undefined;
      });
    })
  );
});
