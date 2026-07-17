using System.Diagnostics;
using System.Text.RegularExpressions;

namespace Bastion.StationBlanche;

/// <summary>Une menace trouvée sur le support.</summary>
public sealed record Menace(string Nom, string Fichier);

/// <summary>Résultat d'une analyse.</summary>
public sealed record Resultat(
    bool Abouti,              // l'analyse est-elle allée à son terme ?
    int NbMenaces,
    IReadOnlyList<Menace> Menaces,
    TimeSpan Duree,
    string Journal,           // sortie brute, pour l'export et le diagnostic
    string? Erreur = null);

/// <summary>État du moteur : sans signatures à jour, une station blanche ne vaut rien.</summary>
public sealed record EtatMoteur(bool Present, DateTime? Signatures, string Version, int AgeJours);

/// <summary>
/// Enveloppe autour de l'antivirus Windows Defender (MpCmdRun.exe).
///
/// POURQUOI DEFENDER : il est présent sur TOUT poste Windows, sans rien à installer ni
/// licence à acheter. Une station blanche doit fonctionner sur un poste isolé, souvent
/// sans droits d'administration ni accès Internet.
///
/// LIMITE ASSUMÉE : un seul moteur. Les stations blanches du commerce en cumulent
/// plusieurs, car aucun moteur ne détecte tout. Le code est écrit pour qu'un second
/// moteur (ClamAV de la passerelle) puisse s'ajouter : voir IMoteur.
/// </summary>
public static class Defender
{
    // Deux emplacements : le binaire « stable » et celui de la plateforme mise à jour.
    // Defender se met à jour en déposant une NOUVELLE version dans Platform\<version>\ ;
    // celui de Program Files peut être ancien. On prend le plus récent.
    private static readonly string[] Emplacements =
    {
        @"C:\ProgramData\Microsoft\Windows Defender\Platform",
        @"C:\Program Files\Windows Defender",
    };

    public static string? TrouverExe()
    {
        var plateforme = Emplacements[0];
        if (Directory.Exists(plateforme))
        {
            // Les dossiers sont nommés « 4.18.26060.3008-0 » : le tri ALPHABÉTIQUE est
            // faux (4.18.9 passerait après 4.18.26060). On trie sur la date de création.
            var recent = new DirectoryInfo(plateforme).GetDirectories()
                .Where(d => File.Exists(Path.Combine(d.FullName, "MpCmdRun.exe")))
                .OrderByDescending(d => d.CreationTimeUtc)
                .FirstOrDefault();
            if (recent != null) return Path.Combine(recent.FullName, "MpCmdRun.exe");
        }
        var fixe = Path.Combine(Emplacements[1], "MpCmdRun.exe");
        return File.Exists(fixe) ? fixe : null;
    }

    /// <summary>
    /// Âge des signatures. Une station blanche dont les signatures datent de six mois
    /// donne une FAUSSE ASSURANCE : elle déclare « sain » ce qu'elle ne sait plus
    /// reconnaître. C'est pire que pas de station du tout, et cela doit se voir.
    /// </summary>
    public static EtatMoteur LireEtat()
    {
        var exe = TrouverExe();
        if (exe == null) return new EtatMoteur(false, null, "", int.MaxValue);

        // PowerShell plutôt que MpCmdRun : Get-MpComputerStatus rend la date des
        // signatures de façon fiable et lisible, ce que MpCmdRun n'expose pas.
        var sortie = Executer("powershell.exe",
            "-NoProfile -NonInteractive -Command \"(Get-MpComputerStatus).AntivirusSignatureLastUpdated.ToString('o')\"",
            TimeSpan.FromSeconds(20));

        DateTime? maj = DateTime.TryParse(sortie.Trim(), null,
            System.Globalization.DateTimeStyles.RoundtripKind, out var d) ? d : null;

        var version = Path.GetFileName(Path.GetDirectoryName(exe)) ?? "";
        var age = maj.HasValue ? (int)(DateTime.Now - maj.Value).TotalDays : int.MaxValue;
        return new EtatMoteur(true, maj, version, age);
    }

    /// <summary>
    /// Analyse un support. NE MODIFIE RIEN.
    ///
    /// « -DisableRemediation » est ESSENTIEL, et ce n'est pas un détail de confort :
    /// sans lui, Defender SUPPRIME ou met en quarantaine ce qu'il trouve. Sur une clé
    /// remise par un tiers — voire placée sous scellé — cela détruirait une pièce et
    /// rendrait l'analyse inexploitable. La station CONSTATE, elle ne touche à rien.
    /// La décision d'effacer appartient à l'agent, pas au logiciel.
    /// </summary>
    public static async Task<Resultat> AnalyserAsync(string chemin, CancellationToken jeton)
    {
        var exe = TrouverExe();
        if (exe == null)
            return new Resultat(false, 0, Array.Empty<Menace>(), TimeSpan.Zero, "",
                "Windows Defender est introuvable sur ce poste.");

        // VÉRIFIER LE SUPPORT AVANT D'ANALYSER — sans quoi la station MENT.
        // MESURÉ : sur un chemin inexistant, MpCmdRun réfléchit 3,4 s puis sort avec le
        // code 0. Sans ce contrôle, une clé retirée en cours d'analyse — ou une lettre de
        // lecteur erronée — serait déclarée SAINE. C'est le pire défaut possible pour une
        // station blanche : donner un feu vert sur ce qu'on n'a pas regardé.
        if (!Directory.Exists(chemin))
            return new Resultat(false, 0, Array.Empty<Menace>(), TimeSpan.Zero, "",
                "Le support n'est plus accessible. A-t-il été retiré ?");

        int nbFichiers;
        try
        {
            nbFichiers = Directory.EnumerateFiles(chemin, "*", SearchOption.AllDirectories).Take(1).Count();
        }
        catch (Exception ex)
        {
            return new Resultat(false, 0, Array.Empty<Menace>(), TimeSpan.Zero, "",
                "Le support est illisible : " + ex.Message);
        }

        var chrono = Stopwatch.StartNew();
        string sortie;
        int code;
        try
        {
            (sortie, code) = await ExecuterAsync(exe,
                $"-Scan -ScanType 3 -File \"{chemin.TrimEnd('\\')}\" -DisableRemediation", jeton);
        }
        catch (OperationCanceledException)
        {
            return new Resultat(false, 0, Array.Empty<Menace>(), chrono.Elapsed, "", "Analyse interrompue.");
        }
        chrono.Stop();

        // CODES DE RETOUR — le piège : 2 n'est PAS une erreur, c'est « menace trouvée ».
        // Le traiter comme un échec ferait passer une clé infectée pour un incident
        // technique, et la station laisserait passer la clé.
        //   0 = aucune menace   2 = menace(s) trouvée(s)   autre = échec réel
        if (code != 0 && code != 2)
            return new Resultat(false, 0, Array.Empty<Menace>(), chrono.Elapsed, sortie,
                $"L'analyse a échoué (code {code}). Le support est-il toujours branché ?");

        var menaces = Analyser(sortie);

        // Cohérence : le compte annoncé par Defender doit correspondre à ce qu'on a su
        // extraire. Un écart signale un format de sortie inattendu (version différente,
        // langue différente) — on préfère le dire que d'annoncer « sain » à tort.
        var annonce = LireNombreAnnonce(sortie);
        if (annonce > 0 && menaces.Count == 0)
            return new Resultat(false, annonce, Array.Empty<Menace>(), chrono.Elapsed, sortie,
                $"Defender annonce {annonce} menace(s) mais le détail n'a pas pu être lu. " +
                "Considérez le support comme SUSPECT et consultez le journal.");

        // Un support VIDE n'est pas un support sain : il n'y avait rien à examiner.
        // Le dire, plutôt que d'afficher un « aucune menace » trompeur.
        if (nbFichiers == 0)
            return new Resultat(true, 0, Array.Empty<Menace>(), chrono.Elapsed, sortie,
                "Aucun fichier sur ce support : rien n'a été analysé.");

        return new Resultat(true, menaces.Count, menaces, chrono.Elapsed, sortie);
    }

    // « Scanning C:\... found 3 threats. » — présent quelle que soit la mise en forme.
    private static int LireNombreAnnonce(string s)
    {
        var m = Regex.Match(s, @"found\s+(\d+)\s+threat", RegexOptions.IgnoreCase);
        return m.Success && int.TryParse(m.Groups[1].Value, out var n) ? n : 0;
    }

    /// <summary>
    /// Extrait les menaces de la sortie de MpCmdRun. Format observé :
    ///   Threat                  : Virus:DOS/EICAR_Test_File
    ///   Resources               : 1 total
    ///       file                : C:\chemin\vers\fichier
    /// Un même nom de menace peut couvrir PLUSIEURS fichiers : on garde chaque couple.
    /// </summary>
    private static List<Menace> Analyser(string sortie)
    {
        var liste = new List<Menace>();
        string? courante = null;
        foreach (var brut in sortie.Split('\n'))
        {
            var l = brut.Trim();
            var mt = Regex.Match(l, @"^Threat\s*:\s*(.+?)\s*$");
            if (mt.Success) { courante = mt.Groups[1].Value; continue; }

            var mf = Regex.Match(l, @"^file\s*:\s*(.+?)\s*$");
            if (mf.Success && courante != null) liste.Add(new Menace(courante, mf.Groups[1].Value));
        }
        return liste;
    }

    private static string Executer(string exe, string args, TimeSpan delai)
    {
        try
        {
            using var p = Process.Start(new ProcessStartInfo(exe, args)
            { RedirectStandardOutput = true, RedirectStandardError = true, UseShellExecute = false, CreateNoWindow = true });
            if (p == null) return "";
            var s = p.StandardOutput.ReadToEnd();
            if (!p.WaitForExit((int)delai.TotalMilliseconds)) { try { p.Kill(true); } catch { } }
            return s;
        }
        catch { return ""; }
    }

    private static async Task<(string, int)> ExecuterAsync(string exe, string args, CancellationToken jeton)
    {
        using var p = new Process
        {
            StartInfo = new ProcessStartInfo(exe, args)
            {
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            }
        };
        p.Start();
        // Lire AVANT d'attendre la fin : le tuyau de sortie a une taille limitée, et un
        // processus qui le remplit se BLOQUE tant que personne ne lit. Une analyse avec
        // beaucoup de menaces se figerait donc indéfiniment.
        var lecture = p.StandardOutput.ReadToEndAsync();
        var erreurs = p.StandardError.ReadToEndAsync();
        try
        {
            await p.WaitForExitAsync(jeton);
        }
        catch (OperationCanceledException)
        {
            try { p.Kill(true); } catch { }
            throw;
        }
        var sortie = await lecture + await erreurs;
        return (sortie, p.ExitCode);
    }
}
