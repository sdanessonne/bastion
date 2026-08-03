<?php
// Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
/**
 * Bastion — mise en forme des notifications par courriel.
 *
 * ── CE QU'UNE NOTIFICATION DOIT FAIRE ────────────────────────────────────────
 * Elle est lue sur un téléphone, souvent en réunion, parfois la nuit. Le lecteur
 * doit savoir en DEUX SECONDES : est-ce grave, quelle passerelle, quoi faire.
 * Tout le reste vient après. C'est ce qui commande le format : l'objet porte
 * l'essentiel — les téléphones le tronquent vers 40 caractères — et le corps
 * répond aux trois questions avant tout habillage.
 *
 * ── DEUX VERSIONS, TOUJOURS ──────────────────────────────────────────────────
 * Chaque message part en « multipart/alternative » : une version texte et une
 * version HTML. Jamais HTML seul. Dans une administration, les passerelles de
 * messagerie retirent couramment le HTML, et un message dont le contenu vit
 * uniquement dans la partie HTML arriverait VIDE — une alerte muette de plus.
 * La version texte est écrite pour être lue, pas comme un repli dégradé.
 *
 * ── AUCUNE IMAGE, AUCUN LIEN EXTÉRIEUR ───────────────────────────────────────
 * Un logo chargé depuis la passerelle serait injoignable hors du réseau, et les
 * clients bloquent les images distantes par défaut : on obtiendrait un cadre
 * vide en haut du message. La mise en forme repose donc uniquement sur des
 * couleurs de fond et du texte.
 *
 * ── ATTENTION AU COMPTE QUI ENVOIE ───────────────────────────────────────────
 * /etc/msmtprc est en 600 root:root — il contient le mot de passe du relais.
 * L'envoi n'est donc possible QUE depuis un processus root. Le surveillant en
 * est un ; une page de la console, servie par « www-data », ne l'est pas et
 * échouerait sur « aucun fichier de configuration disponible ». Toute mise en
 * forme depuis la console doit passer par « sudo proxyfibre-mail ».
 */

if (!function_exists('pf_mail_sujet')) {
    /** Objet encodé en UTF-8 — sans cela les accents arrivent en charabia. */
    function pf_mail_sujet(string $s): string {
        return preg_match('/[\x80-\xFF]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }
}

if (!function_exists('pf_mail_notif')) {
    /**
     * Envoie une notification mise en forme.
     *
     * @param string   $niveau  'danger' | 'warn' | 'ok'
     * @param string   $titre   phrase courte, reprise dans l'objet
     * @param string   $constat ce qui a été observé, en une ou deux phrases
     * @param array    $faits   couples libellé => valeur (passerelle, date, service…)
     * @param string   $suite   ce qu'il convient de faire ; vide si rien
     */
    function pf_mail_notif(string $to, string $niveau, string $titre,
                           string $constat, array $faits = [], string $suite = ''): bool {

        if (!is_executable('/usr/sbin/sendmail')) { return false; }

        $C = [
            'danger' => ['#b91c1c', '#fef2f2', 'ALERTE',        '⛔'],
            'warn'   => ['#a16207', '#fffbeb', 'Avertissement', '⚠'],
            'ok'     => ['#15803d', '#f0fdf4', 'Rétabli',       '✔'],
        ];
        [$coul, $fond, $mot, $pic] = $C[$niveau] ?? $C['warn'];

        // L'objet : le niveau d'abord, puis le titre. Sur un téléphone, seuls les
        // premiers caractères sont visibles dans la liste — ils doivent suffire à
        // décider si l'on ouvre maintenant ou plus tard.
        $sujet = "[Bastion] {$mot} — {$titre}";

        // ── Version texte ────────────────────────────────────────────────────
        $t  = strtoupper($mot) . " — " . $titre . "\n";
        $t .= str_repeat('=', min(70, mb_strlen($t, 'UTF-8'))) . "\n\n";
        $t .= wordwrap($constat, 72, "\n", false) . "\n\n";
        foreach ($faits as $k => $v) { $t .= sprintf("  %-22s %s\n", $k . ' :', $v); }
        if ($suite !== '') { $t .= "\nÀ FAIRE\n" . wordwrap($suite, 72, "\n", false) . "\n"; }
        $t .= "\n-- \nMessage automatique de la passerelle Bastion. Ne pas répondre.\n";

        // ── Version HTML ─────────────────────────────────────────────────────
        // Tableaux et styles EN LIGNE : les clients de messagerie retirent les
        // feuilles de style du « head », et Outlook ignore une bonne part de la
        // mise en page moderne. Le tableau reste ce qui s'affiche partout.
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $h  = '<!doctype html><html lang="fr"><body style="margin:0;padding:0;background:#f1f5f9;">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">';

        // Bandeau de niveau : la couleur porte l'information avant la lecture,
        // et le mot la porte pour qui ne distingue pas les couleurs.
        $h .= '<tr><td style="background:' . $coul . ';color:#ffffff;padding:16px 22px;font-size:15px;font-weight:700;">'
            . $pic . '&nbsp;&nbsp;' . $e($mot) . '</td></tr>';

        $h .= '<tr><td style="padding:22px;">'
            . '<div style="font-size:19px;font-weight:700;color:#0f172a;line-height:1.35;">' . $e($titre) . '</div>'
            . '<div style="font-size:14px;color:#334155;line-height:1.65;margin-top:10px;">' . $e($constat) . '</div>';

        if ($faits) {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
                . 'style="margin-top:18px;border-collapse:collapse;font-size:13.5px;">';
            foreach ($faits as $k => $v) {
                $h .= '<tr>'
                    . '<td style="padding:7px 0;color:#64748b;width:38%;border-bottom:1px solid #f1f5f9;vertical-align:top;">' . $e($k) . '</td>'
                    . '<td style="padding:7px 0;color:#0f172a;border-bottom:1px solid #f1f5f9;font-weight:600;">' . $e($v) . '</td>'
                    . '</tr>';
            }
            $h .= '</table>';
        }

        if ($suite !== '') {
            $h .= '<div style="margin-top:18px;background:' . $fond . ';border-left:3px solid ' . $coul . ';'
                . 'padding:12px 14px;font-size:13.5px;color:#0f172a;line-height:1.6;">'
                . '<strong>À faire</strong><br>' . $e($suite) . '</div>';
        }

        $h .= '</td></tr>'
            . '<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 22px;'
            . 'font-size:11.5px;color:#64748b;line-height:1.6;">'
            . 'Message automatique de la passerelle Bastion — ne pas répondre.<br>'
            . 'Le détail est consultable dans la console d\'administration.'
            . '</td></tr></table></td></tr></table></body></html>';

        // ── Assemblage ───────────────────────────────────────────────────────
        $b = 'bastion_' . bin2hex(random_bytes(12));
        $corps  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $t . "\r\n";
        $corps .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $h . "\r\n";
        $corps .= "--{$b}--\r\n";

        $hote = trim((string) shell_exec('hostname 2>/dev/null')) ?: 'bastion';
        $en  = "MIME-Version: 1.0\r\n";
        $en .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
        // « Auto-Submitted » évite qu'un répondeur d'absence réponde à la machine,
        // et qu'une boucle s'installe entre l'alerte et sa réponse automatique.
        $en .= "Auto-Submitted: auto-generated\r\n";
        $en .= "X-Bastion-Gateway: " . preg_replace('/[^\x20-\x7E]/', '', $hote) . "\r\n";
        if ($niveau === 'danger') { $en .= "X-Priority: 1\r\nImportance: high\r\n"; }

        return @mail($to, pf_mail_sujet($sujet), $corps, $en);
    }
}
