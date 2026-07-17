using System.Diagnostics;

namespace Bastion.StationBlanche;

/// <summary>
/// Écran de réglages de la station.
///
/// POURQUOI IL EXISTE : sans lui, l'adresse de la passerelle et le jeton se saisissaient à
/// la main dans « station.json ». Sur une borne en plein écran, sans barre de titre, où
/// l'agent n'atteint ni l'explorateur ni le Bloc-notes, c'était intenable — l'exploitant
/// devait sortir du mode borne pour éditer un fichier JSON.
///
/// Il n'est PAS accessible à l'agent : ni bouton, ni menu. Ctrl+Shift+R, comme la sortie de
/// secours. Il s'ouvre en revanche de lui-même au premier lancement, tant que rien n'est
/// configuré : c'est le seul moment où quelqu'un le cherche.
/// </summary>
public sealed class ReglagesForm : Form
{
    private readonly Config _cfg;
    private readonly TextBox _passerelle = new(), _jeton = new();
    private readonly CheckBox _defender = new(), _eteindre = new(), _kiosque = new(), _majAuto = new();
    private readonly Label _verdict = new();
    private readonly Button _tester = new(), _ok = new(), _annuler = new();

    private static readonly Color Fond = Color.FromArgb(11, 17, 32);
    private static readonly Color Encre = Color.FromArgb(226, 232, 240);
    private static readonly Color Grise = Color.FromArgb(148, 163, 184);
    private static readonly Color Bleu = Color.FromArgb(56, 189, 248);

    public ReglagesForm(Config cfg)
    {
        _cfg = cfg;
        Text = "Bastion — Réglages de la station";
        BackColor = Fond; ForeColor = Encre;
        Font = new Font("Segoe UI", 10F);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false; MinimizeBox = false;
        StartPosition = FormStartPosition.CenterParent;
        ClientSize = new Size(620, 502);
        try { Icon = Marque.Icone(32); } catch { }
        // Cet écran a une croix et Échap : ce n'est pas la borne, c'est l'outil de
        // l'exploitant. L'y enfermer n'aurait aucun sens.
        KeyPreview = true;
        KeyDown += (_, e) => { if (e.KeyCode == Keys.Escape) { DialogResult = DialogResult.Cancel; Close(); } };

        Construire();
        Relire();
    }

    private void Construire()
    {
        var titre = new Label
        {
            Text = "Réglages de la station",
            Font = new Font("Segoe UI", 16F, FontStyle.Bold), ForeColor = Color.White,
            AutoSize = false,
        };
        titre.SetBounds(24, 20, 400, 30);

        var sous = new Label
        {
            Text = "Ces valeurs se lisent sur la console Bastion : Antivirus → Stations blanches.",
            ForeColor = Grise, AutoSize = false, Font = new Font("Segoe UI", 8.5F),
        };
        sous.SetBounds(26, 50, 570, 18);

        var l1 = Etiquette("Adresse de la console Bastion", 24, 84);
        _passerelle.SetBounds(24, 104, 572, 26);
        Champ(_passerelle);
        _passerelle.PlaceholderText = "https://192.168.182.1:8443";

        var l2 = Etiquette("Jeton des stations", 24, 142);
        _jeton.SetBounds(24, 162, 500, 26);
        Champ(_jeton);
        // Masqué par défaut : la borne est dans un couloir, et l'écran de réglages peut
        // rester ouvert le temps qu'un agent passe derrière l'exploitant.
        _jeton.UseSystemPasswordChar = true;
        _jeton.PlaceholderText = "64 caractères, copiés depuis la console";

        var voir = new Button { Text = "👁" };
        Bouton(voir, Color.FromArgb(51, 65, 85));
        voir.SetBounds(532, 162, 64, 26);
        voir.Click += (_, _) => _jeton.UseSystemPasswordChar = !_jeton.UseSystemPasswordChar;

        var aide = new Label
        {
            Text = "C'est le jeton des STATIONS, pas celui d'administration : il n'ouvre que le dépôt\n"
                 + "des résultats et la base virale. Sans lui, la station analyse quand même — mais\n"
                 + "ses analyses ne sont pas tracées et sa base ne se met plus à jour.",
            ForeColor = Grise, AutoSize = false, Font = new Font("Segoe UI", 8.5F),
        };
        aide.SetBounds(26, 194, 572, 50);

        _tester.Text = "Éprouver la connexion";
        Bouton(_tester, Bleu);
        _tester.SetBounds(24, 248, 180, 30);
        _tester.Click += async (_, _) => await TesterAsync();

        _verdict.SetBounds(214, 248, 382, 46);
        _verdict.ForeColor = Grise;
        _verdict.Font = new Font("Segoe UI", 8.5F);
        _verdict.AutoSize = false;

        var sep = new Label { BorderStyle = BorderStyle.Fixed3D, AutoSize = false };
        sep.SetBounds(24, 304, 572, 2);

        Case(_kiosque, "Mode borne : plein écran, sans bordure (Ctrl+Shift+Q pour quitter)", 24, 318);
        Case(_eteindre, "Afficher le bouton d'extinction", 24, 344);
        Case(_majAuto, "Mettre à jour les signatures au démarrage puis toutes les 4 h", 24, 370);
        Case(_defender, "Faire tourner Windows Defender en second avis, en plus de ClamAV", 24, 396);

        // Pleine largeur et AU-DESSUS des boutons : à 380 px l'étiquette chevauchait
        // « Annuler » et le chemin se coupait à « D:\Claude ». L'exploitant doit savoir où
        // part son fichier — c'est le seul endroit qui le lui dit.
        var ou = new Label
        {
            Text = "Enregistré dans : " + Config.CheminAffiche,
            ForeColor = Color.FromArgb(100, 116, 139), AutoSize = false, Font = new Font("Segoe UI", 7.5F),
        };
        ou.SetBounds(26, 424, 572, 30);

        _ok.Text = "Enregistrer";
        Bouton(_ok, Bleu);
        _ok.SetBounds(456, 460, 140, 30);
        _ok.Click += (_, _) => Enregistrer();

        _annuler.Text = "Annuler";
        Bouton(_annuler, Color.FromArgb(51, 65, 85));
        _annuler.SetBounds(360, 460, 88, 30);
        _annuler.Click += (_, _) => { DialogResult = DialogResult.Cancel; Close(); };

        Controls.AddRange(new Control[] { titre, sous, l1, _passerelle, l2, _jeton, voir, aide,
            _tester, _verdict, sep, _kiosque, _eteindre, _majAuto, _defender, ou, _ok, _annuler });
    }

    private Label Etiquette(string t, int x, int y)
    {
        var l = new Label { Text = t, ForeColor = Encre, AutoSize = true, Font = new Font("Segoe UI", 9F, FontStyle.Bold) };
        l.SetBounds(x, y, 300, 18);
        return l;
    }

    private void Champ(TextBox t)
    {
        t.BackColor = Color.FromArgb(15, 23, 42);
        t.ForeColor = Encre;
        t.BorderStyle = BorderStyle.FixedSingle;
        t.Font = new Font("Consolas", 10F);
    }

    private void Case(CheckBox c, string t, int x, int y)
    {
        c.Text = t; c.ForeColor = Encre; c.AutoSize = false;
        c.FlatStyle = FlatStyle.Flat;
        c.SetBounds(x, y, 572, 22);
        c.Font = new Font("Segoe UI", 9F);
    }

    private void Bouton(Button b, Color c)
    {
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0;
        b.BackColor = c; b.ForeColor = c == Bleu ? Color.FromArgb(11, 17, 32) : Encre;
        b.Font = new Font("Segoe UI", 9F, FontStyle.Bold);
        b.Cursor = Cursors.Hand;
    }

    private void Relire()
    {
        _passerelle.Text = _cfg.Passerelle;
        _jeton.Text = _cfg.Jeton;
        _kiosque.Checked = _cfg.Kiosque;
        _eteindre.Checked = _cfg.BoutonEteindre;
        _majAuto.Checked = _cfg.MajAuto;
        _defender.Checked = _cfg.DefenderEnSecondAvis;
    }

    /// <summary>Réglages tels que saisis à l'écran, sans les enregistrer.</summary>
    private Config Saisie() => new()
    {
        Passerelle = _passerelle.Text.Trim(),
        Jeton = _jeton.Text.Trim(),
        Kiosque = _kiosque.Checked,
        BoutonEteindre = _eteindre.Checked,
        MajAuto = _majAuto.Checked,
        DefenderEnSecondAvis = _defender.Checked,
        AccepterCertificatInterne = _cfg.AccepterCertificatInterne,
    };

    private async Task TesterAsync()
    {
        _tester.Enabled = false;
        _verdict.ForeColor = Grise;
        _verdict.Text = "Interrogation de la passerelle…";
        // On éprouve CE QUI EST À L'ÉCRAN, pas ce qui est enregistré : tout l'intérêt est
        // de valider une saisie avant de la garder.
        var (ok, msg) = await new BastionApi(Saisie()).TesterAsync(CancellationToken.None);
        _verdict.ForeColor = ok ? Color.FromArgb(74, 222, 128) : Color.FromArgb(248, 113, 113);
        _verdict.Text = (ok ? "✓ " : "⛔ ") + msg;
        _tester.Enabled = true;
    }

    private void Enregistrer()
    {
        var n = Saisie();
        // Une adresse sans schéma est l'erreur la plus probable : « 192.168.182.1:8443 »
        // ferait échouer HttpClient sur un message incompréhensible pour un exploitant.
        if (n.Passerelle != "" && !n.Passerelle.StartsWith("http", StringComparison.OrdinalIgnoreCase))
            n.Passerelle = "https://" + n.Passerelle;

        if (n.Jeton != "" && n.Jeton.Length < 32)
        {
            MessageBox.Show(this, "Ce jeton semble tronqué : il devrait faire 64 caractères.\n\n"
                + "Recopiez-le entièrement depuis la console (Antivirus → Stations blanches → 👁).",
                "Jeton douteux", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        _cfg.Passerelle = n.Passerelle;
        _cfg.Jeton = n.Jeton;
        _cfg.Kiosque = n.Kiosque;
        _cfg.BoutonEteindre = n.BoutonEteindre;
        _cfg.MajAuto = n.MajAuto;
        _cfg.DefenderEnSecondAvis = n.DefenderEnSecondAvis;

        var err = _cfg.Enregistrer();
        if (err != null)
        {
            MessageBox.Show(this, err, "Enregistrement impossible", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }
        DialogResult = DialogResult.OK;
        Close();
    }
}
