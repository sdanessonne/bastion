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

    /// <summary>
    /// Port du démon, sur la boucle locale uniquement.
    ///
    /// 3311 et non 3310 (le port par défaut) : si le site fait déjà tourner un clamd, on ne
    /// lui prend pas sa place et on ne dépend pas de sa base — la nôtre vient de Bastion.
    /// </summary>
    private const int PortDaemon = 3311;

    private static Process? _daemon;

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

    /// <summary>
    /// Extensions de base virale reconnues par ClamAV.
    ///
    /// La passerelle sert des « .cvd » et « .cld » — mais s'en tenir à ces deux-là
    /// interdirait à un exploitant de déposer ses propres signatures (.hdb, .ndb, .ldb),
    /// ce que ClamAV sait parfaitement charger. La station déclarerait « base absente »
    /// devant un dossier qui en contient une.
    /// </summary>
    private static readonly string[] Extensions =
        { ".cvd", ".cld", ".cud", ".hdb", ".hsb", ".ndb", ".ldb", ".mdb", ".msb", ".sdb", ".cbc" };

    private static bool BasePresente()
    {
        try
        {
            return Directory.Exists(DossierBase) && Directory.EnumerateFiles(DossierBase)
                .Any(f => Extensions.Contains(Path.GetExtension(f).ToLowerInvariant()));
        }
        catch { return false; }
    }

    /// <summary>Chemin d'un binaire ClamAV voisin de clamscan.exe (clamd, clamdscan).</summary>
    private static string? Voisin(string nom)
    {
        var exe = TrouverExe();
        if (exe == null) return null;
        var p = Path.Combine(Path.GetDirectoryName(exe)!, nom);
        return File.Exists(p) ? p : null;
    }

    /// <summary>
    /// Lance le démon d'analyse et rend la main aussitôt.
    ///
    /// ── POURQUOI UN DÉMON, ET PAS clamscan ──────────────────────────────────────
    /// MESURÉ, base réelle (3,6 millions de signatures, 107 Mo) :
    ///     clamscan   : 34 907 / 35 690 / 35 754 ms  ← à CHAQUE analyse
    ///     clamdscan  :    338 /     48 /     50 ms
    /// Ces 35 secondes ne sont pas le temps d'analyse — le dossier testé ne contenait que
    /// 74 octets. C'est le chargement de la base, repayé à chaque lancement du processus.
    /// Sur une borne, l'agent aurait attendu 35 s à chaque clé insérée. Le démon la charge
    /// une fois et la garde : 700 fois plus rapide, pour ~960 Mo de mémoire retenue.
    ///
    /// Le chargement prend ces mêmes 35 s au démarrage de la station — sans agent devant
    /// l'écran. Tant qu'il dure, les analyses retombent sur clamscan : lentes, mais justes.
    /// </summary>
    public static void DemarrerDaemon()
    {
        var exe = Voisin("clamd.exe");
        if (exe == null || _daemon is { HasExited: false }) return;
        try
        {
            var conf = Path.Combine(DossierBase, "..", "clamd.conf");
            conf = Path.GetFullPath(conf);
            File.WriteAllText(conf, string.Join("\n", new[]
            {
                "DatabaseDirectory " + DossierBase,
                "TCPSocket " + PortDaemon,
                "TCPAddr 127.0.0.1",           // jamais exposé au réseau : le démon obéit sans authentifier
                "MaxThreads 4",
                "Foreground yes",              // sinon il se détache et on ne peut plus l'arrêter
                "SelfCheck 300",               // relit la base quand elle change : la MAJ est prise en compte
                "ExitOnOOM yes",
            }));
            _daemon = Process.Start(new ProcessStartInfo(exe, $"--config-file=\"{conf}\"")
            {
                UseShellExecute = false, CreateNoWindow = true,
                RedirectStandardOutput = true, RedirectStandardError = true,
            });
            // Il faut vider les tuyaux, sinon le démon se bloque une fois le tampon plein.
            _daemon?.BeginOutputReadLine();
            _daemon?.BeginErrorReadLine();
            if (_daemon != null) { _daemon.OutputDataReceived += (_, _) => { }; _daemon.ErrorDataReceived += (_, _) => { }; }
        }
        catch { _daemon = null; /* on se rabattra sur clamscan */ }
    }

    /// <summary>
    /// Attend que le démon réponde. Sert au banc de mesure ; l'interface, elle, n'attend
    /// pas — elle se rabat sur clamscan tant que le démon charge.
    /// </summary>
    public static async Task<bool> AttendreDaemonAsync(TimeSpan delai, CancellationToken jeton)
    {
        var fin = DateTime.UtcNow + delai;
        while (DateTime.UtcNow < fin && !jeton.IsCancellationRequested)
        {
            if (_daemon is null or { HasExited: true }) return false;
            try
            {
                using var c = new System.Net.Sockets.TcpClient();
                await c.ConnectAsync("127.0.0.1", PortDaemon, jeton);
                // Le port s'ouvre AVANT la fin du chargement : clamd écoute puis charge.
                // Se fier au port seul déclarerait « prêt » un démon qui refuse encore
                // toute analyse. PING/PONG est le seul aveu fiable qu'il est opérationnel.
                using var f = c.GetStream();
                await f.WriteAsync("zPING\0"u8.ToArray(), jeton);
                var buf = new byte[16];
                var n = await f.ReadAsync(buf, jeton);
                if (n > 0 && System.Text.Encoding.ASCII.GetString(buf, 0, n).StartsWith("PONG")) return true;
            }
            catch { /* pas encore là */ }
            await Task.Delay(500, jeton);
        }
        return false;
    }

    /// <summary>Arrête le démon. Sans cela, 960 Mo restent retenus après la fermeture.</summary>
    public static void ArreterDaemon()
    {
        try { if (_daemon is { HasExited: false }) _daemon.Kill(true); } catch { }
        _daemon = null;
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
        if (!BasePresente())
            return new Resultat(false, 0, Array.Empty<Menace>(), debut.Elapsed, "",
                "Base virale ClamAV absente : la station ne l'a jamais reçue de la passerelle.");

        // ── ARGUMENTS ────────────────────────────────────────────────────────────────
        // JAMAIS « --remove », « --move » ni « --copy » : une clé peut être un scellé, et
        // la station CONSTATE, elle ne corrige pas. Les moteurs ne touchent à rien par
        // défaut ; ces options sont la seule façon de les faire écrire sur le support, et
        // elles ne doivent jamais apparaître ici.
        var cible = '"' + chemin.TrimEnd('\\') + '"';

        try
        {
            string sortie; int code;

            // Le démon d'abord : 48 ms contre 35 s. Voir DemarrerDaemon().
            var viaDaemon = await AnalyserParDaemonAsync(cible, jeton);
            if (viaDaemon.HasValue)
            {
                (sortie, code) = viaDaemon.Value;
            }
            else
            {
                // Démon absent, ou encore en train de charger sa base : on analyse quand
                // même, en payant les 35 secondes. Lent, mais juste — et bien préférable à
                // ne rien dire à l'agent.
                var args = new StringBuilder();
                args.Append("--database=").Append('"').Append(DossierBase).Append('"');
                args.Append(" --recursive --infected --stdout");
                args.Append(" --max-filesize=2000M --max-scansize=0 --max-files=0");   // 0 = sans limite
                args.Append(" --alert-encrypted=yes");   // une archive chiffrée est signalée, pas ignorée
                args.Append(' ').Append(cible);
                (sortie, code) = await ExecuterAsync(exe, args.ToString(), jeton);
            }
            var menaces = LireMenaces(sortie);

            // ── CODES DE RETOUR ──────────────────────────────────────────────────────
            // clamscan : 0 = rien trouvé, 1 = MENACE TROUVÉE, 2 = erreur.
            // À NE PAS confondre avec MpCmdRun, où c'est le 2 qui signale une menace. Les
            // deux moteurs cohabitent dans ce logiciel : recopier la logique de l'un sur
            // l'autre ferait passer une clé infectée pour un incident technique, ou pire,
            // une erreur pour une clé saine.
            // Fichiers que ClamAV n'a PAS PU LIRE parce que Windows les lui a refusés.
            // Ce cas n'est pas un détail : voir Bloques().
            var bloques = Bloques(sortie);
            if (bloques > 0)
                return new Resultat(false, menaces.Count, menaces, debut.Elapsed, sortie,
                    $"Windows Defender a interdit à ClamAV de lire {bloques} fichier(s) — ce sont "
                  + "précisément ceux qu'il juge dangereux. Excluez les lecteurs amovibles de la "
                  + "protection temps réel, sinon ClamAV reste aveugle sur ce qui compte (voir la notice).");

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

    /// <summary>
    /// Analyse par le démon. Rend null s'il n'a pas pu être joint — à l'appelant de se
    /// rabattre sur clamscan.
    ///
    /// clamdscan rend les mêmes lignes « fichier: Signature FOUND » et les mêmes codes que
    /// clamscan (0 = rien, 1 = menace, 2 = erreur) : l'analyse de sortie est commune.
    /// </summary>
    private static async Task<(string, int)?> AnalyserParDaemonAsync(string cible, CancellationToken jeton)
    {
        var exe = Voisin("clamdscan.exe");
        var conf = Path.GetFullPath(Path.Combine(DossierBase, "..", "clamd.conf"));
        if (exe == null || _daemon is null or { HasExited: true } || !File.Exists(conf)) return null;

        // « --fdpass » n'existe pas sous Windows : le démon ouvre le fichier lui-même. Il
        // tourne sous le même compte que la station, il a donc les mêmes accès au support.
        var (sortie, code) = await ExecuterAsync(exe,
            $"--config-file=\"{conf}\" --multiscan --infected --stdout {cible}", jeton);

        // Démon injoignable (encore en train de charger sa base, ou mort) : ce n'est PAS un
        // résultat d'analyse. Le confondre avec « rien trouvé » donnerait un feu vert sur
        // une clé que personne n'a regardée.
        if (Regex.IsMatch(sortie, @"Could not connect|ERROR: Can't (?:connect|access)|connect\(\) error",
                RegexOptions.IgnoreCase))
            return null;

        return (sortie, code);
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

    /// <summary>
    /// Nombre de fichiers que Windows a REFUSÉ d'ouvrir à ClamAV.
    ///
    /// L'erreur Windows 225 est ERROR_VIRUS_INFECTED : « impossible de terminer
    /// l'opération, car le fichier contient un virus ». Quand la protection temps réel de
    /// Defender intercepte un fichier, elle en bloque l'ouverture par TOUT processus —
    /// ClamAV compris.
    ///
    /// CE QUI A ÉTÉ MESURÉ, exactement : une fois, sur EICAR, ClamAV a rendu
    ///     Can't open file …\eicar.txt: 225
    ///     Scanned files: 1   Infected files: 0   Total errors: 1
    /// et PowerShell s'est vu refuser le même fichier au même instant — ce n'est donc pas
    /// une bizarrerie de ClamAV, c'est le système qui refuse. Mais le cas ne s'est PAS
    /// reproduit : 12 écritures suivies d'une lecture immédiate ont toutes abouti, avec la
    /// protection temps réel active. Le blocage est donc réel mais INTERMITTENT — sans
    /// doute la première rencontre avec un échantillon, le temps d'une interrogation du
    /// nuage, les suivantes étant servies par un cache local.
    ///
    /// Ce qui compte pour nous : quand cela arrive, ClamAV n'annonce pas une menace, il
    /// échoue à LIRE — et son « Infected files: 0 » ne veut alors rien dire. Le prendre
    /// pour une erreur technique banale serait un contresens. D'où le message dédié, et
    /// l'exclusion des lecteurs amovibles conseillée dans la notice.
    ///
    /// Ces lignes sont des « Warning », pas des « Error » : le filtre sur ERROR ne les
    /// voyait pas, et le message rendu était « cause inconnue ».
    /// </summary>
    private static int Bloques(string sortie) =>
        Regex.Matches(sortie, @"Can't open file .+?: 225\b").Count;

    private static string? PremiereErreur(string sortie)
    {
        var m = Regex.Match(sortie, @"^(?:ERROR|LibClamAV (?:Error|Warning)|WARNING):\s*(?<m>.+)$",
            RegexOptions.Multiline);
        return m.Success ? m.Groups["m"].Value.Trim() : null;
    }

    /// <summary>
    /// Rapatrie la base virale depuis la passerelle. C'est freshclam qui tourne là-bas :
    /// la station ne parle jamais aux miroirs ClamAV, et n'a donc pas besoin d'Internet.
    /// </summary>
    public async Task<(bool ok, string message)> MettreAJourAsync(CancellationToken jeton)
    {
        if (TrouverExe() == null) return (false, "ClamAV n'est pas installé sur ce poste.");
        var r = await _api.SynchroniserBaseAsync(DossierBase, jeton);

        // Le démon tient sa base EN MÉMOIRE : sans relecture, il continuerait d'analyser
        // avec les signatures d'avant la mise à jour — et la station afficherait fièrement
        // une date de base qu'elle n'utilise pas. « SelfCheck » finirait par le faire seul,
        // mais jusqu'à cinq minutes plus tard.
        if (r.ok && _daemon is { HasExited: false })
        {
            var exe = Voisin("clamdscan.exe");
            var conf = Path.GetFullPath(Path.Combine(DossierBase, "..", "clamd.conf"));
            if (exe != null && File.Exists(conf))
                try { await ExecuterAsync(exe, $"--config-file=\"{conf}\" --reload", jeton); } catch { }
        }
        return r;
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
