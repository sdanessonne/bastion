using System.Collections.Generic;

namespace DockLite.Models;

public enum DockPosition { Top, Bottom, Left, Right }

public class DockConfig
{
    public DockPosition Position { get; set; } = DockPosition.Top;
    public int IconSize { get; set; } = 64;
    public int MaxIconSize { get; set; } = 110;
    public bool AutoHide { get; set; } = true;
    public bool MagnifyOnHover { get; set; } = true;
    public int IconSpacing { get; set; } = 12;
    public bool ShowSystemInfo { get; set; } = true;
    public bool ShowSupportTicket { get; set; } = true;
    public bool ShowProfile { get; set; } = true;
    public string TicketConnectionString { get; set; } = "";
    public string CommissariatCode { get; set; } = "";
    public string ApiBaseUrl { get; set; } = "";
    public string ApiKey { get; set; } = "";
    public string UpdateCheckUrl { get; set; } = "";
    public int NotificationPollSeconds { get; set; } = 30;
    public List<DockItem> Items { get; set; } = new();
}
