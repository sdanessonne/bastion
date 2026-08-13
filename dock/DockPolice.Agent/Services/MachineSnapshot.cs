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

namespace DockPolice.Agent.Services;

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

        // ----- Système / BIOS / UEFI -----
        public string Manufacturer       { get; set; } = "";
        public string Model              { get; set; } = "";
        public string SerialNumber       { get; set; } = "";
        public string ServiceTag         { get; set; } = "";
        public string BiosManufacturer   { get; set; } = "";
        public string BiosVersion        { get; set; } = "";
        public string BiosReleaseDate    { get; set; } = "";
        public string BiosMode           { get; set; } = "";
        public bool?  SecureBoot         { get; set; }
        public string TpmVersion         { get; set; } = "";
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

        try { CollectSystemAndBios(info); } catch { }

        return info;
    }

    private static void CollectSystemAndBios(StaticInfo info)
    {
        // Win32_ComputerSystem
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

        // Win32_BIOS
        try
        {
            using var s = new System.Management.ManagementObjectSearcher(
                "SELECT Manufacturer, SMBIOSBIOSVersion, ReleaseDate, SerialNumber FROM Win32_BIOS");
            foreach (System.Management.ManagementObject mo in s.Get())
            {
                info.BiosManufacturer = (mo["Manufacturer"]      as string ?? "").Trim();
                info.BiosVersion      = (mo["SMBIOSBIOSVersion"] as string ?? "").Trim();
                var rel = (mo["ReleaseDate"] as string ?? "").Trim();
                if (rel.Length >= 8
                    && int.TryParse(rel.Substring(0, 4), out int y)
                    && int.TryParse(rel.Substring(4, 2), out int mo2)
                    && int.TryParse(rel.Substring(6, 2), out int d))
                {
                    info.BiosReleaseDate = $"{y:D4}-{mo2:D2}-{d:D2}";
                }
                var sn = (mo["SerialNumber"] as string ?? "").Trim();
                if (!string.IsNullOrWhiteSpace(sn) && sn != "0"
                    && !sn.StartsWith("System Serial", StringComparison.OrdinalIgnoreCase))
                {
                    info.SerialNumber = sn;
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

        // Mode UEFI vs Legacy + Secure Boot
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\SecureBoot\State");
            if (key != null)
            {
                info.BiosMode = "UEFI";
                var val = key.GetValue("UEFISecureBootEnabled");
                if (val is int vi) info.SecureBoot = (vi == 1);
            }
            else
            {
                info.BiosMode = "Legacy";
            }
        }
        catch { }

        // TPM via Win32_Tpm
        try
        {
            var scope = new System.Management.ManagementScope(@"\\.\root\CIMV2\Security\MicrosoftTpm");
            scope.Connect();
            using var s = new System.Management.ManagementObjectSearcher(scope, new System.Management.ObjectQuery("SELECT * FROM Win32_Tpm"));
            foreach (System.Management.ManagementObject mo in s.Get())
            {
                var spec = (mo["SpecVersion"] as string ?? "").Trim();
                if (spec.Length > 0)
                    info.TpmVersion = spec.Split(',')[0].Trim();
                break;
            }
        }
        catch { }
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

    private static string GetMacs()
    {
        try
        {
            return string.Join(", ",
                NetworkInterface.GetAllNetworkInterfaces()
                    .Where(n => n.NetworkInterfaceType != NetworkInterfaceType.Loopback
                                && n.OperationalStatus == OperationalStatus.Up)
                    .Select(n => n.GetPhysicalAddress().ToString())
                    .Where(s => !string.IsNullOrEmpty(s))
                    .Select(s => string.Join(":", Enumerable.Range(0, s.Length / 2).Select(i => s.Substring(i * 2, 2))))
                    .Distinct());
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
