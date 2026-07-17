using System.Text;

namespace Bastion.StationBlanche;

/// <summary>
/// Station blanche — écran unique, pensé pour être lu de loin et sans formation.
///
/// PRINCIPE : la station CONSTATE, elle ne modifie JAMAIS le support. Une clé peut être
/// une pièce remise par un tiers, voire un scellé : en effacer un fichier détruirait une
/// preuve. La décision d'effacer appartient à l'agent.
/// </summary>
public sealed class MainForm : Form
{
    private readonly Label _titre = new();
    private readonly Label _etat = new();
    private readonly Label _detail = new();
    private readonly Panel _bandeau = new();
    private readonly ProgressBar _barre = new();
    private readonly ListBox _menaces = new();
    private readonly Button _btnAnalyser = new();
    private readonly Button _btnRapport = new();
    private readonly ComboBox _supports = new();
    private readonly Label _moteur = new();

    private UsbWatcher? _veille;
    private CancellationTokenSource? _annule;
    private Resultat? _dernier;
    private Support? _cible;

    private static readonly Color Fond = Color.FromArgb(11, 17, 32);
    private static readonly Color Encre = Color.FromArgb(226, 232, 240);
    private static readonly Color Grise = Color.FromArgb(148, 163, 184);
    private static readonly Color Vert = Color.FromArgb(74, 222, 128);
    private static readonly Color Rouge = Color.FromArgb(248, 113, 113);
    private static readonly Color Ambre = Color.FromArgb(234, 179, 8);
    private static readonly Color Bleu = Color.FromArgb(56, 189, 248);

    public MainForm()
    {
        Text = "Bastion — Station blanche";
        BackColor = Fond;
        ForeColor = Encre;
        Font = new Font("Segoe UI", 10F);
        MinimumSize = new Size(860, 620);
        Size = new Size(940, 680);
        StartPosition = FormStartPosition.CenterScreen;

        _titre.Text = "Station blanche";
        _titre.Font = new Font("Segoe UI", 22F, FontStyle.Bold);
        _titre.ForeColor = Color.White;
        _titre.SetBounds(28, 20, 500, 40);

        var sous = new Label
        {
            Text = "Analyse d'un support amovible avant tout usage sur le réseau du service.",
            ForeColor = Grise, AutoSize = false,
        };
        sous.SetBounds(30, 62, 700, 22);

        _moteur.SetBounds(30, 88, 860, 20);
        _moteur.ForeColor = Grise;
        _moteur.Font = new Font("Segoe UI", 8.5F);

        // Bandeau de verdict : c'est CE QU'ON REGARDE. Il doit se lire à trois mètres.
        _bandeau.SetBounds(28, 120, 872, 130);
        _bandeau.BackColor = Color.FromArgb(21, 30, 51);
        _bandeau.Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right;

        _etat.Text = "Insérez une clé USB";
        _etat.Font = new Font("Segoe UI", 20F, FontStyle.Bold);
        _etat.ForeColor = Grise;
        _etat.SetBounds(24, 22, 820, 40);
        _bandeau.Controls.Add(_etat);

        _detail.Text = "L'analyse démarre automatiquement.";
        _detail.ForeColor = Grise;
        _detail.SetBounds(26, 66, 820, 46);
        _bandeau.Controls.Add(_detail);

        _barre.SetBounds(28, 262, 872, 8);
        _barre.Style = ProgressBarStyle.Marquee;   // MpCmdRun ne rend aucune progression :
        _barre.MarqueeAnimationSpeed = 30;         // une barre défilante dit « ça travaille »
        _barre.Visible = false;                    // sans prétendre connaître l'avancement.
        _barre.Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right;

        var lblM = new Label { Text = "Menaces détectées", ForeColor = Grise, AutoSize = true };
        lblM.SetBounds(30, 286, 200, 20);

        _menaces.SetBounds(28, 310, 872, 240);
        _menaces.BackColor = Color.FromArgb(15, 23, 42);
        _menaces.ForeColor = Encre;
        _menaces.BorderStyle = BorderStyle.FixedSingle;
        _menaces.Font = new Font("Consolas", 9.5F);
        _menaces.Anchor = AnchorStyles.Top | AnchorStyles.Bottom | AnchorStyles.Left | AnchorStyles.Right;
        _menaces.IntegralHeight = false;

        var lblS = new Label { Text = "Support", ForeColor = Grise, AutoSize = true };
        lblS.SetBounds(30, 566, 60, 20);
        _supports.SetBounds(92, 562, 330, 26);
        _supports.DropDownStyle = ComboBoxStyle.DropDownList;
        _supports.FlatStyle = FlatStyle.Flat;
        _supports.BackColor = Color.FromArgb(15, 23, 42);
        _supports.ForeColor = Encre;
        _supports.Anchor = AnchorStyles.Bottom | AnchorStyles.Left;

        Bouton(_btnAnalyser, "Analyser", 440, 560, 130, Bleu);
        _btnAnalyser.Click += async (_, _) => await LancerAsync();
        Bouton(_btnRapport, "Rapport…", 582, 560, 130, Color.FromArgb(51, 65, 85));
        _btnRapport.Click += (_, _) => Exporter();
        _btnRapport.Enabled = false;

        var pied = new Label
        {
            Text = "La station analyse et signale. Elle ne modifie jamais le support.",
            ForeColor = Grise, Font = new Font("Segoe UI", 8.5F), AutoSize = true,
        };
        pied.SetBounds(30, 596, 600, 18);
        pied.Anchor = AnchorStyles.Bottom | AnchorStyles.Left;

        Controls.AddRange(new Control[] { _titre, sous, _moteur, _bandeau, _barre, lblM, _menaces, lblS, _supports, _btnAnalyser, _btnRapport, pied });

        Load += (_, _) => Demarrer();
        FormClosing += (_, _) => { _annule?.Cancel(); _veille?.Dispose(); };
    }

    private void Bouton(Button b, string t, int x, int y, int w, Color c)
    {
        b.Text = t; b.SetBounds(x, y, w, 30);
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0;
        b.BackColor = c; b.ForeColor = c == Bleu ? Color.FromArgb(11, 17, 32) : Encre;
        b.Font = new Font("Segoe UI", 9.5F, FontStyle.Bold);
        b.Cursor = Cursors.Hand;
        b.Anchor = AnchorStyles.Bottom | AnchorStyles.Left;
    }

    private void Demarrer()
    {
        var m = Defender.LireEtat();
        if (!m.Present)
        {
            _moteur.ForeColor = Rouge;
            _moteur.Text = "⛔ Windows Defender est introuvable : cette station ne peut rien analyser.";
            _btnAnalyser.Enabled = false;
            return;
        }
        // Des signatures périmées donnent une FAUSSE ASSURANCE : la station déclare
        // « sain » ce qu'elle ne sait plus reconnaître. Le dire franchement.
        if (m.AgeJours > 7)
        {
            _moteur.ForeColor = Rouge;
            _moteur.Text = $"⛔ Signatures antivirus vieilles de {m.AgeJours} jours " +
                           $"({m.Signatures:dd/MM/yyyy}) — un « aucune menace » ne veut plus rien dire. Mettez à jour Windows Defender.";
        }
        else if (m.AgeJours > 2)
        {
            _moteur.ForeColor = Ambre;
            _moteur.Text = $"⚠️ Signatures du {m.Signatures:dd/MM/yyyy} ({m.AgeJours} j) — une mise à jour est conseillée.";
        }
        else
        {
            _moteur.ForeColor = Grise;
            _moteur.Text = $"Moteur Windows Defender {m.Version} · signatures du {m.Signatures:dd/MM/yyyy à HH:mm}";
        }

        _veille = new UsbWatcher(this);
        _veille.Insere += s => BeginInvoke(() => SurInsertion(s));
        _veille.Retire += l => BeginInvoke(() => SurRetrait(l));
        Rafraichir();
    }

    private void Rafraichir()
    {
        var avant = (_supports.SelectedItem as Support)?.Lettre;
        _supports.Items.Clear();
        foreach (var s in UsbWatcher.Lister()) _supports.Items.Add(s);
        _supports.DisplayMember = nameof(Support.Libelle);
        if (_supports.Items.Count > 0)
            _supports.SelectedIndex = Math.Max(0, _supports.Items.Cast<Support>().ToList()
                .FindIndex(s => s.Lettre == avant));
        _btnAnalyser.Enabled = _supports.Items.Count > 0;
    }

    private async void SurInsertion(Support s)
    {
        Rafraichir();
        var i = _supports.Items.Cast<Support>().ToList().FindIndex(x => x.Lettre == s.Lettre);
        if (i >= 0) _supports.SelectedIndex = i;
        await LancerAsync();   // automatique : on insère, ça analyse. Rien à cliquer.
    }

    private void SurRetrait(string lettre)
    {
        // Retirer la clé pendant l'analyse : on ANNULE. Sans cela, Defender rendrait
        // « aucune menace » sur un support absent — un feu vert sur rien.
        if (_cible?.Lettre == lettre) _annule?.Cancel();
        Rafraichir();
        if (_supports.Items.Count == 0) Verdict("Insérez une clé USB", "L'analyse démarre automatiquement.", Grise, Color.FromArgb(21, 30, 51));
    }

    private async Task LancerAsync()
    {
        if (_supports.SelectedItem is not Support s) return;
        _cible = s;
        _annule?.Cancel();
        _annule = new CancellationTokenSource();

        _menaces.Items.Clear();
        _btnAnalyser.Enabled = false;
        _btnRapport.Enabled = false;
        _barre.Visible = true;
        Verdict("Analyse en cours…", $"{s.Libelle} — merci de ne pas retirer le support.", Bleu, Color.FromArgb(21, 30, 51));

        var r = await Defender.AnalyserAsync(s.Lettre + "\\", _annule.Token);
        _dernier = r;
        _barre.Visible = false;
        _btnAnalyser.Enabled = true;
        _btnRapport.Enabled = true;

        if (!r.Abouti)
        {
            Verdict("ANALYSE IMPOSSIBLE", r.Erreur ?? "Erreur inconnue.", Ambre, Color.FromArgb(48, 38, 8));
            _menaces.Items.Add("L'analyse n'a pas abouti : ne considérez PAS ce support comme sain.");
            if (r.Erreur != null) _menaces.Items.Add("  " + r.Erreur);
            return;
        }
        if (r.NbMenaces > 0)
        {
            Verdict($"SUPPORT INFECTÉ — {r.NbMenaces} menace(s)",
                "N'utilisez pas ce support. Prévenez le service informatique. Le support n'a PAS été modifié.",
                Rouge, Color.FromArgb(58, 18, 22));
            foreach (var m in r.Menaces) _menaces.Items.Add($"{m.Nom}\n    {m.Fichier}");
            return;
        }
        // Un support vide n'est pas un support sain : Defender le signale via Erreur.
        if (r.Erreur != null)
        {
            Verdict("RIEN À ANALYSER", r.Erreur, Ambre, Color.FromArgb(48, 38, 8));
            return;
        }
        Verdict("AUCUNE MENACE DÉTECTÉE",
            $"Analysé en {r.Duree.TotalSeconds:F1} s. Aucune détection ne garantit toutefois l'absence de tout code inconnu.",
            Vert, Color.FromArgb(12, 42, 26));
        _menaces.Items.Add("Aucune menace connue trouvée sur ce support.");
    }

    private void Verdict(string t, string d, Color c, Color fond)
    {
        _etat.Text = t; _etat.ForeColor = c;
        _detail.Text = d;
        _bandeau.BackColor = fond;
    }

    /// <summary>Rapport texte : une analyse doit pouvoir être jointe à un compte rendu.</summary>
    private void Exporter()
    {
        if (_dernier == null || _cible == null) return;
        var m = Defender.LireEtat();
        var sb = new StringBuilder();
        sb.AppendLine("RAPPORT D'ANALYSE — Bastion Station blanche");
        sb.AppendLine(new string('=', 60));
        sb.AppendLine($"Date            : {DateTime.Now:dd/MM/yyyy HH:mm:ss}");
        sb.AppendLine($"Poste           : {Environment.MachineName}");
        sb.AppendLine($"Opérateur       : {Environment.UserName}");
        sb.AppendLine($"Support         : {_cible.Libelle}");
        sb.AppendLine($"Moteur          : Windows Defender {m.Version}");
        sb.AppendLine($"Signatures      : {m.Signatures:dd/MM/yyyy HH:mm} ({m.AgeJours} jour(s))");
        sb.AppendLine($"Durée           : {_dernier.Duree.TotalSeconds:F1} s");
        sb.AppendLine($"Résultat        : {(!_dernier.Abouti ? "ANALYSE NON ABOUTIE" : _dernier.NbMenaces > 0 ? $"INFECTÉ — {_dernier.NbMenaces} menace(s)" : "aucune menace détectée")}");
        if (_dernier.Erreur != null) sb.AppendLine($"Observation     : {_dernier.Erreur}");
        sb.AppendLine();
        if (_dernier.Menaces.Count > 0)
        {
            sb.AppendLine("MENACES");
            foreach (var x in _dernier.Menaces) sb.AppendLine($"  {x.Nom}\n      {x.Fichier}");
            sb.AppendLine();
        }
        sb.AppendLine("Le support n'a pas été modifié par cette analyse.");
        sb.AppendLine();
        sb.AppendLine(new string('-', 60));
        sb.AppendLine("Journal du moteur :");
        sb.AppendLine(_dernier.Journal);

        using var d = new SaveFileDialog
        {
            FileName = $"analyse-{_cible.Lettre.TrimEnd(':')}-{DateTime.Now:yyyyMMdd-HHmmss}.txt",
            Filter = "Rapport texte|*.txt",
        };
        if (d.ShowDialog(this) == DialogResult.OK)
            File.WriteAllText(d.FileName, sb.ToString(), Encoding.UTF8);
    }
}
