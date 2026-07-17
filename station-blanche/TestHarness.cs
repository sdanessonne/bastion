namespace Bastion.StationBlanche;
public static class TestHarness
{
    public static async Task<int> Run(string[] a)
    {
        var e = Defender.LireEtat();
        Console.WriteLine($"MOTEUR present={e.Present} version={e.Version} signatures={e.Signatures:yyyy-MM-dd HH:mm} age={e.AgeJours}j");
        if (a.Length < 2) return 0;
        var r = await Defender.AnalyserAsync(a[1], CancellationToken.None);
        Console.WriteLine($"ANALYSE abouti={r.Abouti} menaces={r.NbMenaces} duree={r.Duree.TotalMilliseconds:F0}ms erreur={r.Erreur ?? "-"}");
        foreach (var m in r.Menaces) Console.WriteLine($"  MENACE {m.Nom} -> {Path.GetFileName(m.Fichier)}");
        return r.NbMenaces;
    }
}
