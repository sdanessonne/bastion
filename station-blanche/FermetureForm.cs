namespace Bastion.StationBlanche;

/// <summary>
/// Demande les identifiants d'un administrateur Bastion avant de fermer la station.
///
/// La station ne connaît AUCUN mot de passe : elle pose la question à la passerelle et
/// reçoit oui ou non. Un poste en libre accès dans un couloir ne doit rien porter qui
/// puisse être lu — et le jour où un compte est révoqué depuis la console, toutes les
/// stations le savent immédiatement, sans qu'on ait à les toucher.
/// </summary>
public sealed class FermetureForm : Form
{
    private readonly BastionApi _api;
    private readonly TextBox _user = new(), _pass = new(), _code = new();
    private readonly Label _lblCode = new(), _verdict = new();
    private readonly Button _ok = new(), _annuler = new();

    private static readonly Color Fond = Color.FromArgb(11, 17, 32);
    private static readonly Color Encre = Color.FromArgb(226, 232, 240);
    private static readonly Color Grise = Color.FromArgb(148, 163, 184);
    private static readonly Color Bleu = Color.FromArgb(56, 189, 248);

    public FermetureForm(BastionApi api)
    {
        _api = api;
        Text = "Bastion — Fermer la station";
        BackColor = Fond; ForeColor = Encre;
        Font = new Font("Segoe UI", 10F);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false; MinimizeBox = false;
        StartPosition = FormStartPosition.CenterParent;
        ClientSize = new Size(460, 340);
        try { Icon = Marque.Icone(32); } catch { }
        KeyPreview = true;
        KeyDown += (_, e) =>
        {
            if (e.KeyCode == Keys.Escape) { DialogResult = DialogResult.Cancel; Close(); }
            if (e.KeyCode == Keys.Enter && _ok.Enabled) { e.SuppressKeyPress = true; _ = ValiderAsync(); }
        };
        Construire();
    }

    private void Construire()
    {
        var titre = new Label
        {
            Text = "Fermer la station", Font = new Font("Segoe UI", 15F, FontStyle.Bold),
            ForeColor = Color.White, AutoSize = false,
        };
        titre.SetBounds(24, 18, 400, 26);

        var sous = new Label
        {
            Text = "Réservé aux administrateurs. La station cessera d'analyser les clés\n"
                 + "insérées sur ce poste.",
            ForeColor = Grise, AutoSize = false, Font = new Font("Segoe UI", 8.5F),
        };
        sous.SetBounds(26, 46, 410, 32);

        var l1 = Etiquette("Identifiant", 24, 88);
        _user.SetBounds(24, 108, 412, 26); Champ(_user);

        var l2 = Etiquette("Mot de passe", 24, 144);
        _pass.SetBounds(24, 164, 412, 26); Champ(_pass);
        _pass.UseSystemPasswordChar = true;

        // Toujours visible : le champ n'apparaît pas « en plus » après coup, ce qui
        // révélerait qu'un compte donné a une authentification à deux facteurs. La
        // passerelle ne le dit qu'APRÈS avoir validé le mot de passe.
        _lblCode.Text = "Code à deux facteurs (si votre compte en a un)";
        _lblCode.ForeColor = Encre; _lblCode.AutoSize = true;
        _lblCode.Font = new Font("Segoe UI", 9F, FontStyle.Bold);
        _lblCode.SetBounds(24, 200, 400, 18);
        _code.SetBounds(24, 220, 140, 26); Champ(_code);
        _code.MaxLength = 6;

        _verdict.SetBounds(26, 254, 410, 34);
        _verdict.ForeColor = Grise;
        _verdict.Font = new Font("Segoe UI", 8.5F);
        _verdict.AutoSize = false;

        _ok.Text = "Fermer la station";
        Bouton(_ok, Bleu);
        _ok.SetBounds(276, 298, 160, 30);
        _ok.Click += async (_, _) => await ValiderAsync();

        _annuler.Text = "Annuler";
        Bouton(_annuler, Color.FromArgb(51, 65, 85));
        _annuler.SetBounds(180, 298, 88, 30);
        _annuler.Click += (_, _) => { DialogResult = DialogResult.Cancel; Close(); };

        Controls.AddRange(new Control[] { titre, sous, l1, _user, l2, _pass, _lblCode, _code,
            _verdict, _ok, _annuler });
        ActiveControl = _user;
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

    private void Bouton(Button b, Color c)
    {
        b.FlatStyle = FlatStyle.Flat; b.FlatAppearance.BorderSize = 0;
        b.BackColor = c; b.ForeColor = c == Bleu ? Color.FromArgb(11, 17, 32) : Encre;
        b.Font = new Font("Segoe UI", 9F, FontStyle.Bold);
        b.Cursor = Cursors.Hand;
    }

    private async Task ValiderAsync()
    {
        _ok.Enabled = false;
        _verdict.ForeColor = Grise;
        _verdict.Text = "Vérification auprès de la passerelle…";

        var (ok, msg, _) = await _api.AuthentifierAsync(_user.Text.Trim(), _pass.Text,
            _code.Text.Trim(), CancellationToken.None);

        if (ok) { DialogResult = DialogResult.OK; Close(); return; }

        _verdict.ForeColor = Color.FromArgb(248, 113, 113);
        _verdict.Text = "⛔ " + msg;
        // Le mot de passe est vidé, pas l'identifiant : une faute de frappe sur le mot de
        // passe ne doit pas obliger à tout retaper, et un mot de passe qui reste affiché
        // sur une borne dans un couloir est un mot de passe exposé.
        _pass.Clear();
        _code.Clear();
        ActiveControl = _pass;
        _ok.Enabled = true;
    }
}
