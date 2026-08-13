using System;
using System.Collections.Generic;
using System.Diagnostics.Eventing.Reader;
using System.IO;
using System.Linq;
using System.Runtime.Versioning;
using System.Xml;

namespace DockLite.Services;

/// <summary>
/// Lecture du journal d'événements Windows pour obtenir l'activité de l'utilisateur :
/// connexions/déconnexions, démarrages/arrêts, plantages d'applications, verrouillages
/// de compte, sessions RDP, etc.
///
/// Utilise EventLogReader (System.Diagnostics.Eventing) pour parser via XPath et
/// extraire les EventData XML.
///
/// Best-effort : aucune exception ne remonte, retourne une liste vide en cas d'erreur.
/// Lire le journal Security peut nécessiter d'être admin local — en non-admin,
/// les événements 4624/4625/etc. seront simplement absents du résultat.
/// </summary>
[SupportedOSPlatform("windows")]
public static class EventLogService
{
    public sealed class LogEvent
    {
        public DateTime Time         { get; set; }
        public string   Source       { get; set; } = "";   // Security / System / Application
        public int      EventId      { get; set; }
        public string   Provider     { get; set; } = "";
        public string   Level        { get; set; } = "";   // Information | Warning | Error | Critical | Verbose
        public string   Category     { get; set; } = "";   // logon | logoff | boot | shutdown | crash | account | rdp | other
        public string?  UserName     { get; set; }
        public string?  Computer     { get; set; }
        public string?  IpAddress    { get; set; }
        public string?  ProcessName  { get; set; }
        public string?  LogonType    { get; set; }
        public string   Summary      { get; set; } = "";   // 1-line description FR
        public string?  RawMessage   { get; set; }
    }

    public sealed class Summary
    {
        public int LogonsToday      { get; set; }
        public int LogonsWeek       { get; set; }
        public int FailedLogonsWeek { get; set; }
        public int LockoutsMonth    { get; set; }
        public int CrashesWeek      { get; set; }
        public int RebootsMonth     { get; set; }
        public DateTime? LastBoot       { get; set; }
        public DateTime? LastShutdown   { get; set; }
        public DateTime? LastLogon      { get; set; }
        public string?   LastLogonUser  { get; set; }
        public List<LogEvent> RecentLogons   { get; set; } = new();
        public List<LogEvent> RecentSystem   { get; set; } = new();
        public List<LogEvent> RecentCrashes  { get; set; } = new();
        public List<LogEvent> RecentLockouts { get; set; } = new();
        public DateTime FetchedAt { get; set; } = DateTime.Now;
    }

    /// <summary>
    /// Récupère un résumé de l'activité sur les N derniers jours.
    /// Si <paramref name="targetMachine"/> est null/vide/local, lit le journal LOCAL.
    /// Sinon utilise EventLogSession pour interroger la machine distante via RPC
    /// (requiert : accès réseau, membre du groupe "Event Log Readers" ou admin domaine).
    /// </summary>
    public static Summary GetSummary(int days = 7, int perCategoryLimit = 50, string? targetMachine = null)
    {
        var s = new Summary();
        var since   = DateTime.Now.AddDays(-Math.Max(1, days));
        var sinceTodayMidnight = DateTime.Today;
        var sinceMonth = DateTime.Now.AddDays(-30);

        // Session RPC vers la machine cible (null si local)
        EventLogSession? session = null;
        try
        {
            session = OpenRemoteSessionIfNeeded(targetMachine);
        }
        catch (Exception ex)
        {
            // Renvoie un summary vide avec une erreur explicite
            s.RecentSystem.Add(new LogEvent
            {
                Time = DateTime.Now, Source = "session", EventId = 0,
                Level = "Error", Category = "permission",
                Summary = $"Connexion à {targetMachine} impossible : {ex.Message}",
            });
            return s;
        }

        try
        {
            // ---- Sécurité (logon/logoff/lockout) ----
            var sec = ReadEvents("Security",
                $"*[System[(EventID=4624 or EventID=4625 or EventID=4634 or EventID=4647 or EventID=4740 or EventID=4625 or EventID=4672) and TimeCreated[@SystemTime>='{sinceMonth:yyyy-MM-ddTHH:mm:ss}']]]",
                limit: 500, session);

        foreach (var ev in sec)
        {
            switch (ev.EventId)
            {
                case 4624:
                    if (ev.Time >= since) s.RecentLogons.Add(ev);
                    if (ev.Time >= sinceTodayMidnight) s.LogonsToday++;
                    if (ev.Time >= since) s.LogonsWeek++;
                    if (s.LastLogon == null || ev.Time > s.LastLogon)
                    {
                        s.LastLogon = ev.Time;
                        s.LastLogonUser = ev.UserName;
                    }
                    break;
                case 4625:
                    if (ev.Time >= since) { s.RecentLogons.Add(ev); s.FailedLogonsWeek++; }
                    break;
                case 4634:
                case 4647:
                    if (ev.Time >= since) s.RecentLogons.Add(ev);
                    break;
                case 4740:
                    s.LockoutsMonth++;
                    s.RecentLockouts.Add(ev);
                    break;
            }
        }

        // ---- Système (boot / shutdown / kernel-power / crash) ----
        var sys = ReadEvents("System",
            $"*[System[(EventID=12 or EventID=13 or EventID=41 or EventID=1074 or EventID=6005 or EventID=6006 or EventID=6008) and TimeCreated[@SystemTime>='{sinceMonth:yyyy-MM-ddTHH:mm:ss}']]]",
            limit: 200, session);

        foreach (var ev in sys)
        {
            if (ev.Time >= since) s.RecentSystem.Add(ev);
            switch (ev.EventId)
            {
                case 12:
                case 6005:
                    if (s.LastBoot == null || ev.Time > s.LastBoot) s.LastBoot = ev.Time;
                    if (ev.Time >= sinceMonth) s.RebootsMonth++;
                    break;
                case 13:
                case 6006:
                case 1074:
                    if (s.LastShutdown == null || ev.Time > s.LastShutdown) s.LastShutdown = ev.Time;
                    break;
            }
        }

        // ---- Crashes applicatifs ----
        var crashes = ReadEvents("Application",
            $"*[System[(EventID=1000 or EventID=1001 or EventID=1002) and TimeCreated[@SystemTime>='{since:yyyy-MM-ddTHH:mm:ss}']]]",
            limit: perCategoryLimit, session);

        foreach (var ev in crashes)
        {
            s.RecentCrashes.Add(ev);
            s.CrashesWeek++;
        }

        // Tri du plus récent au plus ancien + cap
        s.RecentLogons   = s.RecentLogons.OrderByDescending(e => e.Time).Take(perCategoryLimit).ToList();
        s.RecentSystem   = s.RecentSystem.OrderByDescending(e => e.Time).Take(perCategoryLimit).ToList();
        s.RecentCrashes  = s.RecentCrashes.OrderByDescending(e => e.Time).Take(perCategoryLimit).ToList();
        s.RecentLockouts = s.RecentLockouts.OrderByDescending(e => e.Time).Take(20).ToList();

            return s;
        }
        finally
        {
            try { session?.Dispose(); } catch { }
        }
    }

    /// <summary>
    /// Récupère les événements bruts d'une catégorie donnée pour une machine.
    /// Si <paramref name="targetMachine"/> est fournie et n'est pas la machine locale,
    /// utilise EventLogSession pour interroger via RPC.
    /// </summary>
    public static List<LogEvent> GetEvents(string type, int days = 7, int limit = 200, string? targetMachine = null)
    {
        var since = DateTime.Now.AddDays(-Math.Max(1, days));
        var since_iso = since.ToString("yyyy-MM-ddTHH:mm:ss");
        EventLogSession? session = null;
        try
        {
            session = OpenRemoteSessionIfNeeded(targetMachine);
            return type.ToLowerInvariant() switch
            {
                "logon" => ReadEvents("Security",
                    $"*[System[(EventID=4624 or EventID=4625 or EventID=4634 or EventID=4647 or EventID=4648 or EventID=4672) and TimeCreated[@SystemTime>='{since_iso}']]]",
                    limit, session),
                "system" => ReadEvents("System",
                    $"*[System[(EventID=12 or EventID=13 or EventID=41 or EventID=1074 or EventID=6005 or EventID=6006 or EventID=6008) and TimeCreated[@SystemTime>='{since_iso}']]]",
                    limit, session),
                "crash" => ReadEvents("Application",
                    $"*[System[(EventID=1000 or EventID=1001 or EventID=1002) and TimeCreated[@SystemTime>='{since_iso}']]]",
                    limit, session),
                "lockout" => ReadEvents("Security",
                    $"*[System[EventID=4740 and TimeCreated[@SystemTime>='{since_iso}']]]",
                    limit, session),
                "rdp" => ReadEvents("Microsoft-Windows-TerminalServices-LocalSessionManager/Operational",
                    $"*[System[(EventID=21 or EventID=23 or EventID=24 or EventID=25) and TimeCreated[@SystemTime>='{since_iso}']]]",
                    limit, session),
                _ => new List<LogEvent>(),
            };
        }
        catch (Exception ex)
        {
            return new List<LogEvent> { new LogEvent {
                Time = DateTime.Now, Source = "session", EventId = 0,
                Level = "Error", Category = "permission",
                Summary = $"Lecture {targetMachine} impossible : {ex.Message}",
            }};
        }
        finally
        {
            try { session?.Dispose(); } catch { }
        }
    }

    /// <summary>
    /// Ouvre une session RPC vers la machine distante si nécessaire.
    /// Retourne null si la cible est null/vide ou est la machine locale.
    /// Utilise les credentials de l'utilisateur connecté (Kerberos/NTLM).
    /// </summary>
    private static EventLogSession? OpenRemoteSessionIfNeeded(string? targetMachine)
    {
        if (string.IsNullOrWhiteSpace(targetMachine)) return null;

        var local = Environment.MachineName;
        if (string.Equals(targetMachine, local, StringComparison.OrdinalIgnoreCase)
         || string.Equals(targetMachine, "localhost", StringComparison.OrdinalIgnoreCase)
         || string.Equals(targetMachine, "127.0.0.1", StringComparison.OrdinalIgnoreCase))
            return null;

        // Session avec authentification par défaut (Negotiate → Kerberos puis NTLM)
        return new EventLogSession(targetMachine);
    }

    // =========================================================================
    // Lecture brute via EventLogReader (local ou distant via session RPC)
    // =========================================================================
    private static List<LogEvent> ReadEvents(string logName, string xpath, int limit, EventLogSession? session = null)
    {
        var list = new List<LogEvent>();
        try
        {
            var query = new EventLogQuery(logName, PathType.LogName, xpath);
            if (session != null) query.Session = session;   // ← lecture distante via RPC
            using var reader = new EventLogReader(query);
            EventRecord? ev;
            int count = 0;
            while ((ev = reader.ReadEvent()) != null && count < limit)
            {
                try
                {
                    var le = ParseEvent(ev, logName);
                    if (le != null) { list.Add(le); count++; }
                }
                catch { /* skip malformed */ }
                finally { ev.Dispose(); }
            }
        }
        catch (UnauthorizedAccessException)
        {
            // Pour le journal Security : nécessite admin local
            list.Add(new LogEvent
            {
                Time = DateTime.Now, Source = logName, EventId = 0,
                Level = "Warning", Category = "permission",
                Summary = "Accès refusé au journal " + logName + " — DockLite doit être lancé en admin pour le journal Security.",
            });
        }
        catch { /* journal absent ou autre */ }
        return list;
    }

    private static LogEvent? ParseEvent(EventRecord ev, string source)
    {
        var le = new LogEvent
        {
            Time     = ev.TimeCreated ?? DateTime.Now,
            Source   = source,
            EventId  = ev.Id,
            Provider = ev.ProviderName ?? "",
            Level    = ev.LevelDisplayName ?? (ev.Level?.ToString() ?? ""),
        };

        // Récupération du message FR (peut échouer si MUI manquant)
        string msg = "";
        try { msg = ev.FormatDescription() ?? ""; } catch { }
        le.RawMessage = msg.Length > 4000 ? msg[..4000] : msg;

        // Parse EventData XML pour les attributs structurés
        try
        {
            var xml = ev.ToXml();
            if (!string.IsNullOrEmpty(xml))
            {
                var doc = new XmlDocument();
                doc.LoadXml(xml);
                var ns = new XmlNamespaceManager(doc.NameTable);
                ns.AddNamespace("e", "http://schemas.microsoft.com/win/2004/08/events/event");

                foreach (XmlNode dataNode in doc.SelectNodes("//e:Data", ns) ?? doc.GetElementsByTagName("Data").OfType<XmlNode>().ToList() as object as System.Collections.IEnumerable ?? new XmlNode[0])
                {
                    var name = dataNode.Attributes?["Name"]?.Value ?? "";
                    var val  = dataNode.InnerText ?? "";
                    if (string.IsNullOrEmpty(val)) continue;

                    switch (name)
                    {
                        case "TargetUserName":
                        case "SubjectUserName":
                            if (string.IsNullOrEmpty(le.UserName) && val != "-" && !val.EndsWith("$"))
                                le.UserName = val;
                            break;
                        case "WorkstationName":
                        case "Computer":
                            le.Computer = val;
                            break;
                        case "IpAddress":
                            if (val != "-" && val != "::1") le.IpAddress = val;
                            break;
                        case "LogonType":
                            le.LogonType = LogonTypeName(val);
                            break;
                        case "ProcessName":
                            le.ProcessName = val;
                            break;
                    }
                }
            }
        }
        catch { /* parse best-effort */ }

        // Catégorisation + summary FR
        (le.Category, le.Summary) = SummarizeEvent(le, msg);

        return le;
    }

    private static (string category, string summary) SummarizeEvent(LogEvent ev, string fullMsg)
    {
        switch (ev.EventId)
        {
            case 4624:
                var who = ev.UserName ?? "?";
                var lt  = ev.LogonType ?? "?";
                return ("logon", $"Connexion de {who} ({lt})" + (ev.IpAddress != null ? $" depuis {ev.IpAddress}" : ""));
            case 4625:
                return ("logon_fail", $"Échec connexion de {ev.UserName ?? '?'.ToString()}" + (ev.IpAddress != null ? $" depuis {ev.IpAddress}" : ""));
            case 4634:
                return ("logoff", $"Déconnexion de {ev.UserName ?? "?"}");
            case 4647:
                return ("logoff", $"Déconnexion utilisateur {ev.UserName ?? "?"}");
            case 4648:
                return ("logon", $"Connexion explicite (RunAs) — {ev.UserName ?? "?"}");
            case 4672:
                return ("logon_admin", $"Privilèges spéciaux assignés à {ev.UserName ?? "?"}");
            case 4740:
                return ("lockout", $"⚠ Compte verrouillé : {ev.UserName ?? "?"}");
            case 12:
            case 6005:
                return ("boot", "Démarrage du système");
            case 13:
            case 6006:
                return ("shutdown", "Arrêt propre du système");
            case 1074:
                var rebootUser = ExtractAfter(fullMsg, "Process", 200);
                return ("shutdown", $"Arrêt/redémarrage demandé par utilisateur");
            case 41:
                return ("crash", "⚠ Arrêt inattendu (Kernel-Power 41) — coupure ou plantage");
            case 6008:
                return ("crash", "⚠ Arrêt inattendu — système éteint sans procédure normale");
            case 1000:
                var app = ExtractAfter(fullMsg, "Application en échec :", 80) ?? ExtractAfter(fullMsg, "Faulting application name:", 80);
                return ("crash", $"Plantage application{(app != null ? " : " + app : "")}");
            case 1001:
                return ("crash", $"Rapport d'erreur Windows{(ev.ProcessName != null ? " : " + ev.ProcessName : "")}");
            case 1002:
                return ("crash", "Application figée (hang)");
            case 21:
                return ("rdp", $"Ouverture session RDP — {ev.UserName ?? "?"}");
            case 23:
                return ("rdp", $"Fermeture session RDP — {ev.UserName ?? "?"}");
            case 24:
                return ("rdp", $"Déconnexion RDP — {ev.UserName ?? "?"}");
            case 25:
                return ("rdp", $"Reconnexion RDP — {ev.UserName ?? "?"}");
            default:
                var firstLine = fullMsg.Split('\n').FirstOrDefault()?.Trim() ?? "";
                if (firstLine.Length > 200) firstLine = firstLine[..200];
                return ("other", firstLine);
        }
    }

    private static string LogonTypeName(string raw)
    {
        return raw switch
        {
            "2"  => "Interactive",
            "3"  => "Réseau",
            "4"  => "Batch",
            "5"  => "Service",
            "7"  => "Déverrouillage",
            "8"  => "NetworkCleartext",
            "9"  => "NewCredentials",
            "10" => "RemoteInteractive (RDP)",
            "11" => "CachedInteractive",
            _    => "Type " + raw,
        };
    }

    private static string? ExtractAfter(string text, string marker, int maxLen)
    {
        var idx = text.IndexOf(marker, StringComparison.OrdinalIgnoreCase);
        if (idx < 0) return null;
        var after = text[(idx + marker.Length)..].TrimStart(' ', ':', '\t');
        var endIdx = after.IndexOfAny(new[] { '\r', '\n' });
        if (endIdx > 0) after = after[..endIdx];
        return after.Length > maxLen ? after[..maxLen] : after.Trim();
    }
}
