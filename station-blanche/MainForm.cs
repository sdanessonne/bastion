using System.Diagnostics;
using System.Text;

namespace Bastion.StationBlanche;

/// <summary>
/// Station blanche — écran unique, pensé pour une borne : lisible de loin, sans notice.
///
/// PRINCIPE : la station CONSTATE, elle ne modifie JAMAIS le support. Une clé peut être
/// une pièce remise par un tiers, voire un scellé : en effacer un fichier détruirait une
/// preuve. La décision d'effacer appartient à l'agent.
/// </summary>
public sealed class MainForm : Form
{
    private readonly Config _cfg;
    private BastionApi _api;   // refaite quand le jeton change dans les réglages

    private readonly Label _titre = new(), _etat = new(), _detail = new(), _moteur = new(), _trace = new();
    private readonly Panel _bandeau = new();
    private readonly PictureBox _logo = new();
    private readonly ProgressBar _barre = new();
    private readonly ListBox _menaces = new();
    private readonly Button _btnAnalyser = new(), _btnRapport = new(), _btnEteindre = new(), _btnMaj = new();
    private readonly ComboBox _supports = new();
    private readonly System.Windows.Forms.Timer _majPeriodique = new();

    private readonly List<IMoteur> _moteurs = new();
    private UsbWatcher? _veille;
    private CancellationTokenSource? _annule;
    private Verdict? _dernier;
    private Support? _cible;
    private bool _analyseEnCours;

    private static readonly Color Fond = Color.FromArgb(11, 17, 32);
    private static readonly Color Encre = Color.FromArgb(226, 232, 240);
    private static readonly Color Grise = Color.FromArgb(148, 163, 184);
    private static readonly Color Vert = Color.FromArgb(74, 222, 128);
    private static readonly Color Rouge = Color.FromArgb(248, 113, 113);
    private static readonly Color Ambre = Color.FromArgb(234, 179, 8);
    private static readonly Color Bleu = Color.FromArgb(56, 189, 248);

    public MainForm(Config cfg)
    {
        _cfg = cfg;
        _api = new BastionApi(cfg);

        // ClamAV d'abord : c'est le moteur de Bastion, celui dont on maîtrise la base.
        // Defender ensuite, en second avis — sauf s'il a été écarté par configuration.
        _moteurs.Add(new MoteurClamav(_api));
        if (cfg.DefenderEnSecondAvis) _moteurs.Add(new MoteurDefender());

        Text = "Bastion — Station blanche";
        // Icône de la fenêtre (barre de titre, Alt+Tab). Un logo manquant ne doit pas
        // empêcher une station d'analyser une clé : on s'en passe plutôt que d'échouer.
        try { Icon = Marque.Icone(32); } catch { }
        BackColor = Fond; ForeColor = Encre;
        Font = new Font("Segoe UI", 11F);
        KeyPreview = true;   // pour intercepter la sortie de secours avant les contrôles

        if (_cfg.Kiosque)
        {
            // MODE BORNE : plein écran sans bordure, au-dessus de tout. Il n'y a ni croix
            // de fermeture ni barre de titre — l'agent ne peut pas « sortir » par erreur.
            // Ce n'est PAS le mode kiosque de Windows : pour verrouiller vraiment le poste
            // (pas de Ctrl+Alt+Suppr, pas d'explorateur), il faut Assigned Access ou Shell
            // Launcher, configurés côté Windows. Voir le README.
            FormBorderStyle = FormBorderStyle.None;
            WindowState = FormWindowState.Maximized;
            TopMost = true;
            ShowInTaskbar = false;
        }
        else
        {
            MinimumSize = new Size(900, 660);
            Size = new Size(1000, 720);
            StartPosition = FormStartPosition.CenterScreen;
        }

        Construire();

        // Sortie de secours pour l'exploitant : sans elle, une borne en plein écran ne se
        // referme plus qu'en éteignant le poste. Volontairement peu découvrable.
        KeyDown += (_, e) =>
        {
            // Le drapeau est INDISPENSABLE : sans lui, le FormClosing ci-dessous annule
            // cette fermeture comme il annule Alt+F4, et la borne devient inquittable.
            if (e.Control && e.Shift && e.KeyCode == Keys.Q) { _sortieDemandee = true; _annule?.Cancel(); Close(); }
            // Réglages : même logique que la sortie de secours. Une borne n'expose pas de
            // bouton « configurer » à l'agent qui vient analyser sa clé.
            if (e.Control && e.Shift && e.KeyCode == Keys.R) OuvrirReglages();
        };
        // Alt+F4 ne doit pas fermer une borne par mégarde ; la sortie de secours reste.
        FormClosing += (_, e) =>
        {
            if (_cfg.Kiosque && e.CloseReason == CloseReason.UserClosing && !_sortieDemandee) { e.Cancel = true; return; }
            _annule?.Cancel(); _veille?.Dispose(); _majPeriodique.Dispose();
            // Sans cela, ~960 Mo restent retenus par le démon après la fermeture.
            MoteurClamav.ArreterDaemon();
        };

        Load += async (_, _) => await DemarrerAsync();
    }

    private bool _sortieDemandee;

    private void Construire()
    {
        // Le logo dit à l'agent, sans un mot, que ce poste appartient au dispositif du
        // service — et pas à un utilitaire quelconque installé là par hasard. Sous garde :
        // une station qui refuserait de démarrer à cause d'une image serait absurde.
        try { _logo.Image = Marque.Logo(52); } catch { }
        _logo.SizeMode = PictureBoxSizeMode.AutoSize;
        _logo.SetBounds(36, 26, 52, 52);

        _titre.Text = "Station blanche";
        _titre.Font = new Font("Segoe UI", 26F, FontStyle.Bold);
        _titre.ForeColor = Color.White;
        _titre.SetBounds(102, 26, 600, 46);

        var sous = new Label
        {
            Text = "Analyse d'une clé USB avant tout usage sur le réseau du service.",
            ForeColor = Grise, AutoSize = false,
        };
        sous.SetBounds(104, 74, 800, 24);

        _moteur.SetBounds(38, 102, 1100, 22);
        _moteur.ForeColor = Grise;
        _moteur.Font = new Font("Segoe UI", 9F);

        _bandeau.SetBounds(36, 136, 1, 150);
        _bandeau.BackColor = Color.FromArgb(21, 30, 51);

        _etat.Text = "Insérez une clé USB";
        _etat.Font = new Font("Segoe UI", 24F, FontStyle.Bold);
        _etat.ForeColor = Grise;
        _etat.SetBounds(26, 24, 1000, 46);
        _bandeau.Controls.Add(_etat);

        _detail.Text = "L'analyse démarre automatiquement.";
        _detail.ForeColor = Grise;
        _detail.SetBounds(28, 74, 1000, 60);
        _bandeau.Controls.Add(_detail);

        _barre.SetBounds(36, 298, 1, 8);
        _barre.Style = ProgressBarStyle.Marquee;   // MpCmdRun ne rend aucune progression :
        _barre.MarqueeAnimationSpeed = 30;         // une barre défilante dit « ça travaille »
        _barre.Visible = false;                    // sans prétendre connaître l'avancement.

        var lblM = new Label { Text = "Menaces détectées", ForeColor = Grise, AutoSize = true };
        lblM.SetBounds(38, 322, 200, 20);

        _menaces.SetBounds(36, 346, 1, 1);
        _menaces.BackColor = Color.FromArgb(15, 23, 42);
        _menaces.ForeColor = Encre;
        _menaces.BorderStyle = BorderStyle.FixedSingle;
        _menaces.Font = new Font("Consolas", 10F);
        _menaces.IntegralHeight = false;

        _trace.ForeColor = Grise;
        _trace.Font = new Font("Segoe UI", 8.5F);
        _trace.AutoSize = false;

        _supports.DropDownStyle = ComboBoxStyle.DropDownList;
        _supports.FlatStyle = FlatStyle.Flat;
        _supports.BackColor = Color.FromArgb(15, 23, 42);
        _supports.ForeColor = Encre;
        _supports.Font = new Font("Segoe UI", 10F);
        // Une liste « DropDownList » ignore BackColor : Windows la peint en blanc, ce qui
        // faisait une tache éclatante au milieu d'un écran sombre. Seul le dessin manuel
        // permet de la tenir dans la teinte du reste.
        _supports.DrawMode = DrawMode.OwnerDrawFixed;
        _supports.ItemHeight = 22;
        _supports.DrawItem += (_, e) =>
        {
            e.DrawBackground();
            var choisi = (e.State & DrawItemState.Selected) != 0;
            using var fond = new SolidBrush(choisi ? Color.FromArgb(30, 41, 66) : Color.FromArgb(15, 23, 42));
            e.Graphics.FillRectangle(fond, e.Bounds);
            if (e.Index >= 0 && _supports.Items[e.Index] is Support s)
                TextRenderer.DrawText(e.Graphics, s.Libelle, e.Font ?? Font,
                    new Point(e.Bounds.Left + 6, e.Bounds.Top + 3), Encre);
        };

        Bouton(_btnAnalyser, "Analyser", Bleu);
        _btnAnalyser.Click += async (_, _) => await LancerAsync();
        Bouton(_btnRapport, "Rapport…", Color.FromArgb(51, 65, 85));
        _btnRapport.Click += (_, _) => Exporter();
        _btnRapport.Enabled = false;
        Bouton(_btnMaj, "Mettre à jour", Color.FromArgb(51, 65, 85));
        _btnMaj.Click += async (_, _) => await MajAsync(manuel: true);
        Bouton(_btnEteindre, "⏻  Éteindre", Color.FromArgb(90, 32, 38));
        _btnEteindre.Click += (_, _) => Eteindre();
        _btnEteindre.Visible = _cfg.BoutonEteindre;

        Controls.AddRange(new Control[] { _logo, _titre, sous, _moteur, _bandeau, _barre, lblM, _menaces,
                                          _trace, _supports, _btnAnalyser, _btnRapport, _btnMaj, _btnEteindre });
        Resize += (_, _) => Disposer();
        Shown += (_, _) => Disposer();
    }

    /// <summary>
    /// Mise en page calculée : la borne peut tourner sur n'importe quelle définition.
    ///
    /// La barre du bas se pose DE DROITE À GAUCHE, et la liste des supports prend ce qui
    /// reste. Poser de gauche à droite faisait passer « Mettre à jour » sous « Éteindre »
    /// dès que la fenêtre descendait à 1000 px — soit la taille par défaut.
    /// </summary>
    private void Disposer()
    {
        int l = ClientSize.Width, h = ClientSize.Height, m = 36;
        // Le libellé des moteurs avait une largeur fixe : il se coupait sur une fenêtre
        // étroite, et c'est la ligne qui dit à l'agent si le verdict est digne de foi.
        _moteur.SetBounds(38, 102, l - 2 * m, 22);
        _bandeau.SetBounds(m, 136, l - 2 * m, 150);
        _etat.Width = _bandeau.Width - 52;
        _detail.Width = _bandeau.Width - 56;
        _barre.SetBounds(m, 298, l - 2 * m, 8);
        _menaces.SetBounds(m, 346, l - 2 * m, Math.Max(60, h - 346 - 96));
        _trace.SetBounds(m + 2, h - 88, l - 2 * m, 18);

        const int E = 150, MAJ = 150, RAP = 130, AN = 150, G = 14;
        int y = h - 63;
        int droite = l - m;
        if (_btnEteindre.Visible)
        {
            _btnEteindre.SetBounds(droite - E, y, E, 32);
            droite -= E + G * 2;   // respiration avant le bouton rouge : on n'éteint pas par erreur
        }
        _btnMaj.SetBounds(droite - MAJ, y, MAJ, 32); droite -= MAJ + G;
        _btnRapport.SetBounds(droite - RAP, y, RAP, 32); droite -= RAP + G;
        _btnAnalyser.SetBounds(droite - AN, y, AN, 32); droite -= AN + G;
        _supports.SetBounds(m, h - 62, Math.Max(120, droite - m), 30);
    }

    private void Bouton(Button b, string t, Color c)
    {
        b.Text = t;
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0;
        b.BackColor = c; b.ForeColor = c == Bleu ? Color.FromArgb(11, 17, 32) : Encre;
        b.Font = new Font("Segoe UI", 10F, FontStyle.Bold);
        b.Cursor = Cursors.Hand;
        // Pas d'ancrage : Disposer() pose ces boutons au pixel à chaque redimensionnement.
        // Les deux mécanismes ensemble se contrarient.
    }

    private async Task DemarrerAsync()
    {
        // Au plus tôt : le démon met ~35 s à charger sa base. Lancé maintenant, il sera
        // prêt bien avant qu'un agent n'arrive avec une clé. En attendant, les analyses
        // retombent sur clamscan — lentes, mais justes.
        MoteurClamav.DemarrerDaemon();
        AfficherMoteur();

        // Premier lancement : rien n'est configuré. On ouvre les réglages tout de suite —
        // c'est le seul moment où quelqu'un est devant l'écran pour les remplir. Attendre
        // qu'il découvre Ctrl+Shift+R garantirait une station qui ne trace rien et dont la
        // base ne se met jamais à jour, sans que personne ne s'en aperçoive.
        if (!_cfg.RemonteeActive) OuvrirReglages();
        _veille = new UsbWatcher(this);
        _veille.Insere += s => BeginInvoke(() => SurInsertion(s));
        _veille.Retire += l => BeginInvoke(() => SurRetrait(l));
        Rafraichir();

        if (_cfg.MajAuto)
        {
            await MajAsync(manuel: false);
            // Toutes les 4 h : une borne reste allumée des jours. Microsoft publie
            // plusieurs jeux de signatures par jour ; attendre le redémarrage du poste
            // laisserait la station travailler avec des signatures d'il y a une semaine.
            _majPeriodique.Interval = 4 * 60 * 60 * 1000;
            _majPeriodique.Tick += async (_, _) => await MajAsync(manuel: false);
            _majPeriodique.Start();
        }
    }

    /// <summary>
    /// Bandeau d'état des moteurs. Il tient en une ligne : c'est la seule chose que l'agent
    /// lit avant de faire confiance au verdict.
    /// </summary>
    private void AfficherMoteur()
    {
        var etats = _moteurs.Select(m => (moteur: m, etat: m.LireEtat())).ToList();
        var vivants = etats.Where(e => e.etat.Present).ToList();

        if (vivants.Count == 0)
        {
            _moteur.ForeColor = Rouge;
            _moteur.Text = "⛔ Aucun moteur d'analyse disponible : cette station ne peut rien analyser. "
                         + "Installez ClamAV (voir la notice).";
            _btnAnalyser.Enabled = false; _btnMaj.Enabled = false;
            return;
        }
        _btnMaj.Enabled = true;

        // Le PIRE des moteurs commande la couleur. Afficher en vert parce que l'un des deux
        // est à jour laisserait croire que le verdict vaut pour les deux — alors que le
        // second déclarerait « sain » ce qu'il ne sait plus reconnaître.
        var pire = vivants.Max(e => e.etat.AgeJours);
        var detail = string.Join(" · ", vivants.Select(e =>
            $"{e.moteur.Nom} {e.etat.Version}".Trim() +
            (e.etat.Signatures.HasValue ? $" ({e.etat.Signatures:dd/MM/yyyy})" : " (base absente)")));

        var absents = etats.Where(e => !e.etat.Present).Select(e => e.moteur.Nom).ToList();
        var manque = absents.Count > 0 ? " · " + string.Join(" et ", absents) + " absent" : "";

        if (pire > 7)
        {
            _moteur.ForeColor = Rouge;
            _moteur.Text = $"⛔ Signatures vieilles de {pire} jours — un « aucune menace » ne veut plus "
                         + $"rien dire. Mettez à jour. {detail}";
        }
        else if (pire > 2)
        {
            _moteur.ForeColor = Ambre;
            _moteur.Text = $"⚠️ Signatures vieilles de {pire} jours — mise à jour conseillée. {detail}";
        }
        else
        {
            _moteur.ForeColor = Grise;
            _moteur.Text = detail + manque
                         + (_cfg.RemonteeActive ? $" · analyses tracées sur {_cfg.Passerelle}" : " · analyses NON tracées");
        }
    }

    /// <summary>
    /// Met à jour les signatures de chaque moteur. ClamAV les tire de la passerelle,
    /// Defender de Windows Update : deux chaînes indépendantes, donc deux verdicts.
    /// </summary>
    /// <summary>
    /// Ouvre les réglages et prend en compte ce qui en sort, sans redémarrer la station.
    /// </summary>
    private void OuvrirReglages()
    {
        if (_analyseEnCours) return;   // pas au milieu d'une analyse

        // TopMost passerait DEVANT la boîte de dialogue en mode borne : l'exploitant
        // verrait son écran de réglages disparaître derrière la station, sans comprendre.
        var etaitDevant = TopMost;
        TopMost = false;
        try
        {
            using var f = new ReglagesForm(_cfg);
            if (f.ShowDialog(this) != DialogResult.OK) return;
        }
        finally { TopMost = etaitDevant; }

        // Le jeton a pu changer : l'API le tient à la construction, il faut la refaire.
        _api = new BastionApi(_cfg);
        _moteurs.Clear();
        _moteurs.Add(new MoteurClamav(_api));
        if (_cfg.DefenderEnSecondAvis) _moteurs.Add(new MoteurDefender());

        _btnEteindre.Visible = _cfg.BoutonEteindre;
        Disposer();
        AfficherMoteur();
        Rafraichir();
        // Le mode borne ne bascule qu'au prochain lancement : passer une fenêtre en plein
        // écran sans bordure à chaud produit des artefacts, et l'exploitant vient
        // justement de configurer le poste — il le redémarrera.
        _trace.ForeColor = Grise;
        _trace.Text = "✓ Réglages enregistrés." + (_cfg.Kiosque != etaitDevant
            ? " Le mode borne prendra effet au prochain démarrage." : "");
    }

    private async Task MajAsync(bool manuel)
    {
        if (_analyseEnCours) return;   // jamais pendant une analyse : la base changerait sous les pieds du moteur
        _btnMaj.Enabled = false;
        _moteur.ForeColor = Grise;
        _moteur.Text = "Mise à jour des signatures en cours…";

        var comptes = new List<string>();
        var echecs = new List<string>();
        foreach (var m in _moteurs.Where(m => m.LireEtat().Present))
        {
            var (ok, msg) = await m.MettreAJourAsync(CancellationToken.None);
            (ok ? comptes : echecs).Add($"{m.Nom} : {msg}");
        }

        AfficherMoteur();
        // Un échec se dit TOUJOURS, même en mise à jour automatique : c'est précisément
        // celui que personne ne regarde qui laisse une base pourrir pendant des semaines.
        if (echecs.Count > 0)
        {
            _trace.ForeColor = Ambre;
            _trace.Text = "⚠️ " + string.Join("  ·  ", echecs);
        }
        else if (manuel)
        {
            _trace.ForeColor = Grise;
            _trace.Text = "✓ " + string.Join("  ·  ", comptes);
        }
        _btnMaj.Enabled = true;
    }

    /// <summary>Alimente la liste sans clé USB, pour contrôler le rendu (mode --capture).</summary>
    internal void InjecterSupportFictif(Support s)
    {
        _supports.Items.Add(s); _supports.SelectedIndex = 0;
        _supports.Visible = true; _btnAnalyser.Enabled = true;
    }

    private void Rafraichir()
    {
        var avant = (_supports.SelectedItem as Support)?.Lettre;
        _supports.Items.Clear();
        foreach (var s in UsbWatcher.Lister()) _supports.Items.Add(s);
        _supports.DisplayMember = nameof(Support.Libelle);
        if (_supports.Items.Count > 0)
        {
            var i = _supports.Items.Cast<Support>().ToList().FindIndex(s => s.Lettre == avant);
            _supports.SelectedIndex = Math.Max(0, i);
        }
        // Sans clé, la liste n'a rien à proposer : on la retire au lieu de laisser une
        // case vide — que Windows peint en blanc, en tache au milieu de l'écran sombre.
        _supports.Visible = _supports.Items.Count > 0;
        _btnAnalyser.Enabled = _supports.Items.Count > 0 && Defender.TrouverExe() != null;
    }

    private async void SurInsertion(Support s)
    {
        Rafraichir();
        var i = _supports.Items.Cast<Support>().ToList().FindIndex(x => x.Lettre == s.Lettre);
        if (i >= 0) _supports.SelectedIndex = i;
        await LancerAsync();   // on insère, ça analyse. Rien à cliquer.
    }

    private void SurRetrait(string lettre)
    {
        // Retirer la clé pendant l'analyse : on ANNULE. Sans cela, Defender rendrait
        // « aucune menace » sur un support absent — un feu vert sur rien.
        if (_cible?.Lettre == lettre) _annule?.Cancel();
        Rafraichir();
        if (_supports.Items.Count == 0)
            Afficher("Insérez une clé USB", "L'analyse démarre automatiquement.", Grise, Color.FromArgb(21, 30, 51));
    }

    private async Task LancerAsync()
    {
        if (_supports.SelectedItem is not Support s) return;
        _cible = s;
        _annule?.Cancel();
        _annule = new CancellationTokenSource();

        _menaces.Items.Clear();
        _trace.Text = "";
        _analyseEnCours = true;
        _btnAnalyser.Enabled = false; _btnRapport.Enabled = false;
        _barre.Visible = true;
        Afficher("Analyse en cours…", $"{s.Libelle}\nMerci de ne pas retirer le support.", Bleu, Color.FromArgb(21, 30, 51));

        // Les moteurs passent l'un APRÈS l'autre, pas en parallèle : chacun charge sa base
        // en mémoire (ClamAV en réserve plus d'un gigaoctet) et lit le même support. Les
        // lancer ensemble ferait ramer le poste et se disputer la clé, pour rien gagner.
        var analyses = new List<(IMoteur, Resultat)>();
        var utiles = _moteurs.Where(m => m.LireEtat().Present).ToList();
        foreach (var m in utiles)
        {
            Afficher("Analyse en cours…",
                $"{s.Libelle}\n{m.Nom} ({utiles.IndexOf(m) + 1}/{utiles.Count}) — merci de ne pas retirer le support.",
                Bleu, Color.FromArgb(21, 30, 51));
            analyses.Add((m, await m.AnalyserAsync(s.Lettre + "\\", _annule.Token)));
            if (_annule.IsCancellationRequested) break;
        }

        var r = new Verdict(analyses);
        _dernier = r;
        _analyseEnCours = false;
        _barre.Visible = false;
        _btnAnalyser.Enabled = true; _btnRapport.Enabled = r.Analyses.Count > 0;

        // L'ordre compte : une menace TROUVÉE prime sur une analyse incomplète. Si ClamAV
        // trouve un cheval de Troie et que Defender s'interrompt, le support est infecté —
        // point. Traiter cela comme « analyse impossible » enterrerait la trouvaille.
        if (r.NbMenaces > 0)
        {
            Afficher("SUPPORT INFECTÉ — " + r.NbMenaces + " menace(s)",
                "N'utilisez pas ce support. Prévenez le service informatique.\nLe support n'a PAS été modifié.",
                Rouge, Color.FromArgb(58, 18, 22));
            foreach (var m in r.Menaces) _menaces.Items.Add($"{m.Nom}\n    {m.Fichier}");
            foreach (var e in r.Ecueils) _menaces.Items.Add("⚠️ " + e);
        }
        else if (!r.Abouti)
        {
            Afficher("ANALYSE IMPOSSIBLE", string.Join("\n", r.Ecueils.DefaultIfEmpty("Erreur inconnue.")),
                Ambre, Color.FromArgb(48, 38, 8));
            _menaces.Items.Add("L'analyse n'a pas abouti : ne considérez PAS ce support comme sain.");
            foreach (var e in r.Ecueils) _menaces.Items.Add("  " + e);
        }
        else
        {
            var quels = string.Join(" et ", r.Analyses.Select(a => a.moteur.Nom));
            Afficher("AUCUNE MENACE DÉTECTÉE",
                $"Analysé par {quels} en {r.Duree.TotalSeconds:F1} s. Aucune détection ne garantit toutefois l'absence de tout code inconnu.",
                Vert, Color.FromArgb(12, 42, 26));
            _menaces.Items.Add("Aucune menace connue trouvée sur ce support.");
        }

        // Remontée APRÈS l'affichage : le verdict de l'agent ne doit jamais attendre le
        // réseau. Un échec de remontée est signalé, mais ne change pas le verdict.
        int nb = 0;
        try { nb = Directory.EnumerateFiles(s.Lettre + "\\", "*", SearchOption.AllDirectories).Count(); } catch { }
        var err = await _api.RemonterAsync(s, r, nb, CancellationToken.None);
        _trace.Text = err == null
            ? $"✓ Analyse tracée sur la console Bastion ({DateTime.Now:HH:mm:ss})."
            : "⚠️ " + err;
        _trace.ForeColor = err == null ? Grise : Ambre;
    }

    private void Afficher(string t, string d, Color c, Color fond)
    {
        _etat.Text = t; _etat.ForeColor = c;
        _detail.Text = d;
        _bandeau.BackColor = fond;
    }

    private void Eteindre()
    {
        var r = MessageBox.Show(this,
            "Éteindre cet ordinateur ?\n\nRetirez votre clé USB avant de confirmer.",
            "Éteindre la station", MessageBoxButtons.YesNo, MessageBoxIcon.Question, MessageBoxDefaultButton.Button2);
        if (r != DialogResult.Yes) return;
        try
        {
            // « /t 0 » : sans délai. « /f » ferme les applications récalcitrantes — sur une
            // borne, il n'y a rien à sauvegarder, et un arrêt qui ne se produit pas laisse
            // le poste allumé toute la nuit avec la session ouverte.
            Process.Start(new ProcessStartInfo("shutdown.exe", "/s /f /t 0") { CreateNoWindow = true, UseShellExecute = false });
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, "Extinction impossible : " + ex.Message, "Erreur",
                MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
    }

    /// <summary>Rapport texte : une analyse doit pouvoir être jointe à un compte rendu.</summary>
    private void Exporter()
    {
        if (_dernier == null || _cible == null) return;
        var sb = new StringBuilder();
        sb.AppendLine("RAPPORT D'ANALYSE — Bastion Station blanche");
        sb.AppendLine(new string('=', 62));
        sb.AppendLine($"Date            : {DateTime.Now:dd/MM/yyyy HH:mm:ss}");
        sb.AppendLine($"Poste           : {Environment.MachineName}");
        sb.AppendLine($"Opérateur       : {Environment.UserName}");
        sb.AppendLine($"Support         : {_cible.Libelle}");
        sb.AppendLine($"Durée           : {_dernier.Duree.TotalSeconds:F1} s");
        sb.AppendLine($"Résultat        : {(_dernier.NbMenaces > 0 ? $"INFECTÉ — {_dernier.NbMenaces} menace(s)" : !_dernier.Abouti ? "ANALYSE NON ABOUTIE" : "aucune menace détectée")}");
        sb.AppendLine();
        // Le détail moteur par moteur : c'est ce qui permet, des mois plus tard, de savoir
        // ce qui a réellement été passé sur ce support et avec quelles signatures.
        sb.AppendLine("MOTEURS");
        foreach (var (moteur, res) in _dernier.Analyses)
        {
            var e = moteur.LireEtat();
            sb.AppendLine($"  {moteur.Nom} {e.Version}".TrimEnd());
            sb.AppendLine($"      signatures : {(e.Signatures.HasValue ? $"{e.Signatures:dd/MM/yyyy HH:mm} ({e.AgeJours} jour(s))" : "inconnues")}");
            sb.AppendLine($"      verdict    : {(res.Abouti ? (res.NbMenaces > 0 ? $"{res.NbMenaces} menace(s)" : "rien trouvé") : "NON ABOUTI")}");
            if (res.Erreur != null) sb.AppendLine($"      observation: {res.Erreur}");
        }
        sb.AppendLine();
        if (_dernier.Menaces.Count > 0)
        {
            sb.AppendLine("MENACES");
            foreach (var x in _dernier.Menaces) sb.AppendLine($"  {x.Nom}\n      {x.Fichier}");
            sb.AppendLine();
        }
        sb.AppendLine("Le support n'a pas été modifié par cette analyse.");
        sb.AppendLine("« Aucune menace » signifie « rien de connu par les moteurs ci-dessus, avec les");
        sb.AppendLine("signatures ci-dessus », et non « inoffensif ».");
        sb.AppendLine();
        sb.AppendLine(new string('-', 62));
        sb.AppendLine("Journal des moteurs :");
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
