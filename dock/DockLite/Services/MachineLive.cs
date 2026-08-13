using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace DockLite.Services;

[SupportedOSPlatform("windows")]
public static class MachineLive
{
    [StructLayout(LayoutKind.Sequential)]
    private struct FILETIME
    {
        public uint Low;
        public uint High;
        public ulong Value => ((ulong)High << 32) | Low;
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetSystemTimes(out FILETIME idleTime, out FILETIME kernelTime, out FILETIME userTime);

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

    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO
    {
        public uint cbSize;
        public uint dwTime;
    }

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetLastInputInfo(ref LASTINPUTINFO lii);

    [DllImport("user32.dll", SetLastError = true)]
    private static extern IntPtr OpenInputDesktop(uint dwFlags, bool fInherit, uint dwDesiredAccess);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseDesktop(IntPtr hDesktop);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetUserObjectInformation(IntPtr hObj, int nIndex, IntPtr pvInfo, uint nLength, out uint lpnLengthNeeded);

    private static ulong _prevIdle, _prevKernel, _prevUser;

    public class LiveSnapshot
    {
        public double CpuPercent { get; set; }
        public long RamUsedMb { get; set; }
        public long RamTotalMb { get; set; }
        public int IdleSeconds { get; set; }
        public bool IsLocked { get; set; }
        public string ActiveSession { get; set; } = "";
        public List<ProcInfo> Processes { get; set; } = new();
    }

    public class ProcInfo
    {
        public int Pid { get; set; }
        public string Name { get; set; } = "";
        public long MemoryMb { get; set; }
    }

    public static LiveSnapshot Capture()
    {
        return new LiveSnapshot
        {
            CpuPercent = GetCpuPercent(),
            RamUsedMb = GetRamUsedMb(out long total),
            RamTotalMb = total,
            IdleSeconds = GetIdleSeconds(),
            IsLocked = IsWorkstationLocked(),
            ActiveSession = $"{Environment.UserDomainName}\\{Environment.UserName}",
            Processes = GetTopProcesses(40)
        };
    }

    private static int GetIdleSeconds()
    {
        try
        {
            var lii = new LASTINPUTINFO { cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>() };
            if (!GetLastInputInfo(ref lii)) return -1;
            uint elapsed = (uint)Environment.TickCount - lii.dwTime;
            return (int)(elapsed / 1000);
        }
        catch { return -1; }
    }

    private static bool IsWorkstationLocked()
    {
        try
        {
            // OpenInputDesktop échoue si la session est verrouillée (Winlogon a la main)
            var h = OpenInputDesktop(0, false, 0x0001 /* DESKTOP_READOBJECTS */);
            if (h == IntPtr.Zero) return true;
            CloseDesktop(h);
            return false;
        }
        catch { return false; }
    }

    private static double GetCpuPercent()
    {
        try
        {
            if (!GetSystemTimes(out var idle, out var kernel, out var user)) return 0;
            var idleTicks = idle.Value;
            var kernelTicks = kernel.Value;
            var userTicks = user.Value;

            if (_prevKernel == 0)
            {
                // Premier appel : pas de référence -> retour 0, mémoriser
                _prevIdle = idleTicks;
                _prevKernel = kernelTicks;
                _prevUser = userTicks;
                return 0;
            }

            ulong idleDelta = idleTicks - _prevIdle;
            ulong kernelDelta = kernelTicks - _prevKernel;
            ulong userDelta = userTicks - _prevUser;

            _prevIdle = idleTicks;
            _prevKernel = kernelTicks;
            _prevUser = userTicks;

            ulong totalDelta = kernelDelta + userDelta;
            if (totalDelta == 0) return 0;
            double busy = (double)(totalDelta - idleDelta) / totalDelta * 100;
            return Math.Round(Math.Max(0, Math.Min(100, busy)), 1);
        }
        catch { return 0; }
    }

    private static long GetRamUsedMb(out long totalMb)
    {
        totalMb = 0;
        try
        {
            var mem = new MEMORYSTATUSEX { dwLength = (uint)Marshal.SizeOf<MEMORYSTATUSEX>() };
            if (!GlobalMemoryStatusEx(ref mem)) return 0;
            totalMb = (long)(mem.ullTotalPhys / 1024 / 1024);
            long usedMb = (long)((mem.ullTotalPhys - mem.ullAvailPhys) / 1024 / 1024);
            return usedMb;
        }
        catch { return 0; }
    }

    private static List<ProcInfo> GetTopProcesses(int top)
    {
        try
        {
            return Process.GetProcesses()
                .Select(p =>
                {
                    try
                    {
                        return new ProcInfo
                        {
                            Pid = p.Id,
                            Name = p.ProcessName,
                            MemoryMb = p.WorkingSet64 / 1024 / 1024
                        };
                    }
                    catch { return null; }
                })
                .Where(p => p != null && p!.MemoryMb > 0)
                .OrderByDescending(p => p!.MemoryMb)
                .Take(top)
                .Select(p => p!)
                .ToList();
        }
        catch { return new List<ProcInfo>(); }
    }
}
