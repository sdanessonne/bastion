using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Microsoft.Win32;

namespace DockLite.Services;

[SupportedOSPlatform("windows")]
public static class MachineSnapshot
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Auto)]
    private struct MEMORYSTATUSEX
    {
        public uint dwLength;
        public uint dwMemoryLoad;
        public ulong ullTotalPhys;
        public ulong ullAvailPhys;
        public ulong ullTotalPageFile;
        public ulong ullAvailPageFile;
        public ulong ullTotalVirtual;
        public ulong ullAvailVirtual;
        public ulong ullAvailExtendedVirtual;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Auto, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GlobalMemoryStatusEx(ref MEMORYSTATUSEX lpBuffer);

    public class StaticInfo
    {
        public string MachineName { get; set; } = "";
        public string UserName { get; set; } = "";
        public string OsVersion { get; set; } = "";
        public string Architecture { get; set; } = "";
        public string CpuName { get; set; } = "";
        public int CpuCores { get; set; }
        public long RamTotalMb { get; set; }
        public string DomainName { get; set; } = "";
        public string IpAddresses { get; set; } = "";
        public string MacAddresses { get; set; } = "";
        public long UptimeSeconds { get; set; }
        public string DotnetVersion { get; set; } = "";
        public List<DiskInfo> Disks { get; set; } = new();
        public List<InstalledApp> InstalledApps { get; set; } = new();
        public string AntivirusName { get; set; } = "";
        public string AntivirusState { get; set; } = "";
        public bool AntivirusEnabled { get; set; }
        public bool AntivirusUpToDate { get; set; }
        public string AntivirusSignatureDate { get; set; } = "";
        public int AntivirusRawState { get; set; }

        // ----- Détails Trellix Endpoint Security (si installé) -----
        public bool   TrellixDetected         { get; set; }
        public string TrellixEdition          { get; set; } = "";
        public string TrellixProductVersion   { get; set; } = "";
        public string TrellixAgentVersion     { get; set; } = "";
        public string TrellixEngineVersion    { get; set; } = "";
        public string TrellixContentVersion   { get; set; } = "";
        public string TrellixDatVersion       { get; set; } = "";
        public string TrellixDatDate          { get; set; } = "";
        public string TrellixLastFullScan     { get; set; } = "";
        public string TrellixLastQuickScan    { get; set; } = "";
        public string TrellixLastUpdate       { get; set; } = "";
        public string TrellixEpoServer        { get; set; } = "";
        public string TrellixEpoLastSync      { get; set; } = "";
        public string TrellixEpoAgentGuid     { get; set; } = "";
        public string TrellixInstallPath      { get; set; } = "";
        public bool   TrellixCriticalServicesUp { get; set; }
        public string TrellixServicesJson     { get; set; } = "[]";
        public int    TrellixRecentThreats    { get; set; }
        public string TrellixLastThreatName   { get; set; } = "";
        public string TrellixLastThreatDate   { get; set; } = "";

        // ============ Trellix Extended (Phase 2) ============
        public bool? TrellixOasEnabled                  { get; set; }
        public bool? TrellixExploitPreventionEnabled    { get; set; }
        public bool? TrellixSelfProtectionEnabled       { get; set; }
        public bool? TrellixBehaviorBlockingEnabled     { get; set; }
        public bool? TrellixWebControlEnabled           { get; set; }
        public bool? TrellixFirewallEnabled             { get; set; }
        public bool? TrellixAtpEnabled                  { get; set; }
        public bool? TrellixRealtimeScanEnabled         { get; set; }
        public string TrellixActivePolicyName           { get; set; } = "";
        public string TrellixPolicyAppliedAt            { get; set; } = "";
        public string TrellixUpdateRepository           { get; set; } = "";
        public string TrellixUpdateMethod               { get; set; } = "";
        public int? TrellixQuarantineCount              { get; set; }
        public long? TrellixQuarantineSizeBytes         { get; set; }
        public string TrellixQuarantinePath             { get; set; } = "";
        public List<TrellixInfo.ModuleInfo> TrellixModules { get; set; } = new();
        public int TrellixUpdateErrors7d                { get; set; }
        public int TrellixEpoErrors7d                   { get; set; }
        public string TrellixLastUpdateError            { get; set; } = "";
        public string TrellixLastEpoError               { get; set; } = "";
        public bool? TrellixGtiEnabled                  { get; set; }
        public string TrellixLastGtiCheck               { get; set; } = "";
        public int TrellixDetections7d                  { get; set; }
        public int TrellixDetections30d                 { get; set; }
        public int TrellixDetections90d                 { get; set; }

        // ----- Système / BIOS / UEFI -----
        public string Manufacturer       { get; set; } = "";   // Dell Inc., HP, LENOVO…
        public string Model              { get; set; } = "";   // OptiPlex 7090, EliteBook…
        public string SerialNumber       { get; set; } = "";   // n° de série système
        public string ServiceTag         { get; set; } = "";   // ServiceTag Dell (= SerialNumber)
        public string BiosManufacturer   { get; set; } = "";
        public string BiosVersion        { get; set; } = "";
        public string BiosReleaseDate    { get; set; } = "";   // YYYY-MM-DD
        public string BiosMode           { get; set; } = "";   // UEFI / Legacy
        public bool?  SecureBoot         { get; set; }
        public string TpmVersion         { get; set; } = "";

        // ----- Version DockPolice (auto, lue depuis l'assembly) -----
        public string AgentVersion       { get; set; } = "";

        // ----- Membres du groupe Administrateurs local -----
        // (poussé en JSON brut côté serveur pour DELETE+INSERT atomique de la table)
        public List<LocalAdminsService.MemberInfo> LocalAdmins { get; set; } = new();

        // ----- Mises à jour Windows en attente -----
        public List<WindowsUpdateService.UpdateItem> WindowsUpdates { get; set; } = new();
        public bool   WindowsRebootPending     { get; set; }
        public string WindowsUpdateScanError   { get; set; } = "";

        // ----- Historique des installations Windows Update -----
        public List<WindowsUpdateService.HistoryEntry> WindowsUpdateHistory { get; set; } = new();
    }

    public class DiskInfo
    {
        public string Drive { get; set; } = "";
        public string Label { get; set; } = "";
        public string Format { get; set; } = "";
        public long TotalGb { get; set; }
        public long FreeGb { get; set; }
    }

    public class InstalledApp
    {
        public string Name { get; set; } = "";
        public string Version { get; set; } = "";
        public string Publisher { get; set; } = "";
        public string InstallDate { get; set; } = "";
    }

    public static StaticInfo Collect()
    {
        var info = new StaticInfo
        {
            MachineName = Environment.MachineName,
            UserName = Environment.UserName,
            OsVersion = RuntimeInformation.OSDescription,
            Architecture = RuntimeInformation.OSArchitecture.ToString(),
            CpuName = GetCpuName(),
            CpuCores = Environment.ProcessorCount,
            DomainName = Environment.UserDomainName,
            UptimeSeconds = Environment.TickCount64 / 1000,
            DotnetVersion = RuntimeInformation.FrameworkDescription,
            IpAddresses = GetIPv4s(),
            MacAddresses = GetMacs(),
            RamTotalMb = GetRamTotalMb(),
            Disks = GetDisks(),
            InstalledApps = GetInstalledApps()
        };

        try
        {
            var av = AntivirusInfo.Get();
            info.AntivirusName = av.Name;
            info.AntivirusState = av.State;
            info.AntivirusEnabled = av.Enabled;
            info.AntivirusUpToDate = av.UpToDate;
            info.AntivirusSignatureDate = av.SignatureDate;
            info.AntivirusRawState = av.RawState;
        }
        catch { }

        // Détails Trellix (si installé) — best-effort, ne bloque pas le snapshot
        try
        {
            var t = TrellixInfo.Collect();
            info.TrellixDetected         = t.Detected;
            info.TrellixEdition          = t.Edition;
            info.TrellixProductVersion   = t.ProductVersion;
            info.TrellixAgentVersion     = t.AgentVersion;
            info.TrellixEngineVersion    = t.EngineVersion;
            info.TrellixContentVersion   = t.ContentVersion;
            info.TrellixDatVersion       = t.DatVersion;
            info.TrellixDatDate          = t.DatDate?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixLastFullScan     = t.LastFullScan?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixLastQuickScan    = t.LastQuickScan?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixLastUpdate       = t.LastUpdate?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixEpoServer        = t.EpoServer;
            info.TrellixEpoLastSync      = t.EpoLastSync?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixEpoAgentGuid     = t.EpoAgentGuid;
            info.TrellixInstallPath      = t.InstallPath;
            info.TrellixCriticalServicesUp = t.AllCriticalServicesUp;
            info.TrellixServicesJson     = System.Text.Json.JsonSerializer.Serialize(t.Services);

            // Phase 2 — extended fields
            info.TrellixOasEnabled                = t.OasEnabled;
            info.TrellixExploitPreventionEnabled  = t.ExploitPreventionEnabled;
            info.TrellixSelfProtectionEnabled     = t.SelfProtectionEnabled;
            info.TrellixBehaviorBlockingEnabled   = t.BehaviorBlockingEnabled;
            info.TrellixWebControlEnabled         = t.WebControlEnabled;
            info.TrellixFirewallEnabled           = t.FirewallEnabled;
            info.TrellixAtpEnabled                = t.AtpEnabled;
            info.TrellixRealtimeScanEnabled       = t.RealtimeScanEnabled;
            info.TrellixActivePolicyName          = t.ActivePolicyName;
            info.TrellixPolicyAppliedAt           = t.PolicyAppliedAt?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixUpdateRepository          = t.UpdateRepository;
            info.TrellixUpdateMethod              = t.UpdateMethod;
            info.TrellixQuarantineCount           = t.QuarantineCount;
            info.TrellixQuarantineSizeBytes       = t.QuarantineSizeBytes;
            info.TrellixQuarantinePath            = t.QuarantinePath;
            info.TrellixModules                   = t.Modules;
            info.TrellixUpdateErrors7d            = t.UpdateErrors7d;
            info.TrellixEpoErrors7d               = t.EpoErrors7d;
            info.TrellixLastUpdateError           = t.LastUpdateError;
            info.TrellixLastEpoError              = t.LastEpoError;
            info.TrellixGtiEnabled                = t.GtiEnabled;
            info.TrellixLastGtiCheck              = t.LastGtiCheck?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
            info.TrellixDetections7d              = t.Detections7d;
            info.TrellixDetections30d             = t.Detections30d;
            info.TrellixDetections90d             = t.Detections90d;
            info.TrellixRecentThreats    = t.RecentThreats;
            info.TrellixLastThreatName   = t.LastThreatName;
            info.TrellixLastThreatDate   = t.LastThreatDate?.ToString("yyyy-MM-dd HH:mm:ss") ?? "";
        }
        catch { }

        // BIOS / UEFI / Système (best-effort, n'impacte pas le snapshot si échec)
        try { CollectSystemAndBios(info); } catch { }

        // Version DockPolice (à partir de l'assembly courant)
        try
        {
            var asm = System.Reflection.Assembly.GetExecutingAssembly();
            var infoVer = (System.Reflection.AssemblyInformationalVersionAttribute?)
                System.Attribute.GetCustomAttribute(asm, typeof(System.Reflection.AssemblyInformationalVersionAttribute));
            var v = infoVer?.InformationalVersion ?? asm.GetName().Version?.ToString() ?? "";
            // Nettoie un éventuel "+commitsha"
            var plus = v.IndexOf('+');
            info.AgentVersion = plus > 0 ? v.Substring(0, plus) : v;
        }
        catch { }

        // Membres du groupe Administrateurs local (best-effort)
        try
        {
            var admins = LocalAdminsService.Fetch();
            if (admins.Ok) info.LocalAdmins = admins.Members;
        }
        catch { }

        // Mises à jour Windows en attente (best-effort, peut prendre 30-180s la
        // 1ère fois → on protège le snapshot contre un timeout)
        try
        {
            var wu = WindowsUpdateService.Fetch(timeoutSec: 180);
            info.WindowsUpdates         = wu.Updates;
            info.WindowsRebootPending   = wu.RebootPendingFromOs;
            info.WindowsUpdateScanError = wu.Error ?? "";
        }
        catch (Exception ex)
        {
            info.WindowsUpdateScanError = ex.Message;
        }

        // Historique des installations Windows Update (1 an, max 200 entrées)
        try { info.WindowsUpdateHistory = WindowsUpdateService.FetchHistory(200, 365); }
        catch { }

        return info;
    }

    private static void CollectSystemAndBios(StaticInfo info)
    {
        // Win32_ComputerSystem : Manufacturer, Model
        try
        {
            using var s = new System.Management.ManagementObjectSearcher("SELECT Manufacturer, Model FROM Win32_ComputerSystem");
            foreach (System.Management.ManagementObject mo in s.Get())
            {
                info.Manufacturer = (mo["Manufacturer"] as string ?? "").Trim();
                info.Model        = (mo["Model"] as string ?? "").Trim();
                break;
            }
        }
        catch { }

        // Win32_BIOS : Manufacturer, SMBIOSBIOSVersion, ReleaseDate, SerialNumber
        try
        {
            using var s = new System.Management.ManagementObjectSearcher(
                "SELECT Manufacturer, SMBIOSBIOSVersion, ReleaseDate, SerialNumber FROM Win32_BIOS");
            foreach (System.Management.ManagementObject mo in s.Get())
            {
                info.BiosManufacturer = (mo["Manufacturer"]      as string ?? "").Trim();
                info.BiosVersion      = (mo["SMBIOSBIOSVersion"] as string ?? "").Trim();
                var rel = (mo["ReleaseDate"] as string ?? "").Trim();
                if (rel.Length >= 8)
                {
                    // CIM date "YYYYMMDD..."
                    if (int.TryParse(rel.Substring(0,4), out int y)
                     && int.TryParse(rel.Substring(4,2), out int mo2)
                     && int.TryParse(rel.Substring(6,2), out int d))
                    {
                        info.BiosReleaseDate = $"{y:D4}-{mo2:D2}-{d:D2}";
                    }
                }
                var sn = (mo["SerialNumber"] as string ?? "").Trim();
                if (!string.IsNullOrWhiteSpace(sn) && sn != "0" && !sn.StartsWith("System Serial", StringComparison.OrdinalIgnoreCase))
                {
                    info.SerialNumber = sn;
                    // Pour les Dell, le SerialNumber EST le ServiceTag (7 caractères alphanumériques)
                    if (info.Manufacturer.IndexOf("Dell", StringComparison.OrdinalIgnoreCase) >= 0
                        && System.Text.RegularExpressions.Regex.IsMatch(sn, "^[A-Z0-9]{5,10}$"))
                    {
                        info.ServiceTag = sn;
                    }
                }
                break;
            }
        }
        catch { }

        // Mode UEFI vs Legacy (clé registre + variable firmware)
        try
        {
            // Méthode 1 : registre HKLM\SYSTEM\CurrentControlSet\Control\SecureBoot\State (présence = UEFI)
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\SecureBoot\State");
            if (key != null)
            {
                info.BiosMode = "UEFI";
                var val = key.GetValue("UEFISecureBootEnabled");
                if (val is int vi) info.SecureBoot = (vi == 1);
            }
            else
            {
                // Si pas de SecureBoot : Setupact.log est plus fiable, sinon par défaut Legacy
                info.BiosMode = "Legacy";
            }
        }
        catch { }

        // TPM version via WMI Win32_Tpm (namespace root\CIMV2\Security\MicrosoftTpm)
        try
        {
            var scope = new System.Management.ManagementScope(@"\\.\root\CIMV2\Security\MicrosoftTpm");
            scope.Connect();
            using var s = new System.Management.ManagementObjectSearcher(scope, new System.Management.ObjectQuery("SELECT * FROM Win32_Tpm"));
            foreach (System.Management.ManagementObject mo in s.Get())
            {
                var spec = (mo["SpecVersion"] as string ?? "").Trim();
                // ex : "2.0, 0, 1.16" — on prend la majeure
                if (spec.Length > 0)
                    info.TpmVersion = spec.Split(',')[0].Trim();
                break;
            }
        }
        catch { /* pas de TPM ou pas d'accès */ }
    }

    private static long GetRamTotalMb()
    {
        try
        {
            var mem = new MEMORYSTATUSEX { dwLength = (uint)Marshal.SizeOf<MEMORYSTATUSEX>() };
            return GlobalMemoryStatusEx(ref mem) ? (long)(mem.ullTotalPhys / 1024 / 1024) : 0L;
        }
        catch { return 0L; }
    }

    private static string GetCpuName()
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(@"HARDWARE\DESCRIPTION\System\CentralProcessor\0");
            if (key?.GetValue("ProcessorNameString") is string name)
                return name.Trim();
        }
        catch { }
        return Environment.GetEnvironmentVariable("PROCESSOR_IDENTIFIER") ?? "";
    }

    private static string GetIPv4s()
    {
        try
        {
            return string.Join(", ",
                NetworkInterface.GetAllNetworkInterfaces()
                    .Where(n => n.NetworkInterfaceType != NetworkInterfaceType.Loopback)
                    .SelectMany(n => n.GetIPProperties().UnicastAddresses)
                    .Where(a => a.Address.AddressFamily == AddressFamily.InterNetwork
                                && !IPAddress.IsLoopback(a.Address))
                    .Select(a => a.Address.ToString())
                    .Distinct());
        }
        catch { return ""; }
    }

    // Mots-clés identifiant les adaptateurs virtuels/transitoires à exclure : ils
    // apparaissent/disparaissent (VM démarrée, Wi-Fi Direct, VPN connecté…) et
    // faussaient la détection de « changement matériel ».
    private static readonly string[] VirtualNicKeywords =
    {
        "virtual", "vmware", "hyper-v", "vethernet", "vbox", "virtualbox",
        "vpn", "tap-", "tunnel", "loopback", "pseudo", "bluetooth",
        "wan miniport", "docker", "wsl", "wi-fi direct", "npcap", "wintun",
        "teredo", "isatap", "6to4"
    };

    private static string GetMacs()
    {
        try
        {
            // On capture les cartes PHYSIQUES quel que soit leur état (Up/Down) :
            // une carte Wi-Fi éteinte conserve sa MAC permanente → set stable.
            var macs = NetworkInterface.GetAllNetworkInterfaces()
                .Where(n => n.NetworkInterfaceType != NetworkInterfaceType.Loopback
                            && n.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .Where(n =>
                {
                    var desc = (n.Description ?? "").ToLowerInvariant();
                    var name = (n.Name ?? "").ToLowerInvariant();
                    return !VirtualNicKeywords.Any(k => desc.Contains(k) || name.Contains(k));
                })
                .Select(n => n.GetPhysicalAddress().ToString())
                .Where(s => !string.IsNullOrEmpty(s) && s.Length >= 12)
                .Select(s => string.Join(":", Enumerable.Range(0, s.Length / 2).Select(i => s.Substring(i * 2, 2))))
                .Where(mac => mac != "00:00:00:00:00:00")
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(m => m, StringComparer.OrdinalIgnoreCase);

            return string.Join(", ", macs);
        }
        catch { return ""; }
    }

    private static List<DiskInfo> GetDisks()
    {
        var list = new List<DiskInfo>();
        try
        {
            foreach (var d in DriveInfo.GetDrives())
            {
                if (!d.IsReady) continue;
                if (d.DriveType != DriveType.Fixed) continue;
                list.Add(new DiskInfo
                {
                    Drive = d.Name,
                    Label = d.VolumeLabel ?? "",
                    Format = d.DriveFormat,
                    TotalGb = d.TotalSize / 1024 / 1024 / 1024,
                    FreeGb = d.AvailableFreeSpace / 1024 / 1024 / 1024,
                });
            }
        }
        catch { }
        return list;
    }

    private static List<InstalledApp> GetInstalledApps()
    {
        var apps = new List<InstalledApp>();
        var roots = new[]
        {
            (Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
            (Registry.LocalMachine, @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall"),
            (Registry.CurrentUser,  @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"),
        };

        foreach (var (root, path) in roots)
        {
            try
            {
                using var rk = root.OpenSubKey(path);
                if (rk == null) continue;
                foreach (var subName in rk.GetSubKeyNames())
                {
                    try
                    {
                        using var sub = rk.OpenSubKey(subName);
                        var name = sub?.GetValue("DisplayName") as string;
                        if (string.IsNullOrWhiteSpace(name)) continue;

                        // Filtrer les KB Windows et autres bruit
                        if (sub?.GetValue("SystemComponent") is int sc && sc == 1) continue;
                        if (sub?.GetValue("ParentKeyName") != null) continue;

                        apps.Add(new InstalledApp
                        {
                            Name = name,
                            Version = sub?.GetValue("DisplayVersion") as string ?? "",
                            Publisher = sub?.GetValue("Publisher") as string ?? "",
                            InstallDate = sub?.GetValue("InstallDate") as string ?? ""
                        });
                    }
                    catch { }
                }
            }
            catch { }
        }

        return apps
            .GroupBy(a => $"{a.Name}|{a.Version}")
            .Select(g => g.First())
            .OrderBy(a => a.Name, StringComparer.OrdinalIgnoreCase)
            .ToList();
    }
}
