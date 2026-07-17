namespace Bastion.StationBlanche;

static class Program
{
    /// <summary>
    /// Point d'entrée. « --test &lt;chemin&gt; » exerce le moteur SANS interface : c'est ce
    /// qui permet d'éprouver la détection en automatique, sans clic humain.
    /// « --fenetre » force le mode fenêtré, pour préparer un poste sans se retrouver
    /// enfermé dans la borne.
    /// </summary>
    [STAThread]
    static int Main(string[] args)
    {
        if (args.Length > 0 && args[0] == "--test")
            return TestHarness.Run(args).GetAwaiter().GetResult();

        if (args.Length > 1 && args[0] == "--capture") return Capture(args[1], args.Contains("--demo"));
        if (args.Length > 1 && args[0] == "--capture-reglages") return CaptureReglages(args[1]);
        if (args.Length > 1 && args[0] == "--capture-fermeture") return CaptureFermeture(args[1]);

        var cfg = Config.Charger();
        cfg.EcrireModeleSiAbsent();   // pour que l'exploitant trouve un fichier à remplir
        if (args.Contains("--fenetre")) cfg.Kiosque = false;

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm(cfg));
        return 0;
    }

    /// <summary>Même chose pour l'écran de fermeture.</summary>
    private static int CaptureFermeture(string chemin)
    {
        ApplicationConfiguration.Initialize();
        var f = new FermetureForm(new BastionApi(new Config { Passerelle = "https://x", Jeton = "x" }));
        f.Show();
        Application.DoEvents();
        using var bmp = new Bitmap(f.Width, f.Height);
        f.DrawToBitmap(bmp, new Rectangle(0, 0, f.Width, f.Height));
        bmp.Save(chemin, System.Drawing.Imaging.ImageFormat.Png);
        f.Dispose();
        Console.WriteLine("capture -> " + chemin);
        return 0;
    }

    /// <summary>Même chose pour l'écran de réglages.</summary>
    private static int CaptureReglages(string chemin)
    {
        ApplicationConfiguration.Initialize();
        var f = new ReglagesForm(new Config
        {
            Passerelle = "https://192.168.182.1:8443",
            Jeton = new string('0', 64),   // jamais un vrai jeton dans une capture
        });
        f.Show();
        Application.DoEvents();
        using var bmp = new Bitmap(f.Width, f.Height);
        f.DrawToBitmap(bmp, new Rectangle(0, 0, f.Width, f.Height));
        bmp.Save(chemin, System.Drawing.Imaging.ImageFormat.Png);
        f.Dispose();
        Console.WriteLine("capture -> " + chemin);
        return 0;
    }

    /// <summary>
    /// Enregistre l'écran dans un PNG puis rend la main. Sert à contrôler la mise en page
    /// sans avoir un poste sous les yeux, et à illustrer la notice. « --demo » ajoute une
    /// clé fictive : c'est le seul moyen de montrer l'écran peuplé sans en brancher une.
    /// </summary>
    private static int Capture(string chemin, bool demo)
    {
        ApplicationConfiguration.Initialize();
        var f = new MainForm(new Config { Kiosque = false, MajAuto = false, Passerelle = "", Jeton = "" });
        f.Show();
        if (demo) f.InjecterSupportFictif(new Support("E:", "SANS TITRE", 15_728_640_000, "SanDisk Ultra USB Device", "USB"));
        Application.DoEvents();
        using var bmp = new Bitmap(f.Width, f.Height);
        f.DrawToBitmap(bmp, new Rectangle(0, 0, f.Width, f.Height));
        bmp.Save(chemin, System.Drawing.Imaging.ImageFormat.Png);
        f.Dispose();
        Console.WriteLine("capture -> " + chemin);
        return 0;
    }
}
