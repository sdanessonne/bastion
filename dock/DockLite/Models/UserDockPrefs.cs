using System.Collections.Generic;

namespace DockLite.Models;

/// <summary>
/// Sous-ensemble de DockConfig synchronisé par utilisateur Windows (DOMAIN\user)
/// via le backoffice. Les champs poste (ApiBaseUrl, ApiKey, TicketConnectionString,
/// CommissariatCode, UpdateCheckUrl) restent dans apps.json local.
/// </summary>
public class UserDockPrefs
{
    public DockPosition Position { get; set; } = DockPosition.Top;
    public int IconSize { get; set; } = 64;
    public int MaxIconSize { get; set; } = 110;
    public int IconSpacing { get; set; } = 12;
    public bool AutoHide { get; set; } = true;
    public bool MagnifyOnHover { get; set; } = true;
    public bool ShowSystemInfo { get; set; } = true;
    public bool ShowSupportTicket { get; set; } = true;
    public int NotificationPollSeconds { get; set; } = 30;
    public List<DockItem> Items { get; set; } = new();

    public static UserDockPrefs From(DockConfig cfg) => new()
    {
        Position                = cfg.Position,
        IconSize                = cfg.IconSize,
        MaxIconSize             = cfg.MaxIconSize,
        IconSpacing             = cfg.IconSpacing,
        AutoHide                = cfg.AutoHide,
        MagnifyOnHover          = cfg.MagnifyOnHover,
        ShowSystemInfo          = cfg.ShowSystemInfo,
        ShowSupportTicket       = cfg.ShowSupportTicket,
        NotificationPollSeconds = cfg.NotificationPollSeconds,
        Items                   = new List<DockItem>(cfg.Items),
    };

    public void ApplyTo(DockConfig cfg)
    {
        cfg.Position                = Position;
        cfg.IconSize                = IconSize;
        cfg.MaxIconSize             = MaxIconSize;
        cfg.IconSpacing             = IconSpacing;
        cfg.AutoHide                = AutoHide;
        cfg.MagnifyOnHover          = MagnifyOnHover;
        cfg.ShowSystemInfo          = ShowSystemInfo;
        cfg.ShowSupportTicket       = ShowSupportTicket;
        cfg.NotificationPollSeconds = NotificationPollSeconds;
        cfg.Items                   = new List<DockItem>(Items);
    }
}
