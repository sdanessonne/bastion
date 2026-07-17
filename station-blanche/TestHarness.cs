namespace Bastion.StationBlanche;

/// <summary>
/// Banc d'essai sans interface : « --test [chemin] » exerce les moteurs et la détection en
/// automatique. Une station blanche ne se valide pas à la souris.
///
/// « --test --maj » rapatrie d'abord la base virale depuis la passerelle : c'est le seul
/// moyen d'éprouver la synchronisation sans brancher une clé sur une borne.
/// </summary>
public static class TestHarness
{
    public static async Task<int> Run(string[] a)
    {
        var cfg = Config.Charger();
        var api = new BastionApi(cfg);
        var moteurs = new List<IMoteur> { new MoteurClamav(api) };
        if (cfg.DefenderEnSecondAvis) moteurs.Add(new MoteurDefender());

        Console.WriteLine($"PASSERELLE {(cfg.RemonteeActive ? cfg.Passerelle : "(non configuree)")}");
        Console.WriteLine($"BASE CLAMAV {MoteurClamav.DossierBase}");

        // « --daemon » lance clamd et attend qu'il ait chargé sa base : c'est le seul moyen
        // de mesurer le chemin rapide sans interface. Sans ce drapeau, le banc analyse par
        // clamscan — utile aussi, c'est le repli.
        if (a.Contains("--daemon"))
        {
            var t0 = System.Diagnostics.Stopwatch.StartNew();
            MoteurClamav.DemarrerDaemon();
            var pret = await MoteurClamav.AttendreDaemonAsync(TimeSpan.FromMinutes(2), CancellationToken.None);
            Console.WriteLine($"DAEMON pret={pret} apres {t0.Elapsed.TotalSeconds:F1}s");
        }

        if (a.Contains("--maj"))
        {
            foreach (var m in moteurs.Where(m => m.LireEtat().Present))
            {
                var (ok, msg) = await m.MettreAJourAsync(CancellationToken.None);
                Console.WriteLine($"MAJ {m.Nom} ok={ok} : {msg}");
            }
        }

        foreach (var m in moteurs)
        {
            var e = m.LireEtat();
            Console.WriteLine($"MOTEUR {m.Nom} present={e.Present} version={e.Version} "
                            + $"signatures={(e.Signatures.HasValue ? $"{e.Signatures:yyyy-MM-dd HH:mm}" : "-")} "
                            + $"age={(e.AgeJours == int.MaxValue ? "?" : e.AgeJours + "j")}");
        }

        Console.WriteLine("SUPPORTS USB detectes :");
        var l = UsbWatcher.Lister();
        if (l.Count == 0) Console.WriteLine("  (aucun)");
        foreach (var s in l) Console.WriteLine($"  {s.Lettre} bus={s.Bus} materiel=\"{s.Materiel}\" nom=\"{s.Nom}\" taille={Support.Fmt(s.Taille)}");

        var chemin = a.Skip(1).FirstOrDefault(x => !x.StartsWith("--"));
        if (chemin == null) return 0;

        var analyses = new List<(IMoteur, Resultat)>();
        foreach (var m in moteurs.Where(m => m.LireEtat().Present))
        {
            var r = await m.AnalyserAsync(chemin, CancellationToken.None);
            analyses.Add((m, r));
            Console.WriteLine($"ANALYSE {m.Nom} abouti={r.Abouti} menaces={r.NbMenaces} "
                            + $"duree={r.Duree.TotalMilliseconds:F0}ms erreur={r.Erreur ?? "-"}");
            foreach (var x in r.Menaces) Console.WriteLine($"  MENACE {x.Nom} -> {Path.GetFileName(x.Fichier)}");
        }
        if (analyses.Count == 0) { Console.WriteLine("ANALYSE aucun moteur disponible"); return 3; }

        var v = new Verdict(analyses);
        Console.WriteLine($"VERDICT abouti={v.Abouti} menaces={v.NbMenaces} duree={v.Duree.TotalSeconds:F1}s");
        foreach (var e in v.Ecueils) Console.WriteLine($"  ECUEIL {e}");
        return v.NbMenaces;
    }
}
