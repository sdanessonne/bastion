using System;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace DockPolice.Agent.Services;

[SupportedOSPlatform("windows")]
public static class SessionInfo
{
    [DllImport("kernel32.dll")]
    private static extern uint WTSGetActiveConsoleSessionId();

    [DllImport("Wtsapi32.dll", CharSet = CharSet.Unicode)]
    private static extern bool WTSQuerySessionInformation(
        IntPtr hServer, uint sessionId, WTS_INFO_CLASS infoClass,
        out IntPtr buffer, out uint bytesReturned);

    [DllImport("Wtsapi32.dll")]
    private static extern void WTSFreeMemory(IntPtr memory);

    private enum WTS_INFO_CLASS
    {
        WTSUserName = 5,
        WTSDomainName = 7,
        WTSConnectState = 8
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

    public class CurrentSession
    {
        public bool HasActiveSession { get; set; }
        public string UserName { get; set; } = "";
        public string DomainName { get; set; } = "";
        public string State { get; set; } = "";

        public string DisplayName =>
            string.IsNullOrEmpty(UserName)
                ? "(aucun utilisateur connecté)"
                : (string.IsNullOrEmpty(DomainName) ? UserName : $"{DomainName}\\{UserName}");
    }

    public static CurrentSession GetActive()
    {
        var info = new CurrentSession();
        try
        {
            uint sessionId = WTSGetActiveConsoleSessionId();
            if (sessionId == 0xFFFFFFFF)
            {
                info.State = "AucuneSessionActive";
                return info;
            }

            info.UserName = QueryString(sessionId, WTS_INFO_CLASS.WTSUserName);
            info.DomainName = QueryString(sessionId, WTS_INFO_CLASS.WTSDomainName);

            if (WTSQuerySessionInformation(IntPtr.Zero, sessionId, WTS_INFO_CLASS.WTSConnectState, out IntPtr buf, out uint _))
            {
                try
                {
                    var state = (WTS_CONNECTSTATE_CLASS)Marshal.ReadInt32(buf);
                    info.State = state.ToString().Replace("WTS", "");
                    info.HasActiveSession = state == WTS_CONNECTSTATE_CLASS.WTSActive
                                         && !string.IsNullOrEmpty(info.UserName);
                }
                finally { WTSFreeMemory(buf); }
            }
        }
        catch (Exception ex)
        {
            info.State = "Erreur:" + ex.Message;
        }
        return info;
    }

    private static string QueryString(uint sessionId, WTS_INFO_CLASS infoClass)
    {
        if (!WTSQuerySessionInformation(IntPtr.Zero, sessionId, infoClass, out IntPtr buf, out uint bytes))
            return "";
        try
        {
            return Marshal.PtrToStringUni(buf) ?? "";
        }
        finally { WTSFreeMemory(buf); }
    }
}
