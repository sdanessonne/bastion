using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Diagnostics.Eventing.Reader;
using System.IO;
using System.Linq;
using System.Runtime.Versioning;
using System.ServiceProcess;
using Microsoft.Win32;

namespace DockLite.Services;

/// <summary>
/// Récupération des informations détaillées de l'antivirus Trellix Endpoint Security
/// (anciennement McAfee Endpoint Security / VirusScan Enterprise).
///
/// Sources d'information consultées :
///   - Registre Windows : versions produit, moteur, DAT, ePO server
///   - Services Windows : état des services Trellix/McAfee critiques
///   - Programmes installés : Program Files\Trellix, Program Files\McAfee
///   - Journal d'événements Application : alertes ENS / threats détectés
///
/// Best-effort : aucune exception ne remonte, on renvoie ce qu'on a pu lire.
/// La PKI MI utilise Trellix ENS comme antivirus standardisé sur les postes agents.
/// </summary>
[SupportedOSPlatform("windows")]
public static class TrellixInfo
{
    public class Details
    {
        public bool   Detected         { get; set; }
        public string Edition          { get; set; } = "";   // "Trellix ENS" / "McAfee ENS" / "VirusScan"

        // Versions
        public string ProductVersion   { get; set; } = "";   // ex: 10.7.0
        public string AgentVersion     { get; set; } = "";   // Trellix Agent / McAfee Agent
        public string EngineVersion    { get; set; } = "";   // Scan engine
        public string ContentVersion   { get; set; } = "";   // Content / AMCore
        public string DatVersion       { get; set; } = "";   // DAT (definition file)
        public DateTime? DatDate       { get; set; }         // Date de publication des signatures

        // Activité / scans
        public DateTime? LastFullScan  { get; set; }
        public DateTime? LastQuickScan { get; set; }
        public DateTime? LastUpdate    { get; set; }         // Dernière MAJ DAT/Content

        // ePO (gestion centralisée)
        public string EpoServer        { get; set; } = "";   // hostname du serveur ePO
        public DateTime? EpoLastSync   { get; set; }
        public string EpoAgentGuid     { get; set; } = "";

        // Services
        public List<ServiceState> Services { get; set; } = new();
        public bool   AllCriticalServicesUp { get; set; }

        // Menaces détectées (journal Application, 30 derniers jours)
        public int    RecentThreats     { get; set; }
        public string LastThreatName    { get; set; } = "";
        public DateTime? LastThreatDate { get; set; }

        // Chemin d'installation
        public string InstallPath      { get; set; } = "";

        // ============ AJOUTS Phase 2 ============

        // État des protections actives (null = info indisponible)
        public bool? OasEnabled              { get; set; }
        public bool? ExploitPreventionEnabled{ get; set; }
        public bool? SelfProtectionEnabled   { get; set; }
        public bool? BehaviorBlockingEnabled { get; set; }
        public bool? WebControlEnabled       { get; set; }
        public bool? FirewallEnabled         { get; set; }
        public bool? AtpEnabled              { get; set; }
        public bool? RealtimeScanEnabled     { get; set; }

        // Politique active
        public string ActivePolicyName       { get; set; } = "";
        public DateTime? PolicyAppliedAt     { get; set; }

        // Repository de mise à jour
        public string UpdateRepository       { get; set; } = "";
        public string UpdateMethod           { get; set; } = "";

        // Quarantaine
        public int? QuarantineCount          { get; set; }
        public long? QuarantineSizeBytes     { get; set; }
        public string QuarantinePath         { get; set; } = "";

        // Modules installés (chacun avec sa version)
        public List<ModuleInfo> Modules      { get; set; } = new();

        // Erreurs récentes (lues dans event log Application, 7 derniers jours)
        public int    UpdateErrors7d         { get; set; }
        public int    EpoErrors7d            { get; set; }
        public string LastUpdateError        { get; set; } = "";
        public string LastEpoError           { get; set; } = "";

        // GTI / cloud reputation
        public bool? GtiEnabled              { get; set; }
        public DateTime? LastGtiCheck        { get; set; }

        // Stats détections sur fenêtres glissantes
        public int Detections7d              { get; set; }
        public int Detections30d             { get; set; }
        public int Detections90d             { get; set; }
    }

    public class ModuleInfo
    {
        public string Name     { get; set; } = "";   // "Threat Prevention", "Web Control"…
        public string Code     { get; set; } = "";   // "TP", "WC", "FW", "ATP"…
        public string Version  { get; set; } = "";
        public bool   Enabled  { get; set; }         // si on a pu déterminer
        public string Category { get; set; } = "";   // "Endpoint" / "Web" / "Network"
    }

    public class ServiceState
    {
        public string Name        { get; set; } = "";
        public string DisplayName { get; set; } = "";
        public string Status      { get; set; } = "";   // Running / Stopped / NotInstalled
    }

    /// <summary>
    /// Une détection de menace par Trellix (extraite du journal Application Windows).
    /// </summary>
    public class ThreatDetection
    {
        public DateTime DetectedAt    { get; set; }
        public string   ThreatName    { get; set; } = "";   // ex: "Trojan.Generic.30459"
        public string   ThreatType    { get; set; } = "";   // Virus | Trojan | PUP | Adware | Heuristic | Web-Threat
        public string   Severity      { get; set; } = "medium";
        public string   FilePath      { get; set; } = "";
        public string   FileHash      { get; set; } = "";   // MD5/SHA1/SHA256 si présent
        public string   ProcessName   { get; set; } = "";
        public string   UserContext   { get; set; } = "";
        public string   ActionTaken   { get; set; } = "";   // Cleaned | Deleted | Quarantined | Blocked | Allowed
        public string   DetectionSource { get; set; } = "";  // OAS | OnDemand | Real-time | Web-Control
        public string   RuleName      { get; set; } = "";
        public int      EventId       { get; set; }
        public string   RawMessage    { get; set; } = "";

        /// <summary>SHA-1 calculé localement pour la déduplication serveur.</summary>
        public string DedupHash { get; set; } = "";
    }

    // Services Trellix/McAfee surveillés (un sous-ensemble varie selon la version installée)
    private static readonly (string svc, string label, bool critical)[] TRELLIX_SERVICES =
    {
        // Trellix Endpoint Security (nouveau nommage)
        ("mfemms",          "Trellix Endpoint Security Management",     true ),
        ("mfevtps",         "Trellix Validation Trust Protection",      true ),
        ("mfeesp",          "Trellix Endpoint Security Platform",       true ),
        ("mfeavfk",         "Trellix Threat Prevention On-Access Scan", true ),
        ("mfetdik",         "Trellix Threat Prevention Driver",         false),
        // McAfee Agent / Trellix Agent
        ("McAfeeFramework", "McAfee Agent (Framework Service)",         true ),
        ("MasterFramework", "Trellix Agent (Master Framework)",         true ),
        // Web Control / Firewall
        ("mfewc",           "Trellix Web Control",                      false),
        ("mfefire",         "Trellix Firewall",                         false),
    };

    public static Details Collect()
    {
        var d = new Details();

        try
        {
            // 1. Détection produit + versions
            ReadProductInfo(d);

            // 2. DAT / Engine / Content
            ReadDatInfo(d);

            // 3. ePO server
            ReadEpoInfo(d);

            // 4. État des services
            ReadServices(d);

            // 5. Dernières scans
            ReadLastScans(d);

            // 6. Menaces détectées (event log)
            ReadRecentThreats(d);

            // 7. Chemins d'installation
            ReadInstallPath(d);

            // 8. Modules installés (Phase 2)
            try { ReadModules(d); } catch { }

            // 9. État des protections (Phase 2)
            try { ReadProtectionStatus(d); } catch { }

            // 10. Politique active + repository (Phase 2)
            try { ReadActivePolicy(d); } catch { }

            // 11. Quarantaine (Phase 2)
            try { ReadQuarantine(d); } catch { }

            // 12. Erreurs récentes (Phase 2)
            try { ReadRecentErrors(d); } catch { }

            // 13. Statistiques détections sur fenêtres glissantes (Phase 2)
            try { ReadDetectionStats(d); } catch { }

            // Synthèse
            d.AllCriticalServicesUp = d.Services
                .Where(s => TRELLIX_SERVICES.Any(t => t.svc == s.Name && t.critical))
                .All(s => s.Status == "Running");

            d.Detected = !string.IsNullOrEmpty(d.ProductVersion)
                      || !string.IsNullOrEmpty(d.AgentVersion)
                      || !string.IsNullOrEmpty(d.DatVersion)
                      || d.Services.Any(s => s.Status == "Running")
                      || !string.IsNullOrEmpty(d.InstallPath);
        }
        catch (Exception ex)
        {
            // Log silencieux, on retourne ce qu'on a
            try { File.AppendAllText(LogPath(), $"[{DateTime.Now:O}] Trellix collect: {ex.Message}\r\n"); } catch { }
        }

        return d;
    }

    // -----------------------------------------------------------------------
    private static void ReadProductInfo(Details d)
    {
        // Trellix ENS
        var k = OpenKey(@"SOFTWARE\Trellix\Endpoint Security")
             ?? OpenKey(@"SOFTWARE\McAfee\Endpoint\Common");
        if (k != null)
        {
            d.ProductVersion = (k.GetValue("Version") as string)
                            ?? (k.GetValue("ProductVersion") as string) ?? "";
            d.Edition = OpenKey(@"SOFTWARE\Trellix\Endpoint Security") != null
                        ? "Trellix Endpoint Security" : "McAfee Endpoint Security";
        }

        // VirusScan Enterprise (legacy)
        if (string.IsNullOrEmpty(d.ProductVersion))
        {
            var ks = OpenKey(@"SOFTWARE\McAfee\VSCore");
            if (ks != null)
            {
                d.ProductVersion = (ks.GetValue("szProductVer") as string) ?? "";
                d.Edition = "McAfee VirusScan Enterprise";
            }
        }

        // Agent (Trellix / McAfee)
        var ka = OpenKey(@"SOFTWARE\Network Associates\TVD\Shared Components\Framework")
              ?? OpenKey(@"SOFTWARE\Trellix\Agent")
              ?? OpenKey(@"SOFTWARE\McAfee\Agent");
        if (ka != null)
        {
            d.AgentVersion = (ka.GetValue("Version") as string)
                          ?? (ka.GetValue("AgentVersion") as string) ?? "";
        }
    }

    private static void ReadDatInfo(Details d)
    {
        // Engine + DAT (vue partagée par toutes les versions)
        string?[] engines =
        {
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("EngineVersionMajor") as string),
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("EngineVersion") as string),
            (OpenKey(@"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\Engine")?.GetValue("EngineVersion") as string),
        };
        d.EngineVersion = engines.FirstOrDefault(e => !string.IsNullOrWhiteSpace(e)) ?? "";

        string?[] dats =
        {
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("AVDatVersion") as string),
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("DatVersion") as string),
            (OpenKey(@"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\Engine")?.GetValue("DATVersion") as string),
            (OpenKey(@"SOFTWARE\McAfee\Endpoint\AV")?.GetValue("DATVersion") as string),
        };
        d.DatVersion = dats.FirstOrDefault(s => !string.IsNullOrWhiteSpace(s)) ?? "";

        // Date des signatures
        string?[] datDates =
        {
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("AVDatDate") as string),
            (OpenKey(@"SOFTWARE\McAfee\AVEngine")?.GetValue("DatDate") as string),
            (OpenKey(@"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\Engine")?.GetValue("DATDate") as string),
        };
        var datStr = datDates.FirstOrDefault(s => !string.IsNullOrWhiteSpace(s));
        if (!string.IsNullOrEmpty(datStr) && TryParseDate(datStr, out var dat))
            d.DatDate = dat;

        // Content / AMCore (signature engine plus moderne)
        var content = OpenKey(@"SOFTWARE\McAfee\Endpoint\AMCore")?.GetValue("ContentVersion") as string
                   ?? OpenKey(@"SOFTWARE\Trellix\Endpoint Security\AMCore")?.GetValue("ContentVersion") as string;
        if (!string.IsNullOrEmpty(content)) d.ContentVersion = content;
    }

    private static void ReadEpoInfo(Details d)
    {
        // ePO server : où l'agent reporte
        var k = OpenKey(@"SOFTWARE\Network Associates\TVD\Shared Components\Framework");
        if (k != null)
        {
            // ASCISServer = le serveur principal auquel l'agent se connecte
            d.EpoServer = (k.GetValue("ASCISServer") as string)
                       ?? (k.GetValue("ePOServerList") as string) ?? "";
            d.EpoAgentGuid = (k.GetValue("MachineGUID") as string)
                          ?? (k.GetValue("AgentGuid") as string) ?? "";

            // Dernière communication réussie avec ePO
            var lastConn = k.GetValue("LastASCITime") as string;
            if (!string.IsNullOrEmpty(lastConn) && TryParseDate(lastConn, out var dt))
                d.EpoLastSync = dt;
        }

        // Si ePOServerList contient plusieurs serveurs, on ne garde que le premier
        if (d.EpoServer.Contains('|'))
            d.EpoServer = d.EpoServer.Split('|')[0];
        if (d.EpoServer.Contains(','))
            d.EpoServer = d.EpoServer.Split(',')[0];
    }

    private static void ReadServices(Details d)
    {
        foreach (var (name, display, _) in TRELLIX_SERVICES)
        {
            var st = "NotInstalled";
            try
            {
                using var sc = new ServiceController(name);
                st = sc.Status.ToString();
            }
            catch { /* not installed */ }

            if (st != "NotInstalled")
            {
                d.Services.Add(new ServiceState
                {
                    Name        = name,
                    DisplayName = display,
                    Status      = st,
                });
            }
        }
    }

    private static void ReadLastScans(Details d)
    {
        // Trellix stocke les timestamps des derniers scans dans le registre
        var k = OpenKey(@"SOFTWARE\McAfee\Endpoint\Threat Prevention\OAS")
             ?? OpenKey(@"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\OAS");
        if (k != null)
        {
            var fullStr  = k.GetValue("LastFullScan") as string  ?? k.GetValue("LastFullScanTime") as string;
            var quickStr = k.GetValue("LastQuickScan") as string ?? k.GetValue("LastQuickScanTime") as string;
            if (!string.IsNullOrEmpty(fullStr)  && TryParseDate(fullStr, out var f))  d.LastFullScan  = f;
            if (!string.IsNullOrEmpty(quickStr) && TryParseDate(quickStr, out var q)) d.LastQuickScan = q;
        }

        // Dernière mise à jour DAT (depuis l'agent)
        var ku = OpenKey(@"SOFTWARE\McAfee\AutoUpdate")
              ?? OpenKey(@"SOFTWARE\Trellix\AutoUpdate");
        if (ku != null)
        {
            var lastUpdStr = ku.GetValue("LastUpdate") as string;
            if (!string.IsNullOrEmpty(lastUpdStr) && TryParseDate(lastUpdStr, out var u))
                d.LastUpdate = u;
        }

        // Fallback : la DatDate sert de référence si pas de LastUpdate
        if (d.LastUpdate == null && d.DatDate != null) d.LastUpdate = d.DatDate;
    }

    private static void ReadRecentThreats(Details d)
    {
        // Synthèse rapide pour l'affichage tableau de bord (count + dernier événement).
        // Pour la liste détaillée, voir CollectRecentThreats() qui parse chaque événement.
        var threats = CollectRecentThreats(30);
        d.RecentThreats = threats.Count;
        var latest = threats.OrderByDescending(t => t.DetectedAt).FirstOrDefault();
        if (latest != null)
        {
            d.LastThreatDate = latest.DetectedAt;
            d.LastThreatName = latest.ThreatName.Length > 200 ? latest.ThreatName[..200] : latest.ThreatName;
        }
    }

    // -----------------------------------------------------------------------
    // Lecture détaillée des détections — utilisé pour le reporting forensique
    // -----------------------------------------------------------------------

    /// <summary>
    /// Extrait du journal Application toutes les détections Trellix/McAfee
    /// sur les <paramref name="days"/> derniers jours, avec parsing complet
    /// (nom de menace, fichier, action, source, processus, sévérité, etc.).
    /// </summary>
    public static List<ThreatDetection> CollectRecentThreats(int days = 30)
    {
        var list = new List<ThreatDetection>();
        try
        {
            var since = DateTime.Now.AddDays(-Math.Max(1, days));
            var providers = new[] {
                "McAfee Endpoint Security",
                "Trellix Endpoint Security",
                "McAfeeAVFilter",
                "McAfeeEndpointSecurity",
                "McLogEvent",
                "Endpoint Security",
                "Threat Prevention",
            };
            var orProviders = string.Join(" or ",
                providers.Select(p => $"Provider/@Name='{p.Replace("'", "&apos;")}'"));
            var xpath = $"*[System[({orProviders}) and " +
                        $"TimeCreated[@SystemTime>='{since:yyyy-MM-ddTHH:mm:ss}']]]";
            var query = new EventLogQuery("Application", PathType.LogName, xpath);

            using var reader = new EventLogReader(query);
            EventRecord? ev;
            while ((ev = reader.ReadEvent()) != null)
            {
                try
                {
                    var t = ParseThreatEvent(ev);
                    if (t != null) list.Add(t);
                }
                catch { /* ignore une mauvaise ligne, on continue */ }
                finally { ev.Dispose(); }
            }
        }
        catch { /* pas d'accès au journal Application */ }

        return list;
    }

    private static ThreatDetection? ParseThreatEvent(EventRecord ev)
    {
        var t = new ThreatDetection
        {
            DetectedAt = ev.TimeCreated ?? DateTime.Now,
            EventId    = ev.Id,
        };

        // 1. Récupération du message complet
        string msg = "";
        try { msg = ev.FormatDescription() ?? ""; } catch { }
        t.RawMessage = msg.Length > 4000 ? msg[..4000] : msg;

        // 2. Heuristiques de filtrage : un événement Trellix n'est intéressant que
        //    s'il parle de détection. On laisse passer les EventID typiques de threat.
        var threatEventIds = new[] { 1024, 1027, 1028, 1029, 1030, 1031, 1032, 1095, 1096, 1097, 1098, 1099, 1100, 1101, 1119, 1120 };
        bool looksLikeThreat = threatEventIds.Contains(ev.Id)
            || ContainsAny(msg, "threat", "menace", "virus", "trojan", "malware", "infected",
                                "blocked", "quarantined", "detected", "détecté", "nettoyé", "supprimé");
        if (!looksLikeThreat) return null;

        // 3. Parse les champs (regex sur le message brut + EventData si présent)
        ParseFromMessage(msg, t);

        // 4. Parse les EventData XML (champs structurés Trellix ENS)
        ParseEventDataXml(ev, t);

        // 5. Calcul du hash de déduplication (SHA-1)
        t.DedupHash = ComputeSha1(
            $"{t.DetectedAt:o}|{t.ThreatName}|{t.FilePath}|{t.EventId}");

        // 6. Sévérité dérivée si non fournie
        if (string.IsNullOrEmpty(t.Severity) || t.Severity == "medium")
            t.Severity = DeriveSeverity(t);

        return t;
    }

    private static void ParseFromMessage(string msg, ThreatDetection t)
    {
        if (string.IsNullOrEmpty(msg)) return;
        var lines = msg.Split('\n').Select(l => l.Trim()).Where(l => l != "").ToList();

        // Patterns de récupération (insensitif à la casse)
        var patterns = new (string field, string regex)[]
        {
            ("threat_name",    @"(?:Threat\s*Name|Nom\s+de\s+la\s+menace|Detection\s*Name|Virus[ -]?Name|Détection)\s*[:=]\s*(.+)"),
            ("threat_type",    @"(?:Threat\s*Type|Type\s+de\s+menace|Detection\s*Type|Virus\s*Type)\s*[:=]\s*(.+)"),
            ("file_path",      @"(?:File|Fichier|Path|Chemin|File\s*Path|Object\s*Name|Target\s*Path|Source\s+Process\s+Path)\s*[:=]\s*(.+)"),
            ("file_hash",      @"(?:Hash|MD5|SHA1|SHA256|SHA-1|SHA-256)\s*[:=]\s*([0-9A-Fa-f]{32,64})"),
            ("process_name",   @"(?:Process|Processus|Source\s*Process|Target\s+Process\s+Name)\s*[:=]\s*(.+)"),
            ("user_context",   @"(?:User|Utilisateur|User\s*Name|Source\s*User\s*Name|Logged\s+On\s+User)\s*[:=]\s*(.+)"),
            ("action_taken",   @"(?:Action(?:\s+Taken)?|Action\s+entreprise|Result|Résultat|Disposition)\s*[:=]\s*(.+)"),
            ("detection_source",@"(?:Source|Detection\s*Source|Scan\s*Type|Type\s*de\s*scan|Analyzer\s*Detection)\s*[:=]\s*(.+)"),
            ("rule_name",      @"(?:Rule\s*Name|Règle|Policy\s*Name|Engine|Module)\s*[:=]\s*(.+)"),
        };
        foreach (var (field, rx) in patterns)
        {
            var m = System.Text.RegularExpressions.Regex.Match(msg, rx,
                System.Text.RegularExpressions.RegexOptions.IgnoreCase | System.Text.RegularExpressions.RegexOptions.Multiline);
            if (m.Success)
            {
                var val = m.Groups[1].Value.Trim().TrimEnd('.', ';');
                if (val.Length > 500) val = val[..500];
                switch (field)
                {
                    case "threat_name":     t.ThreatName     = val; break;
                    case "threat_type":     t.ThreatType     = val; break;
                    case "file_path":       t.FilePath       = val; break;
                    case "file_hash":       t.FileHash       = val.ToLower(); break;
                    case "process_name":    t.ProcessName    = val; break;
                    case "user_context":    t.UserContext    = val; break;
                    case "action_taken":    t.ActionTaken    = NormalizeAction(val); break;
                    case "detection_source":t.DetectionSource= NormalizeSource(val); break;
                    case "rule_name":       t.RuleName       = val; break;
                }
            }
        }

        // Si le nom de menace est vide, prend la 1re ligne significative
        if (string.IsNullOrEmpty(t.ThreatName) && lines.Count > 0)
        {
            var firstShort = lines.FirstOrDefault(l => l.Length < 200 && l.Length > 8) ?? lines[0];
            t.ThreatName = firstShort.Length > 250 ? firstShort[..250] : firstShort;
        }
    }

    private static void ParseEventDataXml(EventRecord ev, ThreatDetection t)
    {
        try
        {
            var xml = ev.ToXml();
            if (string.IsNullOrEmpty(xml)) return;

            // Trellix ENS expose souvent <Data Name="ThreatName">XXX</Data>
            var rx = new System.Text.RegularExpressions.Regex(
                @"<Data\s+Name=""([^""]+)""[^>]*>([^<]*)</Data>",
                System.Text.RegularExpressions.RegexOptions.IgnoreCase);
            foreach (System.Text.RegularExpressions.Match m in rx.Matches(xml))
            {
                var key = m.Groups[1].Value.Trim();
                var val = System.Net.WebUtility.HtmlDecode(m.Groups[2].Value).Trim();
                if (string.IsNullOrEmpty(val)) continue;

                switch (key.ToLowerInvariant())
                {
                    case "threatname":       case "detectionname":  case "virusname":
                        if (string.IsNullOrEmpty(t.ThreatName)) t.ThreatName = val; break;
                    case "threattype":       case "detectiontype":
                        if (string.IsNullOrEmpty(t.ThreatType)) t.ThreatType = val; break;
                    case "threatseverity":   case "severity":
                        t.Severity = NormalizeSeverity(val); break;
                    case "objectname":       case "filepath":       case "targetpath": case "filename":
                        if (string.IsNullOrEmpty(t.FilePath)) t.FilePath = val; break;
                    case "filehash":         case "md5":            case "sha1": case "sha256":
                        if (string.IsNullOrEmpty(t.FileHash)) t.FileHash = val.ToLower(); break;
                    case "sourceprocessname":case "processname":    case "targetprocessname":
                        if (string.IsNullOrEmpty(t.ProcessName)) t.ProcessName = val; break;
                    case "sourceusername":   case "username":       case "loggedonuser":
                        if (string.IsNullOrEmpty(t.UserContext)) t.UserContext = val; break;
                    case "actiontaken":      case "action":         case "disposition":
                        t.ActionTaken = NormalizeAction(val); break;
                    case "analyzerdetectionmethod": case "detectionsource": case "scantype":
                        t.DetectionSource = NormalizeSource(val); break;
                    case "rulename":         case "policyname":     case "engine":
                        if (string.IsNullOrEmpty(t.RuleName)) t.RuleName = val; break;
                }
            }
        }
        catch { }
    }

    private static string NormalizeAction(string s)
    {
        var l = s.ToLowerInvariant();
        if (l.Contains("clean"))    return "Cleaned";
        if (l.Contains("delete") || l.Contains("supprim")) return "Deleted";
        if (l.Contains("quarant")) return "Quarantined";
        if (l.Contains("block") || l.Contains("bloqu"))    return "Blocked";
        if (l.Contains("allow") || l.Contains("autoris"))  return "Allowed";
        if (l.Contains("no action") || l.Contains("aucune"))return "NoActionTaken";
        return s.Length > 80 ? s[..80] : s;
    }

    private static string NormalizeSource(string s)
    {
        var l = s.ToLowerInvariant();
        if (l.Contains("on-access") || l.Contains("oas") || l.Contains("temps réel") || l.Contains("realtime")) return "OAS";
        if (l.Contains("on-demand") || l.Contains("scheduled") || l.Contains("planif")) return "OnDemand";
        if (l.Contains("web") || l.Contains("url"))       return "Web-Control";
        if (l.Contains("firewall") || l.Contains("pare-feu")) return "Firewall";
        if (l.Contains("exploit"))                        return "Exploit-Prevention";
        return s.Length > 80 ? s[..80] : s;
    }

    private static string NormalizeSeverity(string s)
    {
        var l = s.ToLowerInvariant();
        if (l.Contains("critical") || l == "5" || l.Contains("crit")) return "critical";
        if (l.Contains("high")     || l == "4" || l.Contains("élevé")) return "high";
        if (l.Contains("medium")   || l == "3" || l.Contains("moyen")) return "medium";
        if (l.Contains("low")      || l == "2" || l.Contains("faible")) return "low";
        if (l.Contains("info")     || l == "1") return "info";
        return "medium";
    }

    private static string DeriveSeverity(ThreatDetection t)
    {
        var nameType = (t.ThreatName + " " + t.ThreatType).ToLowerInvariant();
        if (ContainsAny(nameType, "ransomware", "wiper", "rootkit", "backdoor", "rat", "stealer")) return "critical";
        if (ContainsAny(nameType, "trojan", "worm", "exploit", "spyware"))                          return "high";
        if (ContainsAny(nameType, "pup", "adware", "riskware", "potentially unwanted"))             return "low";
        if (ContainsAny(nameType, "heuristic", "generic", "suspicious"))                            return "medium";
        // Action-based fallback
        if (t.ActionTaken == "Allowed" || t.ActionTaken == "NoActionTaken")                        return "high";
        if (t.ActionTaken == "Blocked" || t.ActionTaken == "Quarantined")                          return "medium";
        return "medium";
    }

    private static bool ContainsAny(string haystack, params string[] needles)
    {
        var l = (haystack ?? "").ToLowerInvariant();
        return needles.Any(n => l.Contains(n.ToLowerInvariant()));
    }

    private static string ComputeSha1(string input)
    {
        using var sha = System.Security.Cryptography.SHA1.Create();
        var bytes = sha.ComputeHash(System.Text.Encoding.UTF8.GetBytes(input ?? ""));
        return Convert.ToHexString(bytes).ToLowerInvariant();
    }

    private static void ReadInstallPath(Details d)
    {
        string[] candidates =
        {
            @"C:\Program Files\Trellix\Endpoint Security",
            @"C:\Program Files\McAfee\Endpoint Security",
            @"C:\Program Files (x86)\McAfee\Endpoint Security",
            @"C:\Program Files (x86)\McAfee\Common Framework",
            @"C:\Program Files\Common Files\McAfee\Engine",
            @"C:\Program Files (x86)\McAfee\VirusScan Enterprise",
        };
        d.InstallPath = candidates.FirstOrDefault(Directory.Exists) ?? "";
    }

    // -----------------------------------------------------------------------
    private static RegistryKey? OpenKey(string subkey)
    {
        // Tente la vue 64-bit puis 32-bit (Wow6432Node)
        try { var k = Registry.LocalMachine.OpenSubKey(subkey); if (k != null) return k; } catch { }
        try { var k = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Wow6432Node\" + subkey.Substring("SOFTWARE\\".Length)); return k; } catch { return null; }
    }

    private static bool TryParseDate(string s, out DateTime dt)
    {
        s = (s ?? "").Trim();
        dt = DateTime.MinValue;
        if (string.IsNullOrEmpty(s)) return false;

        // Trellix stocke parfois "YYYY/MM/DD" ou "YYYY-MM-DD" ou en format Unix
        var formats = new[] { "yyyy/MM/dd", "yyyy-MM-dd", "MM/dd/yyyy", "dd/MM/yyyy",
                              "yyyy/MM/dd HH:mm:ss", "yyyy-MM-dd HH:mm:ss", "o" };
        if (DateTime.TryParseExact(s, formats, System.Globalization.CultureInfo.InvariantCulture,
                System.Globalization.DateTimeStyles.AssumeLocal, out dt)) return true;

        // Tentative parser générique (ISO + locale)
        if (DateTime.TryParse(s, System.Globalization.CultureInfo.InvariantCulture,
                System.Globalization.DateTimeStyles.AssumeLocal, out dt)) return true;

        // Unix timestamp ?
        if (long.TryParse(s, out var unix) && unix > 1_000_000_000 && unix < 4_000_000_000)
        {
            dt = DateTimeOffset.FromUnixTimeSeconds(unix).LocalDateTime;
            return true;
        }
        return false;
    }

    private static string LogPath()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "DockPolice");
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, "trellix.log");
    }

    // =====================================================================
    // PHASE 2 — Collecte étendue
    // =====================================================================

    // Modules / sous-produits Trellix connus, avec leur clé registre principale
    // (Trellix ENS est modulaire : TP, ATP, WC, FW… chacun avec sa version)
    private static readonly (string code, string name, string regPath, string category)[] TRELLIX_MODULES =
    {
        ("TP",       "Threat Prevention",                    @"SOFTWARE\McAfee\Endpoint\Threat Prevention",      "Endpoint"),
        ("TPv2",     "Threat Prevention",                    @"SOFTWARE\Trellix\Endpoint Security\Threat Prevention", "Endpoint"),
        ("ATP",      "Adaptive Threat Protection",           @"SOFTWARE\McAfee\Endpoint\ATP",                    "Endpoint"),
        ("ATPv2",    "Adaptive Threat Protection",           @"SOFTWARE\Trellix\Endpoint Security\ATP",          "Endpoint"),
        ("WC",       "Web Control",                          @"SOFTWARE\McAfee\Endpoint\Web Control",            "Web"),
        ("WCv2",     "Web Control",                          @"SOFTWARE\Trellix\Endpoint Security\Web Control",  "Web"),
        ("FW",       "Firewall",                             @"SOFTWARE\McAfee\Endpoint\Firewall",               "Network"),
        ("FWv2",     "Firewall",                             @"SOFTWARE\Trellix\Endpoint Security\Firewall",     "Network"),
        ("DLP",      "Data Loss Prevention",                 @"SOFTWARE\McAfee\DLP\Agent",                       "Data"),
        ("DLPv2",    "Data Loss Prevention",                 @"SOFTWARE\Trellix\DLP\Agent",                      "Data"),
        ("Solidcore","Application & Change Control",         @"SOFTWARE\McAfee\Solidcore",                       "Endpoint"),
        ("DriveEnc", "Drive Encryption",                     @"SOFTWARE\McAfee\Endpoint Encryption Manager",     "Encryption"),
        ("DriveEncT","Drive Encryption",                     @"SOFTWARE\Trellix\Drive Encryption",               "Encryption"),
    };

    private static void ReadModules(Details d)
    {
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var (code, name, regPath, category) in TRELLIX_MODULES)
        {
            try
            {
                using var k = OpenKey(regPath);
                if (k == null) continue;

                var version = (k.GetValue("Version") as string)
                           ?? (k.GetValue("ProductVersion") as string)
                           ?? (k.GetValue("ModuleVersion") as string)
                           ?? "";
                if (string.IsNullOrWhiteSpace(version)) continue;

                // Évite doublons (TP + TPv2 par exemple)
                if (!seen.Add(name)) continue;

                d.Modules.Add(new ModuleInfo
                {
                    Code     = code.Replace("v2", ""),
                    Name     = name,
                    Version  = version.Trim(),
                    Category = category,
                    Enabled  = true,    // si le module est installé, on le considère actif sauf info contraire
                });
            }
            catch { }
        }
    }

    private static void ReadProtectionStatus(Details d)
    {
        // OAS / On-Access Scan
        d.OasEnabled = ReadProtectionFlag(
            new[] {
                @"SOFTWARE\McAfee\Endpoint\Threat Prevention\OAS",
                @"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\OAS",
            },
            new[] { "Enabled", "OnAccessScanEnabled", "OASEnabled" });

        // Exploit Prevention
        d.ExploitPreventionEnabled = ReadProtectionFlag(
            new[] {
                @"SOFTWARE\McAfee\Endpoint\Threat Prevention\Exploit Prevention",
                @"SOFTWARE\Trellix\Endpoint Security\Threat Prevention\Exploit Prevention",
            },
            new[] { "Enabled", "ExploitEnabled" });

        // Self-Protection / Tamper
        d.SelfProtectionEnabled = ReadProtectionFlag(
            new[] {
                @"SOFTWARE\McAfee\Endpoint\Common\SelfProtection",
                @"SOFTWARE\Trellix\Endpoint Security\Common\SelfProtection",
                @"SOFTWARE\McAfee\Endpoint\Tamper Protection",
            },
            new[] { "Enabled", "SelfProtectionEnabled", "TamperProtectionEnabled" });

        // Behavior Blocking (Real Protect)
        d.BehaviorBlockingEnabled = ReadProtectionFlag(
            new[] {
                @"SOFTWARE\McAfee\Endpoint\Threat Prevention\BehaviorBlocking",
                @"SOFTWARE\McAfee\Endpoint\ATP\RealProtect",
                @"SOFTWARE\Trellix\Endpoint Security\ATP\RealProtect",
            },
            new[] { "Enabled" });

        // Web Control
        if (d.Modules.Any(m => m.Code == "WC"))
        {
            d.WebControlEnabled = ReadProtectionFlag(
                new[] {
                    @"SOFTWARE\McAfee\Endpoint\Web Control",
                    @"SOFTWARE\Trellix\Endpoint Security\Web Control",
                },
                new[] { "Enabled" }) ?? true;
        }

        // Firewall
        if (d.Modules.Any(m => m.Code == "FW"))
        {
            d.FirewallEnabled = ReadProtectionFlag(
                new[] {
                    @"SOFTWARE\McAfee\Endpoint\Firewall",
                    @"SOFTWARE\Trellix\Endpoint Security\Firewall",
                },
                new[] { "Enabled" }) ?? true;
        }

        // ATP (Adaptive Threat Protection)
        if (d.Modules.Any(m => m.Code == "ATP"))
        {
            d.AtpEnabled = ReadProtectionFlag(
                new[] {
                    @"SOFTWARE\McAfee\Endpoint\ATP",
                    @"SOFTWARE\Trellix\Endpoint Security\ATP",
                },
                new[] { "Enabled" }) ?? true;
        }

        // Real-time scan = OAS dans Trellix ENS
        d.RealtimeScanEnabled = d.OasEnabled;

        // GTI (Global Threat Intelligence) / Reputation cloud
        d.GtiEnabled = ReadProtectionFlag(
            new[] {
                @"SOFTWARE\McAfee\Endpoint\Common\GTI",
                @"SOFTWARE\McAfee\Endpoint\AMCore\GTI",
                @"SOFTWARE\Trellix\Endpoint Security\AMCore\GTI",
            },
            new[] { "Enabled" });

        var lastGtiStr = (OpenKey(@"SOFTWARE\McAfee\Endpoint\Common\GTI")?.GetValue("LastCheck") as string)
                      ?? (OpenKey(@"SOFTWARE\Trellix\Endpoint Security\AMCore\GTI")?.GetValue("LastCheck") as string);
        if (!string.IsNullOrEmpty(lastGtiStr) && TryParseDate(lastGtiStr, out var gti))
            d.LastGtiCheck = gti;
    }

    /// <summary>
    /// Lit un flag d'activation depuis plusieurs chemins/noms candidates.
    /// Retourne null si aucune source ne donne de réponse.
    /// </summary>
    private static bool? ReadProtectionFlag(string[] paths, string[] valueNames)
    {
        foreach (var path in paths)
        {
            try
            {
                using var k = OpenKey(path);
                if (k == null) continue;
                foreach (var name in valueNames)
                {
                    var v = k.GetValue(name);
                    if (v == null) continue;
                    if (v is int i)              return i != 0;
                    if (v is long l)             return l != 0;
                    if (v is string s)
                    {
                        s = s.Trim().ToLowerInvariant();
                        if (s == "1" || s == "true" || s == "yes" || s == "on" || s == "enabled") return true;
                        if (s == "0" || s == "false" || s == "no" || s == "off" || s == "disabled") return false;
                    }
                }
            }
            catch { }
        }
        return null;
    }

    private static void ReadActivePolicy(Details d)
    {
        var k = OpenKey(@"SOFTWARE\McAfee\Endpoint\Common\PolicyManagement")
             ?? OpenKey(@"SOFTWARE\Trellix\Endpoint Security\Common\PolicyManagement")
             ?? OpenKey(@"SOFTWARE\McAfee\Endpoint\Common");
        if (k != null)
        {
            d.ActivePolicyName = (k.GetValue("ActivePolicy") as string)
                              ?? (k.GetValue("PolicyName") as string)
                              ?? (k.GetValue("CurrentPolicy") as string) ?? "";
            var applied = (k.GetValue("LastPolicyApplied") as string)
                       ?? (k.GetValue("PolicyAppliedTime") as string);
            if (!string.IsNullOrEmpty(applied) && TryParseDate(applied, out var t))
                d.PolicyAppliedAt = t;
        }

        // Repository / méthode de mise à jour
        var ku = OpenKey(@"SOFTWARE\McAfee\AutoUpdate")
              ?? OpenKey(@"SOFTWARE\Trellix\AutoUpdate");
        if (ku != null)
        {
            d.UpdateRepository = (ku.GetValue("RepositoryName") as string)
                              ?? (ku.GetValue("ASCISServer") as string)
                              ?? (ku.GetValue("DefaultRepository") as string) ?? "";
            d.UpdateMethod     = (ku.GetValue("UpdateMethod") as string) ?? "";
        }
        if (string.IsNullOrEmpty(d.UpdateRepository) && !string.IsNullOrEmpty(d.EpoServer))
            d.UpdateRepository = "ePO: " + d.EpoServer;
    }

    private static void ReadQuarantine(Details d)
    {
        // Chemins typiques de la quarantaine Trellix/McAfee
        string[] candidates =
        {
            @"C:\Quarantine",
            @"C:\ProgramData\McAfee\Endpoint\Quarantine",
            @"C:\ProgramData\Trellix\Endpoint Security\Quarantine",
            @"C:\ProgramData\McAfee\VSE\Quarantine",
        };
        foreach (var path in candidates)
        {
            try
            {
                if (!Directory.Exists(path)) continue;
                d.QuarantinePath = path;
                long total = 0; int n = 0;
                foreach (var f in Directory.EnumerateFiles(path, "*", SearchOption.AllDirectories))
                {
                    try { total += new FileInfo(f).Length; n++; } catch { }
                    if (n > 5000) break; // safety
                }
                d.QuarantineCount = n;
                d.QuarantineSizeBytes = total;
                return;
            }
            catch { }
        }
    }

    private static void ReadRecentErrors(Details d)
    {
        try
        {
            var since = DateTime.Now.AddDays(-7);
            var providers = new[] {
                "McAfee Endpoint Security", "Trellix Endpoint Security",
                "McLogEvent", "McAfeeUpdater", "Trellix AutoUpdate",
                "McAfee Agent", "Trellix Agent",
            };
            var orProviders = string.Join(" or ",
                providers.Select(p => $"Provider/@Name='{p.Replace("'","&apos;")}'"));
            var xpath = $"*[System[({orProviders}) and Level<=3 and " +
                        $"TimeCreated[@SystemTime>='{since:yyyy-MM-ddTHH:mm:ss}']]]";
            var query = new EventLogQuery("Application", PathType.LogName, xpath);

            using var reader = new EventLogReader(query);
            EventRecord? ev;
            while ((ev = reader.ReadEvent()) != null)
            {
                using (ev)
                {
                    string msg = "";
                    try { msg = ev.FormatDescription() ?? ""; } catch { }
                    var msgLow = msg.ToLowerInvariant();

                    bool isUpdate = ContainsAny(msgLow, "update", "dat", "amcore", "content", "mise à jour");
                    bool isEpo    = ContainsAny(msgLow, "epo", "ascis", "agent", "communication", "sync");

                    if (isUpdate)
                    {
                        d.UpdateErrors7d++;
                        if (string.IsNullOrEmpty(d.LastUpdateError))
                            d.LastUpdateError = msg.Length > 500 ? msg[..500] : msg;
                    }
                    else if (isEpo)
                    {
                        d.EpoErrors7d++;
                        if (string.IsNullOrEmpty(d.LastEpoError))
                            d.LastEpoError = msg.Length > 500 ? msg[..500] : msg;
                    }
                }
            }
        }
        catch { }
    }

    private static void ReadDetectionStats(Details d)
    {
        // Fenêtres glissantes (réutilise CollectRecentThreats)
        try
        {
            var t90 = CollectRecentThreats(90);
            d.Detections90d = t90.Count;
            var now = DateTime.Now;
            d.Detections30d = t90.Count(t => t.DetectedAt >= now.AddDays(-30));
            d.Detections7d  = t90.Count(t => t.DetectedAt >= now.AddDays(-7));
        }
        catch { }
    }
}
