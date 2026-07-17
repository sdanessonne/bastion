namespace Bastion.StationBlanche;

/// <summary>
/// Un moteur d'analyse.
///
/// POURQUOI DEUX MOTEURS : aucun moteur ne détecte tout. ClamAV est celui de Bastion —
/// même base, même chaîne de mise à jour que la passerelle, et il fonctionne sans Internet
/// puisque la passerelle lui sert les signatures. Windows Defender vient en second avis :
/// il est déjà là, et sa détection sur les malwares Windows est meilleure que celle de
/// ClamAV, qui a été conçu pour filtrer des flux de messagerie plutôt que protéger un
/// poste. Les faire tourber tous les deux coûte quelques secondes et rattrape ce que
/// l'autre laisse passer.
/// </summary>
public interface IMoteur
{
    /// <summary>Nom affiché, ex. « ClamAV ».</summary>
    string Nom { get; }

    /// <summary>Présence, version, date des signatures.</summary>
    EtatMoteur LireEtat();

    /// <summary>
    /// Analyse un support. Ne DOIT jamais le modifier : une clé peut être un scellé.
    /// </summary>
    Task<Resultat> AnalyserAsync(string chemin, CancellationToken jeton);

    /// <summary>Met à jour les signatures. Rend le motif d'échec le cas échéant.</summary>
    Task<(bool ok, string message)> MettreAJourAsync(CancellationToken jeton);
}

/// <summary>Verdict consolidé de plusieurs moteurs.</summary>
public sealed record Verdict(
    IReadOnlyList<(IMoteur moteur, Resultat resultat)> Analyses)
{
    /// <summary>
    /// Menaces de tous les moteurs, regroupées PAR FICHIER.
    ///
    /// Le regroupement se fait sur le fichier seul, pas sur le couple fichier + signature :
    /// deux moteurs nomment DIFFÉREMMENT la même chose. MESURÉ sur EICAR — ClamAV rend
    /// « Bastion-Test-EICAR.UNOFFICIAL », Defender « Virus:DOS/EICAR_Test_File ». Compter
    /// les couples annonçait « 2 menaces » là où il n'y a qu'UN fichier infecté.
    ///
    /// Les deux appellations sont conservées et affichées : savoir que les deux moteurs
    /// sont d'accord vaut mieux que n'en montrer qu'un.
    /// </summary>
    public IReadOnlyList<Menace> Menaces => Analyses
        .SelectMany(a => a.resultat.Menaces)
        .GroupBy(m => Cle(m.Fichier))
        .Select(g => new Menace(
            string.Join(" · ", g.Select(m => m.Nom).Distinct()),
            g.First().Fichier))
        .ToList();

    /// <summary>
    /// Clé de regroupement d'un fichier, indépendante de l'écriture du chemin.
    ///
    /// Comparer les chaînes brutes ne suffit pas : les moteurs ne rendent pas le chemin de
    /// la même façon. OBSERVÉ — sur un même fichier, l'un a rendu « C:/…/e.txt » et l'autre
    /// « C:\…\e.txt », et la station a compté DEUX menaces là où il n'y en avait qu'une.
    /// Le décompte affiché à l'agent ne doit pas dépendre du style de barre oblique d'un
    /// moteur.
    /// </summary>
    private static string Cle(string chemin)
    {
        var c = chemin.Trim().Replace('/', '\\');
        // GetFullPath lève sur un chemin invalide (caractère interdit, chemin trop long) :
        // on se rabat alors sur la forme normalisée, plutôt que de faire échouer l'affichage
        // du verdict pour une histoire de nom de fichier.
        try { c = Path.GetFullPath(c); } catch { }
        return c.TrimEnd('\\').ToLowerInvariant();
    }

    /// <summary>Nombre de FICHIERS infectés — pas de signatures relevées.</summary>
    public int NbMenaces => Menaces.Count;

    /// <summary>
    /// Vrai seulement si TOUS les moteurs ayant tourné sont allés à leur terme.
    ///
    /// Le « et » est capital : si ClamAV rend « rien trouvé » mais que Defender s'est
    /// interrompu, on ne sait pas ce que Defender aurait vu. Annoncer « aucune menace »
    /// serait une affirmation qu'aucun des deux moteurs n'a faite.
    /// </summary>
    public bool Abouti => Analyses.Count > 0 && Analyses.All(a => a.resultat.Abouti);

    public TimeSpan Duree => Analyses.Count == 0
        ? TimeSpan.Zero
        : TimeSpan.FromTicks(Analyses.Sum(a => a.resultat.Duree.Ticks));

    /// <summary>Sortie brute de chaque moteur, pour le rapport.</summary>
    public string Journal => string.Join("\n\n", Analyses.Select(a =>
        $"───── {a.moteur.Nom} ─────\n{a.resultat.Journal}"));

    /// <summary>Motifs d'échec, moteur par moteur. Vide si tout s'est bien passé.</summary>
    public IReadOnlyList<string> Ecueils => Analyses
        .Where(a => !a.resultat.Abouti || a.resultat.Erreur != null)
        .Select(a => $"{a.moteur.Nom} : {a.resultat.Erreur ?? "analyse non aboutie"}")
        .ToList();
}
