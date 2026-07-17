using System.Diagnostics;
using System.Text;
using System.Text.RegularExpressions;

namespace Bastion.StationBlanche;

/// <summary>
/// Moteur ClamAV — celui de Bastion.
///
/// POURQUOI CLAMAV ICI : c'est le moteur de la passerelle. Une seule base, une seule
/// chaîne de mise à jour, un seul verdict à expliquer. Et surtout, la station n'a pas
/// besoin d'Internet : la passerelle fait tourner freshclam et lui sert les signatures
/// sur le LAN. Un poste isolé en commissariat reste à jour.
///
/// CE QU'IL NE FAIT PAS : ClamAV a été conçu pour filtrer des flux de messagerie, pas pour
/// protéger un poste Windows. Sa détection sur les malwares Windows est en retrait de
/// celle de Defender. C'est pour cela qu'il ne travaille pas seul ici — voir IMoteur.
/// </summary>
public sealed class MoteurClamav : IMoteur
{
    private readonly BastionApi _api;

    public MoteurClamav(BastionApi api) => _api = api;

    public string Nom => "ClamAV";

    private static readonly string[] Emplacements =
    {
        @"C:\Program Files\ClamAV\clamscan.exe",
        @"C:\Program Files (x86)\ClamAV\clamscan.exe",
        @"C:\ClamAV\clamscan.exe",
    };

    /// <summary>
    /// Dossier de la base virale, à NOUS.
    ///
    /// On n'écrit pas dans celui de ClamAV sous « Program Files » : il faut des droits
    /// d'administrateur, qu'une borne n'a pas. La station y dépose ce qu'elle télécharge
    /// depuis la passerelle et le passe à clamscan par « --database ».
    /// </summary>
    public static string DossierBase
    {
        get
        {
            foreach (var racine in new[] { Environment.SpecialFolder.CommonApplicationData,
                                           Environment.SpecialFolder.LocalApplicationData })
            {
                try
                {
                    var d = Path.Combine(Environment.GetFolderPath(racine), "Bastion", "clamav-db");
                    Directory.CreateDirectory(d);
                    // Créer le dossier ne prouve pas qu'on pourra y écrire 170 Mo : sur un
                    // poste durci, ProgramData peut être en lecture seule pour l'utilisateur.
                    var t = Path.Combine(d, ".essai");
                    File.WriteAllText(t, "x"); File.Delete(t);
                    return d;
                }
                catch { /* on tente la racine suivante */ }
            }
            return Path.Combine(Path.GetTempPath(), "Bastion", "clamav-db");
        }
    }

    public static string? TrouverExe()
    {
        foreach (var p in Emplacements) { if (File.Exists(p)) return p; }
        // Dans le PATH ? Une installation portable peut être posée n'importe où.
        try
        {
            foreach (var d in (Environment.GetEnvironmentVariable("PATH") ?? "").Split(';'))
            {
                if (string.IsNullOrWhiteSpace(d)) continue;
                var p = Path.Combine(d.Trim(), "clamscan.exe");
                if (File.Exists(p)) return p;
            }
        }
        catch { }
        return null;
    }

    // La version demande de LANCER clamscan. LireEtat() est appelé à chaque affichage,
    // chaque analyse et chaque remontée, sur le fil de l'interface : sans mémorisation, la
    // borne se figerait le temps d'un démarrage de processus à chaque fois. La version d'un
    // exécutable ne change pas en cours de session ; la date des signatures, si — elle est
    // relue à chaque appel, plus bas, et ne coûte qu'un accès disque.
    private static string? _version;

    public EtatMoteur LireEtat()
    {
        var exe = TrouverExe();
        if (exe == null) return new EtatMoteur(false, null, "", int.MaxValue);

        if (_version == null)
        {
            _version = "ClamAV";
            try
            {
                // « --version » rend « ClamAV 1.5.3/27890/Thu Jul 17 08:00:00 2026 ». On ne
                // garde que le premier champ : la date qui suit dépend de la base que ClamAV
                // trouve LUI-MÊME, pas de la nôtre. Elle mentirait sur ce qu'on utilise.
                var s = Executer(exe, "--version", TimeSpan.FromSeconds(10));
                var m = Regex.Match(s, @"ClamAV\s+([0-9][0-9.\-a-z]*)", RegexOptions.IgnoreCase);
                if (m.Success) _version = "ClamAV " + m.Groups[1].Value;
            }
            catch { }
        }
        var version = _version;

        // La date des signatures est celle des fichiers que NOUS avons téléchargés. On
        // préserve à la copie la date qu'ils ont sur la passerelle : elle reflète donc la
        // fraîcheur réelle de la base, pas l'heure du téléchargement.
        DateTime? date = null;
        try
        {
            foreach (var f in new[] { "daily.cld", "daily.cvd" })
            {
                var p = Path.Combine(DossierBase, f);
                if (!File.Exists(p)) continue;
                var d = File.GetLastWriteTime(p);
                if (date == null || d > date) date = d;
            }
        }
        catch { }

        var age = date.HasValue ? (int) (DateTime.Now - date.Value).TotalDays : int.MaxValue;
        return new EtatMoteur(true, date, version, age);
    }

    public async Task<Resultat> AnalyserAsync(string chemin, CancellationToken jeton)
    {
        var debut = Stopwatch.StartNew();
        var exe = TrouverExe();
        if (exe == null)
            return new Resultat(false, 0, Array.Empty<Menace>(), debut.Elapsed, "",
                "ClamAV n'est pas installé sur ce poste.");

        // Sans base, clamscan sort en erreur — mais mieux vaut le dire clairement que de
        // laisser l'agent lire un message du moteur.
        if (!Directory.Exists(DossierBase) || !Directory.EnumerateFiles(DossierBase, "*.c?d").Any())
            return new Resultat(false, 0, Array.Empty<Menace>(), debut.Elapsed, "",
                "Base virale ClamAV absente : la station ne l'a jamais reçue de la passerelle.");

        // ── ARGUMENTS ────────────────────────────────────────────────────────────────
        // JAMAIS « --remove », « --move » ni « --copy » : une clé peut être un scellé, et
        // la station CONSTATE, elle ne corrige pas. clamscan ne touche à rien par défaut ;
        // ces options sont la seule façon de le faire écrire sur le support, et elles ne
        // doivent jamais apparaître ici.
        var args = new StringBuilder();
        args.Append("--database=").Append('"').Append(DossierBase).Append('"');
        args.Append(" --recursive --infected --stdout");
        args.Append(" --max-filesize=2000M --max-scansize=0 --max-files=0");   // 0 = sans limite
        args.Append(" --alert-encrypted=yes");   // une archive chiffrée est signalée, pas ignorée
        args.Append(' ').Append('"').Append(chemin.TrimEnd('\\')).Append('"');

        try
        {
            var (sortie, code) = await ExecuterAsync(exe, args.ToString(), jeton);
            var menaces = LireMenaces(sortie);

            // ── CODES DE RETOUR ──────────────────────────────────────────────────────
            // clamscan : 0 = rien trouvé, 1 = MENACE TROUVÉE, 2 = erreur.
            // À NE PAS confondre avec MpCmdRun, où c'est le 2 qui signale une menace. Les
            // deux moteurs cohabitent dans ce logiciel : recopier la logique de l'un sur
            // l'autre ferait passer une clé infectée pour un incident technique, ou pire,
            // une erreur pour une clé saine.
            if (code == 2)
                return new Resultat(false, menaces.Count, menaces, debut.Elapsed, sortie,
                    "ClamAV a rencontré une erreur : " + (PremiereErreur(sortie) ?? "cause inconnue") + ".");

            return new Resultat(true, menaces.Count, menaces, debut.Elapsed, sortie);
        }
        catch (OperationCanceledException)
        {
            return new Resultat(false, 0, Array.Empty<Menace>(), debut.Elapsed, "",
                "Analyse interrompue (support retiré ?).");
        }
        catch (Exception ex)
        {
            return new Resultat(false, 0, Array.Empty<Menace>(), debut.Elapsed, "", "ClamAV : " + ex.Message);
        }
    }

    /// <summary>« C:\chemin\fichier: Win.Test.EICAR_HDB-1 FOUND »</summary>
    private static List<Menace> LireMenaces(string sortie)
    {
        var l = new List<Menace>();
        foreach (Match m in Regex.Matches(sortie, @"^(?<f>.+?):\s+(?<n>\S+)\s+FOUND\s*$",
                     RegexOptions.Multiline))
            l.Add(new Menace(m.Groups["n"].Value, m.Groups["f"].Value.Trim()));
        return l;
    }

    private static string? PremiereErreur(string sortie)
    {
        var m = Regex.Match(sortie, @"^(?:ERROR|LibClamAV Error):\s*(?<m>.+)$", RegexOptions.Multiline);
        return m.Success ? m.Groups["m"].Value.Trim() : null;
    }

    /// <summary>
    /// Rapatrie la base virale depuis la passerelle. C'est freshclam qui tourne là-bas :
    /// la station ne parle jamais aux miroirs ClamAV, et n'a donc pas besoin d'Internet.
    /// </summary>
    public async Task<(bool ok, string message)> MettreAJourAsync(CancellationToken jeton)
    {
        if (TrouverExe() == null) return (false, "ClamAV n'est pas installé sur ce poste.");
        return await _api.SynchroniserBaseAsync(DossierBase, jeton);
    }

    private static string Executer(string exe, string args, TimeSpan delai)
    {
        using var p = Process.Start(new ProcessStartInfo(exe, args)
        {
            RedirectStandardOutput = true, RedirectStandardError = true,
            UseShellExecute = false, CreateNoWindow = true,
        })!;
        var s = p.StandardOutput.ReadToEnd() + p.StandardError.ReadToEnd();
        if (!p.WaitForExit((int) delai.TotalMilliseconds)) { try { p.Kill(true); } catch { } }
        return s;
    }

    private static async Task<(string, int)> ExecuterAsync(string exe, string args, CancellationToken jeton)
    {
        using var p = new Process
        {
            StartInfo = new ProcessStartInfo(exe, args)
            {
                RedirectStandardOutput = true, RedirectStandardError = true,
                UseShellExecute = false, CreateNoWindow = true,
            },
        };
        var sb = new StringBuilder();
        p.OutputDataReceived += (_, e) => { if (e.Data != null) lock (sb) sb.AppendLine(e.Data); };
        p.ErrorDataReceived += (_, e) => { if (e.Data != null) lock (sb) sb.AppendLine(e.Data); };
        p.Start();
        p.BeginOutputReadLine();
        p.BeginErrorReadLine();
        try
        {
            await p.WaitForExitAsync(jeton);
        }
        catch (OperationCanceledException)
        {
            // Le support a été retiré : on ne laisse pas un clamscan orphelin tenir un
            // handle sur un lecteur qui n'existe plus.
            try { p.Kill(true); } catch { }
            throw;
        }
        lock (sb) return (sb.ToString(), p.ExitCode);
    }
}
