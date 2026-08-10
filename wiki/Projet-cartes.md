# Cartes du projet GitHub

À créer dans **Projects → New project → Board**, trois colonnes : *À faire*, *En cours*,
*Fait*. Chaque bloc ci-dessous est une carte, titre puis corps.

---

## 🔴 Poser WPA2 sur le point d'accès Wi-Fi

Le réseau `BASTION` est **ouvert**, sans chiffrement. N'importe qui à portée obtient une
adresse et atteint le portail. Le filtrage impose bien une authentification pour sortir,
mais le réseau local est accessible avant toute identification.

La phrase de passe doit être choisie par l'exploitant et insérée dans
`/etc/hostapd/hostapd.conf`, puis `systemctl restart hostapd` — ce qui coupe brièvement les
clients connectés.

---

## 🔴 Remplacer le dongle Wi-Fi

La puce `rtw88_8822bu` ne tient pas le mode point d'accès dans la durée. Le journal du noyau
montre `error beacon valid` et `failed to download drv rsvd page` : le service se déclare
actif et n'émet plus.

Un rechargement du pilote débloque à chaque fois, mais ce n'est pas une solution. Chercher
une clé à chipset MediaTek (`mt7612u`, `mt7921u`) ou Atheros (`ath9k_htc`), connus pour
tenir le mode AP sous Linux. Une vingtaine d'euros.

Ne pas automatiser la détection sans un signal fiable : une tentative fondée sur le compteur
de paquets émis fabriquait la panne qu'elle devait corriger.

---

## 🟠 Sortir la sauvegarde de la machine

`bastion-20260808-090517.tar.gz.gpg` est sur le disque qu'elle protège. La télécharger
depuis la console et la déposer ailleurs. Conserver le secret de chiffrement séparément.

Puis définir une périodicité — aucune sauvegarde automatique n'est active aujourd'hui.

---

## 🟠 Instrumenter la lenteur de la page intranet

La page met **0,4 s** à répondre sur la passerelle elle-même, avant tout réseau. Mesuré
trois fois : 438, 405, 443 ms.

Une hypothèse a été testée et **infirmée** : allonger le cache du portail de 10 à 30 s n'a
rien changé. La cause est ailleurs.

Instrumenter la page — horodater chaque étape du rendu — plutôt que de supposer.

---

## 🟠 Photo absente à l'écran de connexion Windows

La photo apparaît au verrouillage mais pas au démarrage. Les vignettes locales et
l'attribut `thumbnailPhoto` de l'annuaire sont **tous deux corrects** (vérifié).

Piste restante : le moment de l'écriture. L'écran de connexion se dessine très tôt, à partir
d'un cache ; le script écrit peut-être trop tard.

Le contrôle qui tranche, sur un poste :

```powershell
Get-ChildItem "C:\Users\Public\AccountPictures" -Recurse -Filter Image448.jpg |
  Select-Object FullName, LastWriteTime
```

Date antérieure au dernier démarrage → l'hypothèse tombe. Postérieure → poser la vignette à
l'arrêt plutôt qu'au démarrage.

---

## 🟡 Activer le 802.11n sur le point d'accès

Le Wi-Fi tourne en 802.11g pur (`no HT`), plafonné à 54 Mb/s théoriques. Activer `ieee80211n`
et `ht_capab` triple le débit disponible et améliore la robustesse de la liaison.

À faire **après** le remplacement du dongle : inutile de régler un matériel qu'on remplace.

---

## 🟡 Rendre persistantes les règles de pare-feu du relais

La table nftables `bastion_distance` n'a pas survécu au redémarrage : créée avec `nft -f`
sans persistance. Les ports de la prise de main à distance sont donc refermés.

À inscrire dans `deploy.sh`, avec la règle `users_to_router` du portail — deux modifications
faites directement sur le serveur et non versionnées, qu'un redéploiement effacerait.

---

## 🟡 Vérifier l'exécution du store après le correctif de taille

Le contrôle de taille est déployé. Confirmer sur un poste que Firefox ESR cesse de se
retélécharger en boucle, et que la file va jusqu'au bout.

Journal : `C:\ProgramData\Bastion\apps.log`. Une ligne `ABANDONNE apres 3 echecs` nomme les
installeurs défaillants — c'est voulu, ils cessent de bloquer les autres.

---

## 🟢 Réduire le nombre de stratégies de groupe

29 stratégies liées à la racine du domaine, dont 10 portant un script de démarrage. Chacune
est traitée à chaque `gpupdate`. Ce n'est pas une panne, c'est une accumulation — mais elle
se paie à chaque ouverture de session.

---

## 🟢 Étoffer le wiki

Pages présentes : Accueil, Installation, Exploitation, Dépannage.

Manquent : l'architecture réseau avec un schéma, et la procédure de raccordement d'un second
commissariat.
