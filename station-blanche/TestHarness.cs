namespace Bastion.StationBlanche;

/// <summary>
/// Banc d'essai sans interface : « --test » exerce le moteur et la détection en
/// automatique. Une station blanche ne se valide pas à la souris.
/// </summary>
public static class TestHarness
{
    public static async Task<int> Run(string[] a)
    {
        var e = Defender.LireEtat();
        Console.WriteLine($"MOTEUR present={e.Present} version={e.Version} signatures={e.Signatures:yyyy-MM-dd HH:mm} age={e.AgeJours}j");

        Console.WriteLine("SUPPORTS USB detectes :");
        var l = UsbWatcher.Lister();
        if (l.Count == 0) Console.WriteLine("  (aucun)");
        foreach (var s in l) Console.WriteLine($"  {s.Lettre} bus={s.Bus} materiel=\"{s.Materiel}\" nom=\"{s.Nom}\" taille={Support.Fmt(s.Taille)}");

        if (a.Length < 2) return 0;
        var r = await Defender.AnalyserAsync(a[1], CancellationToken.None);
        Console.WriteLine($"ANALYSE abouti={r.Abouti} menaces={r.NbMenaces} duree={r.Duree.TotalMilliseconds:F0}ms erreur={r.Erreur ?? "-"}");
        foreach (var m in r.Menaces) Console.WriteLine($"  MENACE {m.Nom} -> {Path.GetFileName(m.Fichier)}");
        return r.NbMenaces;
    }
}
