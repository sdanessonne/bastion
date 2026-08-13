using System;
using System.ComponentModel;
using System.IO;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace DockPolice.Agent.Services;

[SupportedOSPlatform("windows")]
public static class UserSessionLauncher
{
    // ====================================================================
    // Win32 imports
    // ====================================================================

    [DllImport("Wtsapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQueryUserToken(uint sessionId, out IntPtr token);

    [DllImport("Wtsapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSEnumerateSessions(
        IntPtr server, int reserved, int version,
        out IntPtr sessionInfo, out int count);

    [DllImport("Wtsapi32.dll")]
    private static extern void WTSFreeMemory(IntPtr memory);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseHandle(IntPtr handle);

    [DllImport("userenv.dll", SetLastError = true, CharSet = CharSet.Auto)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateEnvironmentBlock(out IntPtr env, IntPtr token, bool inherit);

    [DllImport("userenv.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DestroyEnvironmentBlock(IntPtr env);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateProcessAsUser(
        IntPtr token, string? applicationName, string? commandLine,
        IntPtr processAttributes, IntPtr threadAttributes,
        [MarshalAs(UnmanagedType.Bool)] bool inheritHandles,
        uint creationFlags, IntPtr environment, string? currentDirectory,
        ref STARTUPINFO startupInfo, out PROCESS_INFORMATION processInfo);

    [StructLayout(LayoutKind.Sequential)]
    private struct WTS_SESSION_INFO
    {
        public uint SessionId;
        [MarshalAs(UnmanagedType.LPStr)] public string pWinStationName;
        public WTS_CONNECTSTATE_CLASS State;
    }

    private enum WTS_CONNECTSTATE_CLASS
    {
        WTSActive = 0,
        WTSConnected = 1,
        WTSConnectQuery = 2,
        WTSShadow = 3,
        WTSDisconnected = 4,
        WTSIdle = 5,
        WTSListen = 6,
        WTSReset = 7,
        WTSDown = 8,
        WTSInit = 9
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct STARTUPINFO
    {
        public int cb;
        public string lpReserved;
        public string lpDesktop;
        public string lpTitle;
        public uint dwX;
        public uint dwY;
        public uint dwXSize;
        public uint dwYSize;
        public uint dwXCountChars;
        public uint dwYCountChars;
        public uint dwFillAttribute;
        public uint dwFlags;
        public ushort wShowWindow;
        public ushort cbReserved2;
        public IntPtr lpReserved2;
        public IntPtr hStdInput;
        public IntPtr hStdOutput;
        public IntPtr hStdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct PROCESS_INFORMATION
    {
        public IntPtr hProcess;
        public IntPtr hThread;
        public int dwProcessId;
        public int dwThreadId;
    }

    private const uint CREATE_UNICODE_ENVIRONMENT = 0x00000400;
    private const uint CREATE_NO_WINDOW           = 0x08000000;
    private const uint NORMAL_PRIORITY_CLASS      = 0x00000020;

    // ====================================================================
    // API publique
    // ====================================================================

    /// <summary>
    /// Lance un exécutable dans la session interactive d'un utilisateur connecté.
    /// Doit être appelé depuis un service tournant en SYSTEM.
    /// </summary>
    public static int LaunchInSession(uint sessionId, string exePath, string? args = null)
    {
        if (!File.Exists(exePath))
            throw new FileNotFoundException("Exécutable introuvable.", exePath);

        if (!WTSQueryUserToken(sessionId, out IntPtr userToken))
            throw new Win32Exception(Marshal.GetLastWin32Error(),
                "WTSQueryUserToken a échoué (utilisateur peut-être déconnecté).");

        try
        {
            IntPtr envBlock = IntPtr.Zero;
            CreateEnvironmentBlock(out envBlock, userToken, false);

            var si = new STARTUPINFO
            {
                cb = Marshal.SizeOf<STARTUPINFO>(),
                lpDesktop = @"winsta0\default"
            };

            string commandLine = string.IsNullOrEmpty(args) ? "" : " " + args;
            // CreateProcessAsUser veut commandLine writable — passer null + applicationName est OK
            // ou passer le chemin entre quotes dans commandLine

            uint flags = CREATE_UNICODE_ENVIRONMENT | NORMAL_PRIORITY_CLASS;

            string workingDir = Path.GetDirectoryName(exePath) ?? "";

            bool ok = CreateProcessAsUser(
                userToken,
                exePath,
                commandLine == "" ? null : commandLine,
                IntPtr.Zero, IntPtr.Zero, false,
                flags,
                envBlock,
                workingDir,
                ref si,
                out PROCESS_INFORMATION pi);

            int pid = ok ? pi.dwProcessId : -1;
            int err = ok ? 0 : Marshal.GetLastWin32Error();

            if (envBlock != IntPtr.Zero) DestroyEnvironmentBlock(envBlock);
            if (pi.hProcess != IntPtr.Zero) CloseHandle(pi.hProcess);
            if (pi.hThread != IntPtr.Zero) CloseHandle(pi.hThread);

            if (!ok) throw new Win32Exception(err, $"CreateProcessAsUser a échoué (code {err}).");

            return pid;
        }
        finally
        {
            CloseHandle(userToken);
        }
    }

    /// <summary>
    /// Énumère les sessions actuellement actives avec un utilisateur connecté.
    /// </summary>
    public static System.Collections.Generic.List<uint> GetActiveSessions()
    {
        var list = new System.Collections.Generic.List<uint>();

        if (!WTSEnumerateSessions(IntPtr.Zero, 0, 1, out IntPtr sessionInfo, out int count))
            return list;

        try
        {
            int dataSize = Marshal.SizeOf<WTS_SESSION_INFO>();
            for (int i = 0; i < count; i++)
            {
                IntPtr cur = IntPtr.Add(sessionInfo, i * dataSize);
                var s = Marshal.PtrToStructure<WTS_SESSION_INFO>(cur);
                if (s.State == WTS_CONNECTSTATE_CLASS.WTSActive
                    || s.State == WTS_CONNECTSTATE_CLASS.WTSConnected)
                {
                    // Vérifier qu'il y a bien un utilisateur loggé
                    if (WTSQueryUserToken(s.SessionId, out IntPtr t))
                    {
                        list.Add(s.SessionId);
                        CloseHandle(t);
                    }
                }
            }
        }
        finally
        {
            WTSFreeMemory(sessionInfo);
        }

        return list;
    }
}
