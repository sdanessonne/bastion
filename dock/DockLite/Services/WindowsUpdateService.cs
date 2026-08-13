using System;
using System.Collections.Generic;
using System.Linq;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text.RegularExpressions;

namespace DockLite.Services;

/// <summary>
/// Énumère les mises à jour Windows en attente via l'API COM
/// "Microsoft.Update.Session" (= équivalent natif de Get-WindowsUpdate
/// sans dépendre du module PSWindowsUpdate).
///
/// Filtre standard utilisé par Windows Update (UI Settings) :
///     IsInstalled=0 and Type='Software' and IsHidden=0
///
/// Aucune écriture, juste de la lecture. Pour installer, on utilise un
/// script PowerShell distinct côté agent en SYSTEM (cf Install endpoint).
/// </summary>
[SupportedOSPlatform("windows")]
public static class WindowsUpdateService
{
    public class UpdateItem
    {
        public string Id              { get; set; } = "";
        public string? KbNumber       { get; set; }
        public string Title           { get; set; } = "";
        public string? Description    { get; set; }
        public string? Severity       { get; set; }    // Critical|Important|Moderate|Low|Unspecified
        public string? Category       { get; set; }
        public int? SizeMb            { get; set; }
        public bool IsMandatory       { get; set; }
        public bool IsDownloaded      { get; set; }
        public bool RequiresReboot    { get; set; }
        public bool AutoSelect        { get; set; }
        public string? DeploymentAction { get; set; }
        public DateTime? LastDeploymentChange { get; set; }
    }

    public class Result
    {
        public bool Ok                     { get; set; }
        public string? Error               { get; set; }
        public bool RebootPendingFromOs    { get; set; }   // un reboot Windows est déjà en attente
        public DateTime ScannedAt          { get; set; } = DateTime.Now;
        public List<UpdateItem> Updates    { get; set; } = new();
    }

    public class HistoryEntry
    {
        public DateTime InstallDate { get; set; }
        public string   Title       { get; set; } = "";
        public string?  KbNumber    { get; set; }
        public string   Operation   { get; set; } = "Other";    // Installation|Uninstallation|Other
        public string   ResultCode  { get; set; } = "NotStarted";// Succeeded|SucceededWithErrors|Failed|Aborted|InProgress|NotStarted
        public string?  HResult     { get; set; }
        public string?  Description { get; set; }
        public string?  SupportUrl  { get; set; }
        public string?  UpdateId    { get; set; }
    }

    /// <summary>
    /// Scan synchrone des mises à jour. Peut prendre plusieurs minutes la 1ère
    /// fois (téléchargement du catalogue depuis WSUS/Microsoft). À appeler en
    /// background uniquement.
    /// </summary>
    public static Result Fetch(int timeoutSec = 180)
    {
        var res = new Result();

        try
        {
            res.RebootPendingFromOs = IsOsRebootPending();

            var sessionType = Type.GetTypeFromProgID("Microsoft.Update.Session", true);
            if (sessionType == null) { res.Error = "COM Microsoft.Update.Session indisponible"; return res; }

            dynamic session = Activator.CreateInstance(sessionType)!;
            session.ClientApplicationID = "DockPolice";

            dynamic searcher = session.CreateUpdateSearcher();
            searcher.ServerSelection = 0;             // 0 = ssDefault (WSUS si configuré, sinon Microsoft Update)
            // searcher.IncludePotentiallySupersededUpdates = false; // défaut

            // Recherche bloquante (limitée par timeoutSec côté thread parent — l'API
            // COM elle-même n'a pas de timeout natif, mais on l'exécute en Task)
            var task = System.Threading.Tasks.Task.Run<object?>(() =>
            {
                return searcher.Search("IsInstalled=0 and Type='Software' and IsHidden=0");
            });

            if (!task.Wait(TimeSpan.FromSeconds(Math.Max(30, timeoutSec))))
            {
                res.Error = $"Search timeout (>{timeoutSec}s) — WU service occupé ou hors-ligne";
                return res;
            }

            dynamic searchResult = task.Result!;
            int n = (int)searchResult.Updates.Count;
            for (int i = 0; i < n; i++)
            {
                try
                {
                    dynamic u = searchResult.Updates.Item(i);
                    res.Updates.Add(MapUpdate(u));
                }
                catch { /* item suivant */ }
            }

            res.Ok = true;
        }
        catch (COMException ex)
        {
            // Codes d'erreur fréquents :
            //   0x8024401C : ServerName non résolu (DNS WSUS KO)
            //   0x80244022 : connexion HTTP refusée (WSUS down)
            //   0x80244019 : pas de mises à jour applicables
            res.Error = $"COM 0x{ex.HResult:X8} : {ex.Message}";
        }
        catch (Exception ex)
        {
            res.Error = ex.Message;
        }

        return res;
    }

    private static UpdateItem MapUpdate(dynamic u)
    {
        var item = new UpdateItem
        {
            Id          = (string)u.Identity.UpdateID,
            Title       = (string)u.Title,
            Description = TryGetString(() => (string)u.Description),
            IsMandatory = (bool)u.IsMandatory,
            IsDownloaded= (bool)u.IsDownloaded,
            AutoSelect  = (bool)u.AutoSelectOnWebSites,
        };

        // KB number — extraction depuis KBArticleIDs (collection)
        try
        {
            int kbCount = (int)u.KBArticleIDs.Count;
            if (kbCount > 0)
            {
                var kb = (string)u.KBArticleIDs.Item(0);
                if (!string.IsNullOrEmpty(kb)) item.KbNumber = "KB" + kb;
            }
        }
        catch { }
        // Fallback : extraction depuis le titre (les MAJ Windows ont presque toujours [KBxxxx])
        if (string.IsNullOrEmpty(item.KbNumber))
        {
            var m = Regex.Match(item.Title, @"\bKB\d{6,8}\b", RegexOptions.IgnoreCase);
            if (m.Success) item.KbNumber = m.Value.ToUpperInvariant();
        }

        // Severité (msrcSeverity) : Critical | Important | Moderate | Low | "" (Unspecified)
        try
        {
            var sev = (string)u.MsrcSeverity;
            item.Severity = string.IsNullOrEmpty(sev) ? "Unspecified" : sev;
        }
        catch { item.Severity = "Unspecified"; }

        // Catégorie (1ère catégorie typée)
        try
        {
            int cnt = (int)u.Categories.Count;
            if (cnt > 0) item.Category = (string)u.Categories.Item(0).Name;
        }
        catch { }

        // Taille (somme de MaxDownloadSize en octets)
        try
        {
            ulong bytes = (ulong)u.MaxDownloadSize;
            if (bytes > 0) item.SizeMb = (int)Math.Ceiling(bytes / 1024.0 / 1024.0);
        }
        catch { }

        // Reboot
        try { item.RequiresReboot = (bool)u.InstallationBehavior.RebootBehavior != false
                                  && (int)u.InstallationBehavior.RebootBehavior > 0; }
        catch { }
        // Plus simple : bool RebootRequired (KB n'existe pas toujours)
        try { if ((bool)u.RebootRequired) item.RequiresReboot = true; } catch { }

        // Action déploiement (si fournie)
        try { item.DeploymentAction = (string)u.DeploymentAction.ToString(); } catch { }
        try
        {
            object dt = u.LastDeploymentChangeTime;
            if (dt is DateTime d) item.LastDeploymentChange = d;
            else if (dt != null && DateTime.TryParse(dt.ToString(), out var dd)) item.LastDeploymentChange = dd;
        }
        catch { }

        return item;
    }

    private static string? TryGetString(Func<string?> f)
    {
        try { return f(); } catch { return null; }
    }

    /// <summary>
    /// Énumère l'historique des installations Windows Update via
    /// <c>IUpdateSearcher.QueryHistory()</c> (équivalent du panneau
    /// "Paramètres → Windows Update → Historique des MAJ").
    ///
    /// Renvoie au plus <paramref name="maxEntries"/> entrées (les plus récentes),
    /// optionnellement bornées à <paramref name="daysBack"/> jours.
    /// </summary>
    public static List<HistoryEntry> FetchHistory(int maxEntries = 200, int daysBack = 365)
    {
        var list = new List<HistoryEntry>();
        var since = DateTime.Now.AddDays(-Math.Max(1, daysBack));

        try
        {
            var sessionType = Type.GetTypeFromProgID("Microsoft.Update.Session", true);
            if (sessionType == null) return list;

            dynamic session = Activator.CreateInstance(sessionType)!;
            session.ClientApplicationID = "DockPolice";

            dynamic searcher = session.CreateUpdateSearcher();
            int total = (int)searcher.GetTotalHistoryCount();
            if (total <= 0) return list;

            int want = Math.Min(maxEntries, total);
            dynamic history = searcher.QueryHistory(0, want);

            int n = (int)history.Count;
            for (int i = 0; i < n; i++)
            {
                try
                {
                    dynamic h = history.Item(i);

                    DateTime when;
                    try { when = (DateTime)h.Date; } catch { continue; }
                    if (when < since) continue;

                    var entry = new HistoryEntry
                    {
                        InstallDate = when,
                        Title       = TryGetString(() => (string)h.Title) ?? "",
                        Description = TryGetString(() => (string)h.Description),
                        SupportUrl  = TryGetString(() => (string)h.SupportUrl),
                        Operation   = MapOperation(TryGetInt(() => (int)h.Operation)),
                        ResultCode  = MapResult(TryGetInt(() => (int)h.ResultCode)),
                        UpdateId    = TryGetString(() => (string)h.UpdateIdentity.UpdateID),
                    };

                    // HRESULT en hexa lisible
                    try
                    {
                        int hr = (int)h.HResult;
                        if (hr != 0) entry.HResult = "0x" + hr.ToString("X8");
                    }
                    catch { }

                    // KB depuis le titre
                    if (!string.IsNullOrEmpty(entry.Title))
                    {
                        var m = Regex.Match(entry.Title, @"\bKB\d{6,8}\b", RegexOptions.IgnoreCase);
                        if (m.Success) entry.KbNumber = m.Value.ToUpperInvariant();
                    }

                    if (!string.IsNullOrEmpty(entry.Title))
                        list.Add(entry);
                }
                catch { /* entrée suivante */ }
            }
        }
        catch { /* COM indispo / WU service down → liste vide */ }

        // Tri décroissant par date pour cohérence (QueryHistory est censé l'être déjà)
        list.Sort((a, b) => b.InstallDate.CompareTo(a.InstallDate));
        return list;
    }

    private static int? TryGetInt(Func<int> f)
    {
        try { return f(); } catch { return null; }
    }

    private static string MapOperation(int? op) => op switch
    {
        1 => "Installation",
        2 => "Uninstallation",
        3 => "Other",
        _ => "Other"
    };

    private static string MapResult(int? r) => r switch
    {
        0 => "NotStarted",
        1 => "InProgress",
        2 => "Succeeded",
        3 => "SucceededWithErrors",
        4 => "Failed",
        5 => "Aborted",
        _ => "NotStarted"
    };

    /// <summary>
    /// Détecte si Windows demande déjà un redémarrage suite à un install précédent.
    /// (Sans cela on pousse toujours plus de MAJ alors que le poste DOIT redémarrer)
    /// </summary>
    private static bool IsOsRebootPending()
    {
        try
        {
            // Clé typique posée par CBS quand un install requiert reboot
            using var k1 = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending");
            if (k1 != null) return true;
        }
        catch { }

        try
        {
            using var k2 = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired");
            if (k2 != null) return true;
        }
        catch { }

        return false;
    }
}
