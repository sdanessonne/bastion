using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;

namespace DockLite.Services;

public static class SystemInfo
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

    public record InfoEntry(string Label, string Value);

    public static List<InfoEntry> Collect()
    {
        var list = new List<InfoEntry>
        {
            new("Nom de la machine", Environment.MachineName),
            new("Utilisateur", Environment.UserName),
            new("Domaine", Environment.UserDomainName),
            new("Système", RuntimeInformation.OSDescription),
            new("Architecture", RuntimeInformation.OSArchitecture.ToString()),
            new("Processeur", Environment.GetEnvironmentVariable("PROCESSOR_IDENTIFIER") ?? "—"),
            new("Cœurs logiques", Environment.ProcessorCount.ToString()),
            new("Mémoire vive", FormatMemory()),
            new("Adresse IP", GetIPv4s()),
            new("Adresse MAC", GetMacs()),
            new("Temps de fonctionnement", FormatUptime()),
            new(".NET", RuntimeInformation.FrameworkDescription),
        };
        return list;
    }

    private static string FormatMemory()
    {
        try
        {
            var mem = new MEMORYSTATUSEX { dwLength = (uint)Marshal.SizeOf<MEMORYSTATUSEX>() };
            if (!GlobalMemoryStatusEx(ref mem)) return "—";
            double totalGb = mem.ullTotalPhys / 1024d / 1024d / 1024d;
            double availGb = mem.ullAvailPhys / 1024d / 1024d / 1024d;
            return $"{totalGb:0.0} Go total ({availGb:0.0} Go libre, {mem.dwMemoryLoad}% utilisé)";
        }
        catch { return "—"; }
    }

    public static string? GetPrimaryIPv4() => GetCandidateIPv4s().FirstOrDefault();

    /// <summary>
    /// Retourne toutes les IPv4 utilisables, triées pour mettre en tête celles des
    /// interfaces réellement connectées au LAN (ayant une passerelle par défaut) et
    /// reléguer en fin celles des adaptateurs virtuels (VirtualBox/VMware/Hyper-V/WSL).
    /// À utiliser pour matcher un poste contre les plages IP de commissariat, car la
    /// « première IP non-APIPA » peut être une IP virtuelle sans aucun rapport.
    /// </summary>
    public static List<string> GetCandidateIPv4s()
    {
        var result = new List<(string ip, int score)>();
        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != OperationalStatus.Up) continue;
                if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;
                if (ni.NetworkInterfaceType == NetworkInterfaceType.Tunnel) continue;

                var props = ni.GetIPProperties();
                bool hasDefaultGw = props.GatewayAddresses
                    .Any(g => g.Address != null
                           && g.Address.AddressFamily == AddressFamily.InterNetwork
                           && !g.Address.Equals(IPAddress.Any));

                bool looksVirtual = LooksVirtual(ni.Name) || LooksVirtual(ni.Description);

                int score = 0;
                if (hasDefaultGw)  score += 100;    // vraie connexion réseau
                if (!looksVirtual) score += 20;     // adaptateur physique
                if (ni.NetworkInterfaceType == NetworkInterfaceType.Wireless80211) score += 5;
                if (ni.NetworkInterfaceType == NetworkInterfaceType.Ethernet)      score += 5;

                foreach (var addr in props.UnicastAddresses)
                {
                    if (addr.Address.AddressFamily != AddressFamily.InterNetwork) continue;
                    if (IPAddress.IsLoopback(addr.Address)) continue;
                    var s = addr.Address.ToString();
                    if (s.StartsWith("169.254.")) continue;
                    result.Add((s, score));
                }
            }
        }
        catch { }
        return result.OrderByDescending(t => t.score).Select(t => t.ip).ToList();
    }

    private static bool LooksVirtual(string name)
    {
        if (string.IsNullOrEmpty(name)) return false;
        var n = name.ToLowerInvariant();
        return n.Contains("virtualbox") || n.Contains("vmware") || n.Contains("hyper-v")
            || n.Contains("vethernet")  || n.Contains("wsl")    || n.Contains("docker")
            || n.Contains("tap-")       || n.Contains("loopback");
    }

    private static string GetIPv4s()
    {
        var results = new List<string>();

        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;

                IPInterfaceProperties props;
                try { props = ni.GetIPProperties(); } catch { continue; }

                foreach (var addr in props.UnicastAddresses)
                {
                    if (addr.Address.AddressFamily != AddressFamily.InterNetwork) continue;
                    var ip = addr.Address.ToString();
                    if (IPAddress.IsLoopback(addr.Address)) continue;
                    var label = $"{ip} ({ni.Name})";
                    if (!results.Contains(label)) results.Add(label);
                }
            }
        }
        catch { }

        if (results.Count == 0)
        {
            try
            {
                var host = Dns.GetHostEntry(Dns.GetHostName());
                foreach (var addr in host.AddressList)
                {
                    if (addr.AddressFamily == AddressFamily.InterNetwork
                        && !IPAddress.IsLoopback(addr))
                    {
                        var ip = addr.ToString();
                        if (!results.Contains(ip)) results.Add(ip);
                    }
                }
            }
            catch { }
        }

        return results.Count == 0 ? "—" : string.Join(", ", results);
    }

    private static string GetMacs()
    {
        try
        {
            var macs = NetworkInterface.GetAllNetworkInterfaces()
                .Where(n => n.OperationalStatus == OperationalStatus.Up
                            && n.NetworkInterfaceType != NetworkInterfaceType.Loopback
                            && n.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .Select(n => n.GetPhysicalAddress().ToString())
                .Where(m => !string.IsNullOrEmpty(m))
                .Select(m => string.Join(":", Enumerable.Range(0, m.Length / 2).Select(i => m.Substring(i * 2, 2))))
                .Distinct()
                .ToList();
            return macs.Count == 0 ? "—" : string.Join(", ", macs);
        }
        catch { return "—"; }
    }

    private static string FormatUptime()
    {
        var ms = Environment.TickCount64;
        var ts = TimeSpan.FromMilliseconds(ms);
        if (ts.TotalDays >= 1)
            return $"{(int)ts.TotalDays} j {ts.Hours} h {ts.Minutes} min";
        if (ts.TotalHours >= 1)
            return $"{(int)ts.TotalHours} h {ts.Minutes} min";
        return $"{(int)ts.TotalMinutes} min";
    }
}
