namespace Bastion.StationBlanche;

/// <summary>
/// Fenêtre « Préparer une clé de service chiffrée » (BitLocker To Go).
///
/// Volontairement SÉPARÉE de l'analyse : la station ne modifie jamais un support de constat.
/// Ici, l'agent prépare une clé DU SERVICE. L'écran l'avertit, exige une confirmation
/// explicite (« ce n'est pas un scellé »), puis chiffre la clé et affiche/escrowe la clé de
/// récupération. Ouverte depuis MainForm (Ctrl+Maj+P) uniquement si la fonction est autorisée.
/// </summary>
public sealed class PreparationCleForm : Form
{
    private readonly BastionApi _api;

    private static readonly Color Fond = Color.FromArgb(11, 17, 32);
    private static readonly Color Carte = Color.FromArgb(21, 31, 50);
    private static readonly Color Encre = Color.FromArgb(226, 232, 240);
    private static readonly Color Grise = Color.FromArgb(148, 163, 184);
    private static readonly Color Rouge = Color.FromArgb(248, 113, 113);
    private static readonly Color Vert = Color.FromArgb(74, 222, 128);
    private static readonly Color Bleu = Color.FromArgb(56, 189, 248);

    private readonly ComboBox _lecteurs = new();
    private readonly Button _actualiser = new(), _chiffrer = new(), _fermer = new();
    private readonly CheckBox _confirme = new();
    private readonly TextBox _mdp1 = new(), _mdp2 = new();
    private readonly Label _etat = new();

    public PreparationCleForm(BastionApi api)
    {
        _api = api;
        Text = "Bastion — Préparer une clé de service chiffrée";
        BackColor = Fond; ForeColor = Encre; Font = new Font("Segoe UI", 11F);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        StartPosition = FormStartPosition.CenterParent;
        MaximizeBox = false; MinimizeBox = false;
        ClientSize = new Size(660, 560);
        try { Icon = Marque.Icone(32); } catch { }
        Construire();
        Load += (_, _) => RafraichirLecteurs();
    }

    private Label Ajouter(string texte, int x, int y, int l, Color c, float taille = 11F, FontStyle style = FontStyle.Regular)
    {
        var lbl = new Label { Text = texte, ForeColor = c, AutoSize = false, Font = new Font("Segoe UI", taille, style) };
        lbl.SetBounds(x, y, l, texte.Length > 90 ? 60 : 26);
        Controls.Add(lbl);
        return lbl;
    }

    private void Construire()
    {
        Ajouter("🔒  Préparer une clé de service chiffrée", 28, 22, 600, Color.White, 16F, FontStyle.Bold);

        // Avertissement — le point qui réconcilie la fonction avec le principe de la station.
        var av = new Panel { BackColor = Color.FromArgb(40, 20, 20) };
        av.SetBounds(28, 62, 604, 88);
        var avl = new Label
        {
            ForeColor = Rouge, AutoSize = false, Dock = DockStyle.Fill, Padding = new Padding(12, 8, 12, 8),
            Text = "⚠️  Cette opération CHIFFRE et MODIFIE la clé. Ne l'utilisez JAMAIS sur un scellé, "
                 + "une pièce, ou la clé d'un tiers.\nRéservée aux clés DU SERVICE que l'on veut sécuriser. "
                 + "Les données présentes sont conservées (chiffrées) ; prévoir tout de même une sauvegarde.",
            Font = new Font("Segoe UI", 10F),
        };
        av.Controls.Add(avl); Controls.Add(av);

        Ajouter("Clé USB à chiffrer", 28, 164, 300, Grise, 10F);
        _lecteurs.SetBounds(28, 190, 480, 30);
        _lecteurs.DropDownStyle = ComboBoxStyle.DropDownList;
        _lecteurs.BackColor = Carte; _lecteurs.ForeColor = Encre; _lecteurs.FlatStyle = FlatStyle.Flat;
        _lecteurs.Format += (_, e) => { if (e.ListItem is BitLockerCle.Support s) e.Value = $"{s.Lettre}   {s.Nom}   ({Octets(s.Taille)})"; };
        Controls.Add(_lecteurs);
        StyleBouton(_actualiser, "⟳ Actualiser"); _actualiser.SetBounds(520, 190, 112, 30);
        _actualiser.Click += (_, _) => RafraichirLecteurs();

        Ajouter("Mot de passe de la clé (8 caractères minimum)", 28, 236, 400, Grise, 10F);
        StyleChamp(_mdp1); _mdp1.SetBounds(28, 262, 300, 30); _mdp1.UseSystemPasswordChar = true;
        Ajouter("Confirmer le mot de passe", 348, 236, 300, Grise, 10F);
        StyleChamp(_mdp2); _mdp2.SetBounds(348, 262, 284, 30); _mdp2.UseSystemPasswordChar = true;

        _confirme.SetBounds(28, 306, 604, 44);
        _confirme.Text = "Je confirme que cette clé appartient au service et n'est PAS une pièce ou un scellé.";
        _confirme.ForeColor = Encre; _confirme.AutoSize = false; _confirme.Font = new Font("Segoe UI", 10F);
        Controls.Add(_confirme);

        StyleBouton(_chiffrer, "🔒  Chiffrer la clé"); _chiffrer.SetBounds(28, 360, 220, 42);
        _chiffrer.BackColor = Color.FromArgb(30, 58, 95);
        _chiffrer.Click += async (_, _) => await LancerAsync();
        StyleBouton(_fermer, "Fermer"); _fermer.SetBounds(258, 360, 120, 42);
        _fermer.Click += (_, _) => Close();

        _etat.SetBounds(28, 416, 604, 128);
        _etat.ForeColor = Grise; _etat.AutoSize = false; _etat.Font = new Font("Consolas", 10F);
        Controls.Add(_etat);
    }

    private void StyleBouton(Button b, string t)
    {
        b.Text = t; b.FlatStyle = FlatStyle.Flat; b.ForeColor = Encre;
        b.BackColor = Carte; b.FlatAppearance.BorderColor = Color.FromArgb(60, 76, 100);
        Controls.Add(b);
    }
    private void StyleChamp(TextBox t)
    {
        t.BackColor = Carte; t.ForeColor = Encre; t.BorderStyle = BorderStyle.FixedSingle;
        Controls.Add(t);
    }

    private void RafraichirLecteurs()
    {
        _lecteurs.Items.Clear();
        foreach (var s in BitLockerCle.LecteursAmovibles()) _lecteurs.Items.Add(s);
        if (_lecteurs.Items.Count > 0) _lecteurs.SelectedIndex = 0;
        _etat.ForeColor = Grise;
        _etat.Text = _lecteurs.Items.Count == 0
            ? "Aucune clé USB amovible détectée. Branchez une clé puis « Actualiser »."
            : "";
    }

    private async Task LancerAsync()
    {
        if (_lecteurs.SelectedItem is not BitLockerCle.Support cible)
        { Message(Rouge, "Sélectionnez une clé USB."); return; }
        if (!_confirme.Checked)
        { Message(Rouge, "Cochez la confirmation : cette clé n'est pas un scellé."); return; }
        if (_mdp1.Text.Length < 8)
        { Message(Rouge, "Le mot de passe doit faire au moins 8 caractères."); return; }
        if (_mdp1.Text != _mdp2.Text)
        { Message(Rouge, "Les deux mots de passe ne correspondent pas."); return; }
        if (!BitLockerCle.EstAdministrateur())
        { Message(Rouge, "Droits administrateur requis : lancez la station « en tant qu'administrateur »."); return; }

        Interactif(false);
        Message(Bleu, $"Chiffrement de {cible.Lettre} en cours… (ne retirez pas la clé)");

        var res = await Task.Run(() => BitLockerCle.Chiffrer(cible.Lettre, _mdp1.Text));
        if (!res.Ok || res.CleRecuperation is null)
        {
            Message(Rouge, "Échec : " + res.Message);
            Interactif(true);
            return;
        }

        // Escrow de la clé de récupération vers la passerelle (best-effort : n'annule pas le succès).
        var volume = $"{cible.Nom} ({cible.Lettre})";
        string? escrow;
        try { escrow = await _api.EnvoyerCleRecuperationAsync(volume, res.CleRecuperation, CancellationToken.None); }
        catch (Exception ex) { escrow = ex.Message; }

        _etat.ForeColor = Vert;
        _etat.Text =
            "✅ Clé chiffrée. Elle se déverrouille par MOT DE PASSE sur tout PC Windows.\r\n\r\n"
          + "CLÉ DE RÉCUPÉRATION (à conserver précieusement) :\r\n" + res.CleRecuperation + "\r\n\r\n"
          + (escrow is null
                ? "→ Enregistrée aussi dans la console Bastion (Antivirus → Stations)."
                : "⚠️  Non enregistrée dans la console (" + escrow + ") — NOTEZ-la impérativement.");
        _chiffrer.Enabled = false;   // une clé déjà chiffrée : on ne recommence pas
        _mdp1.Enabled = _mdp2.Enabled = _lecteurs.Enabled = _confirme.Enabled = false;
    }

    private void Interactif(bool actif)
    {
        _chiffrer.Enabled = _actualiser.Enabled = _lecteurs.Enabled = _confirme.Enabled = _mdp1.Enabled = _mdp2.Enabled = actif;
    }

    private void Message(Color c, string t) { _etat.ForeColor = c; _etat.Text = t; }

    private static string Octets(long n)
    {
        string[] u = { "o", "Ko", "Mo", "Go", "To" }; double v = n; int i = 0;
        while (v >= 1024 && i < u.Length - 1) { v /= 1024; i++; }
        return $"{v:0.#} {u[i]}";
    }
}
