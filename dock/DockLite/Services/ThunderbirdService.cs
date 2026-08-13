using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using Microsoft.Win32;

namespace DockLite.Services;

/// <summary>
/// Détecte Thunderbird sur le poste, extrait les profils et leurs comptes mail,
/// et exécute les tâches de déploiement push depuis le backoffice.
/// </summary>
public static class ThunderbirdService
{
    public static string? BaseUrl { get; set; }
    public static string? ApiKey  { get; set; }

    public static bool IsConfigured =>
        !string.IsNullOrWhiteSpace(BaseUrl) && !string.IsNullOrWhiteSpace(ApiKey);

    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };

    // ============================================================
    // SCAN local : install + profils
    // ============================================================
    public class TbAccount
    {
        public string Email   { get; set; } = "";
        public string Type    { get; set; } = "";
        public string Imap    { get; set; } = "";
        public string Smtp    { get; set; } = "";
    }
    public class TbProfile
    {
        public string Name { get; set; } = "";
        public string Path { get; set; } = "";
        public bool   Default { get; set; }
        public List<TbAccount> Accounts { get; set; } = new();
    }
    public class TbInstall
    {
        public string InstallPath { get; set; } = "";
        public string Version     { get; set; } = "";
        public List<TbProfile> Profiles { get; set; } = new();
    }

    /// <summary>Scan complet Thunderbird (install + profils + comptes).</summary>
    public static TbInstall Scan()
    {
        var info = new TbInstall();
        info.InstallPath = LocateThunderbird();
        info.Version     = GetThunderbirdVersion(info.InstallPath);
        info.Profiles    = ScanProfiles();
        return info;
    }

    private static string LocateThunderbird()
    {
        var candidates = new[]
        {
            System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles),    "Mozilla Thunderbird", "thunderbird.exe"),
            System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "Mozilla Thunderbird", "thunderbird.exe"),
        };
        foreach (var p in candidates)
            if (!string.IsNullOrEmpty(p) && File.Exists(p)) return p;

        // Registre
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Mozilla\Mozilla Thunderbird");
            var version = key?.GetValue("CurrentVersion") as string;
            if (!string.IsNullOrEmpty(version))
            {
                using var sub = Registry.LocalMachine.OpenSubKey($@"SOFTWARE\Mozilla\Mozilla Thunderbird\{version}\Main");
                var path = sub?.GetValue("PathToExe") as string;
                if (!string.IsNullOrEmpty(path) && File.Exists(path)) return path;
            }
        } catch { }
        return "";
    }

    private static string GetThunderbirdVersion(string exePath)
    {
        if (string.IsNullOrEmpty(exePath) || !File.Exists(exePath)) return "";
        try { return System.Diagnostics.FileVersionInfo.GetVersionInfo(exePath).ProductVersion ?? ""; }
        catch { return ""; }
    }

    private static List<TbProfile> ScanProfiles()
    {
        var profiles = new List<TbProfile>();
        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        var profilesIni = System.IO.Path.Combine(appData, "Thunderbird", "profiles.ini");
        if (!File.Exists(profilesIni)) return profiles;

        try
        {
            // Parse profiles.ini (format INI Mozilla)
            var lines = File.ReadAllLines(profilesIni);
            TbProfile? cur = null;
            string section = "";
            foreach (var raw in lines)
            {
                var l = raw.Trim();
                if (l.StartsWith("[") && l.EndsWith("]"))
                {
                    if (cur != null && cur.Path.Length > 0) profiles.Add(cur);
                    section = l.Trim('[', ']');
                    cur = section.StartsWith("Profile") ? new TbProfile() : null;
                    continue;
                }
                if (cur == null || !l.Contains('=')) continue;
                var idx = l.IndexOf('=');
                var key = l.Substring(0, idx).Trim();
                var val = l.Substring(idx + 1).Trim();
                if (key.Equals("Name", StringComparison.OrdinalIgnoreCase)) cur.Name = val;
                else if (key.Equals("IsRelative", StringComparison.OrdinalIgnoreCase)) { /* géré via val */ }
                else if (key.Equals("Path", StringComparison.OrdinalIgnoreCase))
                {
                    // IsRelative=1 : relatif à AppData\Thunderbird
                    cur.Path = System.IO.Path.Combine(appData, "Thunderbird", val.Replace('/', System.IO.Path.DirectorySeparatorChar));
                }
                else if (key.Equals("Default", StringComparison.OrdinalIgnoreCase)) cur.Default = (val == "1");
            }
            if (cur != null && cur.Path.Length > 0) profiles.Add(cur);

            // Pour chaque profil : parse prefs.js → extraire comptes IMAP/SMTP
            foreach (var p in profiles)
            {
                var prefsJs = System.IO.Path.Combine(p.Path, "prefs.js");
                if (File.Exists(prefsJs)) p.Accounts = ParseAccountsFromPrefs(prefsJs);
            }
        }
        catch { /* best-effort */ }

        return profiles;
    }

    private static List<TbAccount> ParseAccountsFromPrefs(string prefsJs)
    {
        var accounts = new List<TbAccount>();
        try
        {
            var content = File.ReadAllText(prefsJs);
            // Format Mozilla : user_pref("mail.server.serverN.hostname", "mail.server.com");
            // user_pref("mail.identity.idN.useremail", "user@domain");
            var serverHosts  = new Dictionary<string, string>(); // serverN → hostname
            var serverTypes  = new Dictionary<string, string>(); // serverN → imap/pop3
            var identityMail = new Dictionary<string, string>(); // idN → email
            var idServer     = new Dictionary<string, string>(); // idN → serverN
            var smtpHosts    = new Dictionary<string, string>(); // smtpN → hostname
            var idSmtp       = new Dictionary<string, string>(); // idN → smtpN

            var rx = new System.Text.RegularExpressions.Regex(@"user_pref\(""([^""]+)"",\s*""?([^""\)]*)""?\);");
            foreach (System.Text.RegularExpressions.Match m in rx.Matches(content))
            {
                var key = m.Groups[1].Value;
                var val = m.Groups[2].Value;
                if (key.StartsWith("mail.server."))
                {
                    var parts = key.Split('.');
                    if (parts.Length < 4) continue;
                    var serverId = parts[2];
                    var prop     = parts[3];
                    if (prop == "hostname") serverHosts[serverId] = val;
                    else if (prop == "type")     serverTypes[serverId] = val;
                }
                else if (key.StartsWith("mail.identity."))
                {
                    var parts = key.Split('.');
                    if (parts.Length < 4) continue;
                    var idId = parts[2];
                    var prop = parts[3];
                    if (prop == "useremail") identityMail[idId] = val;
                    else if (prop == "smtpServer") idSmtp[idId] = val;
                }
                else if (key.StartsWith("mail.smtpserver."))
                {
                    var parts = key.Split('.');
                    if (parts.Length < 4) continue;
                    var smtpId = parts[2];
                    var prop   = parts[3];
                    if (prop == "hostname") smtpHosts[smtpId] = val;
                }
                else if (key.StartsWith("mail.account."))
                {
                    // mail.account.accountN.identities = "id1,id2"
                    // mail.account.accountN.server = "serverN"
                    var parts = key.Split('.');
                    if (parts.Length < 4) continue;
                    var accId = parts[2];
                    var prop  = parts[3];
                    if (prop == "server")
                    {
                        // map identities of this account to this server : besoin de croiser identities
                        // simplifié : on lie via l'identité plus bas
                    }
                }
            }

            // Reconstitue les comptes : pour chaque identité avec email, on essaie de retrouver son serveur
            // (heuristique simple : associe par numérotation)
            foreach (var kv in identityMail)
            {
                var idId = kv.Key;
                var email = kv.Value;
                var acc = new TbAccount { Email = email };

                // Cherche le serveur via mail.account.* — on prend le premier server qui a "useremail" matchant
                foreach (var serverKv in serverHosts)
                {
                    acc.Imap = serverKv.Value;
                    if (serverTypes.TryGetValue(serverKv.Key, out var t)) acc.Type = t;
                    break; // simpliste : prend le premier
                }
                if (idSmtp.TryGetValue(idId, out var smtpId) && smtpHosts.TryGetValue(smtpId, out var smtpHost))
                {
                    acc.Smtp = smtpHost;
                }
                else if (smtpHosts.Count > 0)
                {
                    acc.Smtp = smtpHosts.Values.First();
                }
                accounts.Add(acc);
            }
        }
        catch { }
        return accounts;
    }

    // ============================================================
    // REPORT vers backoffice
    // ============================================================
    public static async Task ReportAsync()
    {
        if (!IsConfigured) return;
        try
        {
            var info = Scan();
            var payload = new
            {
                machine      = Environment.MachineName,
                user         = Environment.UserName,
                install_path = info.InstallPath,
                version      = info.Version,
                profiles     = info.Profiles.Select(p => new {
                    name     = p.Name,
                    path     = p.Path,
                    @default = p.Default,
                    accounts = p.Accounts.Select(a => new { email = a.Email, type = a.Type, imap = a.Imap, smtp = a.Smtp }),
                }),
            };
            var json = JsonSerializer.Serialize(payload);
            using var content = new StringContent(json, Encoding.UTF8, "application/json");
            using var req = new HttpRequestMessage(HttpMethod.Post, BaseUrl!.TrimEnd('/') + "/api/thunderbird-report.php") { Content = content };
            req.Headers.Add("X-API-Key", ApiKey);
            using var _ = await _http.SendAsync(req);
        }
        catch { /* silencieux */ }
    }

    // ============================================================
    // POLL des tâches de déploiement
    // ============================================================
    public static async Task ProcessPendingDeploymentsAsync()
    {
        if (!IsConfigured) return;
        try
        {
            var url = BaseUrl!.TrimEnd('/') + "/api/thunderbird-deploy.php?machine=" + Uri.EscapeDataString(Environment.MachineName);
            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", ApiKey);
            using var resp = await _http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return;
            var body = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("tasks", out var arr)) return;

            foreach (var t in arr.EnumerateArray())
            {
                int taskId = t.GetProperty("id").GetInt32();
                string targetUser = t.TryGetProperty("target_user", out var tu) ? tu.GetString() ?? "" : "";

                // Filtre : si target_user spécifié, ne pas exécuter si on n'est pas le bon utilisateur
                if (!string.IsNullOrWhiteSpace(targetUser) &&
                    !string.Equals(targetUser, Environment.UserName, StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                string error = "";
                bool ok = false;
                try
                {
                    if (t.TryGetProperty("config", out var cfg))
                        ok = ApplyDeployment(cfg);
                }
                catch (Exception ex) { error = ex.Message; }

                // Reporte le résultat
                var result = JsonSerializer.Serialize(new
                {
                    task_id = taskId,
                    status  = ok ? "success" : "error",
                    error   = error,
                });
                using var rContent = new StringContent(result, Encoding.UTF8, "application/json");
                using var rReq = new HttpRequestMessage(HttpMethod.Post, BaseUrl!.TrimEnd('/') + "/api/thunderbird-deploy.php") { Content = rContent };
                rReq.Headers.Add("X-API-Key", ApiKey);
                try { using var _ = await _http.SendAsync(rReq); } catch { }
            }
        }
        catch { /* silencieux */ }
    }

    /// <summary>
    /// Applique une config (ajoute un compte au profil par défaut via prefs.js).
    /// Retourne true si OK.
    /// </summary>
    private static bool ApplyDeployment(JsonElement cfg)
    {
        var profiles = ScanProfiles();
        var defaultProfile = profiles.FirstOrDefault(p => p.Default) ?? profiles.FirstOrDefault();
        if (defaultProfile == null || string.IsNullOrEmpty(defaultProfile.Path)) return false;

        var prefsJs = System.IO.Path.Combine(defaultProfile.Path, "prefs.js");
        if (!File.Exists(prefsJs)) return false;

        // Sauvegarde
        var backupJs = prefsJs + ".dockpolice-backup-" + DateTime.Now.ToString("yyyyMMddHHmmss");
        File.Copy(prefsJs, backupJs);

        string Get(string k) => cfg.TryGetProperty(k, out var v) ? v.ToString() : "";
        var email      = Get("email");
        var displayN   = Get("display_name");
        var accType    = Get("account_type"); if (accType == "") accType = "imap";
        var incServer  = Get("incoming_server");
        var incPort    = Get("incoming_port");
        var incSec     = Get("incoming_security");
        var outServer  = Get("outgoing_server");
        var outPort    = Get("outgoing_port");
        var outSec     = Get("outgoing_security");

        if (string.IsNullOrWhiteSpace(email) || string.IsNullOrWhiteSpace(incServer) || string.IsNullOrWhiteSpace(outServer))
            return false;

        // Calcule des indices uniques (server4, identity4, smtp4, account4 — au-dessus des existants)
        var existing = File.ReadAllText(prefsJs);
        int nextIdx = 1;
        var rxIdx = new System.Text.RegularExpressions.Regex(@"mail\.server\.server(\d+)\.");
        foreach (System.Text.RegularExpressions.Match m in rxIdx.Matches(existing))
        {
            if (int.TryParse(m.Groups[1].Value, out int n) && n >= nextIdx) nextIdx = n + 1;
        }

        int sslInc = incSec == "ssl" ? 3 : (incSec == "starttls" ? 2 : 0);
        int sslOut = outSec == "ssl" ? 3 : (outSec == "starttls" ? 2 : 0);

        var newPrefs = new StringBuilder();
        newPrefs.AppendLine();
        newPrefs.AppendLine($"// === DockPolice deploy {DateTime.Now:yyyy-MM-dd HH:mm} : {email} ===");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.hostname\", \"{incServer}\");");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.port\", {incPort});");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.socketType\", {sslInc});");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.type\", \"{accType}\");");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.userName\", \"{email}\");");
        newPrefs.AppendLine($"user_pref(\"mail.server.server{nextIdx}.name\", \"{email}\");");
        newPrefs.AppendLine($"user_pref(\"mail.smtpserver.smtp{nextIdx}.hostname\", \"{outServer}\");");
        newPrefs.AppendLine($"user_pref(\"mail.smtpserver.smtp{nextIdx}.port\", {outPort});");
        newPrefs.AppendLine($"user_pref(\"mail.smtpserver.smtp{nextIdx}.try_ssl\", {sslOut});");
        newPrefs.AppendLine($"user_pref(\"mail.smtpserver.smtp{nextIdx}.username\", \"{email}\");");
        newPrefs.AppendLine($"user_pref(\"mail.identity.id{nextIdx}.useremail\", \"{email}\");");
        if (!string.IsNullOrEmpty(displayN))
            newPrefs.AppendLine($"user_pref(\"mail.identity.id{nextIdx}.fullName\", \"{displayN}\");");
        newPrefs.AppendLine($"user_pref(\"mail.identity.id{nextIdx}.smtpServer\", \"smtp{nextIdx}\");");
        newPrefs.AppendLine($"user_pref(\"mail.account.account{nextIdx}.identities\", \"id{nextIdx}\");");
        newPrefs.AppendLine($"user_pref(\"mail.account.account{nextIdx}.server\", \"server{nextIdx}\");");

        // Append à prefs.js (Thunderbird relit au prochain démarrage)
        File.AppendAllText(prefsJs, newPrefs.ToString());
        return true;
    }
}
