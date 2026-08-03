<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — règles du mot de passe choisi par l'agent.
 *
 * Isolé de la page pour être VÉRIFIABLE : une règle de mot de passe enfouie dans du
 * HTML ne se teste pas, et c'est précisément la partie qu'il faut pouvoir prouver.
 * Aucune sortie, aucune dépendance : ce fichier peut être inclus par un banc d'essai.
 */

const MDP_MIN = 12;   // longueur minimale

/**
 * Renvoie la raison du refus, ou '' si le mot de passe est acceptable.
 *
 * Volontairement peu de règles, et toutes explicables à l'agent : une contrainte
 * qu'on ne sait pas énoncer est une contrainte qu'il contournera en ajoutant « 1 »
 * à la fin. La longueur fait l'essentiel du travail.
 */
function mdp_refus(string $nouveau, string $user, string $ancien): string {
    if (mb_strlen($nouveau) < MDP_MIN)  { return 'Le nouveau mot de passe doit faire au moins ' . MDP_MIN . ' caractères.'; }
    if (mb_strlen($nouveau) > 128)      { return 'Le nouveau mot de passe est trop long (128 caractères au maximum).'; }
    if ($nouveau === $ancien)           { return 'Le nouveau mot de passe doit être différent de l’actuel.'; }
    if ($user !== '' && mb_stripos($nouveau, $user) !== false) { return 'Le mot de passe ne doit pas contenir votre matricule.'; }
    // Un mot de passe uniquement numérique est deviné en quelques secondes — et c'est
    // le réflexe naturel quand on s'identifie déjà par un matricule.
    if (preg_match('/^\d+$/', $nouveau)) { return 'Le mot de passe ne doit pas être composé uniquement de chiffres.'; }
    if (trim($nouveau) !== $nouveau)     { return 'Le mot de passe ne doit pas commencer ni finir par un espace.'; }
    return '';
}
