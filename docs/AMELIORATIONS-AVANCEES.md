# Pistes avancées — note de cadrage

Trois chantiers plus lourds, à décider **un par un**. Pour chacun : le problème, la
solution réaliste dans le contexte Bastion (appareil unique, mono-site, commissariat),
l'effort, les limites, et une recommandation.

---

## A. Deuxième contrôleur de domaine (résilience de l'AD)

**Problème.** L'AD Samba tourne sur **une seule** passerelle. Si la VM tombe, plus
d'authentification, plus de GPO, plus d'ouverture de session sur les postes.

**Solution.** Joindre un 2ᵉ contrôleur de domaine Samba en réplique (`samba-tool domain
join … DC`), réplication multi-maître de l'annuaire.

**Limites réelles.**
- Un 2ᵉ DC sur **la même machine hôte** ne protège de rien (si l'hôte meurt, les deux
  tombent). Il faut un **second hôte physique** — rarement disponible dans un petit
  commissariat.
- La réplication du **SYSVOL** (donc des GPO) n'est **pas automatique** sous Samba : il
  faut un `rsync`/`osync` périodique entre DC — point de fragilité connu.
- Bastion n'est pas *qu'*un DC : c'est aussi la passerelle (OpenNDS, filtrage, DHCP,
  RADIUS). Un 2ᵉ DC réplique l'annuaire, **pas** la fonction passerelle.

**Effort : élevé.** Second hôte + DNS (les deux DC comme résolveurs) + rôles FSMO +
réplication SYSVOL à outiller.

**Recommandation.** Pour un mono-site, **peu rentable**. Meilleur rapport
effort/bénéfice : un **runbook de restauration testé** (restaurer l'AD depuis la
sauvegarde chiffrée sur une VM neuve, `samba-tool domain backup restore`), objectif
« repartir en ~30 min ». Je peux l'écrire **et le vérifier par un exercice de
restauration**. À ne faire en 2ᵉ DC que s'il existe un second hôte **et** un besoin de
zéro interruption.

---

## B. Chiffrement LUKS du disque de la passerelle (secrets au repos)

**Problème.** Le disque de la VM contient l'AD (empreintes + clés BitLocker), la base
(journaux, identifiants), les configs **et** la clé de chiffrement des sauvegardes. Si
le disque virtuel est copié (vol, sauvegarde de l'hôte, mise au rebut), **tout fuite**.

**Solution.** Chiffrement intégral du disque (LUKS) de la Debian.

**Le point délicat : le déverrouillage au démarrage.** Un appareil doit pouvoir
**redémarrer seul** (coupure de courant). Or LUKS réclame une phrase au boot. Options :
- **TPM (clevis + tpm2)** — la VM a déjà un **vTPM** (activé pour BitLocker). Le disque
  se déverrouille **tout seul sur le même hôte**, mais reste **verrouillé si on le
  déplace** ailleurs. C'est le bon compromis : reboot autonome + protection au vol.
- Tang (réseau) — nécessite un autre serveur.
- Phrase manuelle — casse le reboot autonome (à éviter).

**Limite.** LUKS se pose **à l'installation** (partition racine chiffrée). Le rétrofit
sur le système déjà en service = quasi-réinstallation (risqué).

**Effort : moyen** côté provisioning, **lourd** en rétrofit.

**Recommandation.** Intégrer **LUKS + clevis-TPM2 au script de provisioning** pour les
**futures** installations (chaque nouveau commissariat a un disque chiffré), et
documenter la migration des passerelles existantes (sauvegarde → réinstallation chiffrée
→ restauration). Concret, sans perturber la VM en production.

---

## C. Détection d'anomalies (réseau / AD)  ⭐ recommandé en premier

**Problème.** Aucune alerte aujourd'hui sur les événements suspects.

**Détecteurs proposés** (incrémentaux, réutilisent le canal d'alerte courriel du
`watchdog` déjà en place + le journal d'audit) :
1. **Nouvel appareil sur le LAN** — diff des baux DHCP (dnsmasq) contre les MAC connues
   (réservations DHCP + inventaire des postes) → alerte sur MAC inconnue.
2. **Changement des groupes d'administration AD** — surveille les membres de *Domain
   Admins* / *Administrators* → alerte sur ajout/retrait.
3. **GPO modifiée hors console** — instantané des versions de GPO → alerte sur une GPO
   ajoutée, supprimée ou dont la version change de façon inattendue.
4. (option) **Pics d'échecs d'authentification** — au-delà d'un seuil (RADIUS/console).

**Effort : moyen.** Scripts périodiques (systemd timer) + envoi via le mécanisme
d'alerte existant + affichage sur la page **Santé & sécurité**.

**Recommandation.** **Meilleur rapport effort/valeur des trois.** Commencer par les
détecteurs 1, 2 et 3, chacun activable indépendamment.

---

## Synthèse — ordre conseillé

| Piste | Effort | Valeur (mono-site) | Verdict |
|---|---|---|---|
| **C. Détection d'anomalies** | moyen | élevée | **À faire en premier** |
| **B. LUKS au provisioning** | moyen | élevée (au repos) | À faire pour les futures installs |
| **A. 2ᵉ DC** | élevé | faible sans 2ᵉ hôte | Remplacer par un **runbook de restauration testé** |
