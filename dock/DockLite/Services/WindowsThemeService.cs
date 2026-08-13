using System;
using Microsoft.Win32;

namespace DockLite.Services;

/// <summary>
/// Détecte la préférence Light / Dark de Windows 10/11 via le registre.
/// HKCU\Software\Microsoft\Windows\CurrentVersion\Themes\Personalize\AppsUseLightTheme = 1 (light) / 0 (dark)
/// </summary>
public static class WindowsThemeService
{
    public enum WindowsTheme { Dark, Light, HighContrast }

    public static WindowsTheme GetCurrentTheme()
    {
        try
        {
            // Mode contraste élevé → priorité a11y
            if (System.Windows.SystemParameters.HighContrast)
                return WindowsTheme.HighContrast;

            using var key = Registry.CurrentUser.OpenSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            var value = key?.GetValue("AppsUseLightTheme");
            if (value is int i)
                return i == 0 ? WindowsTheme.Dark : WindowsTheme.Light;
        }
        catch { /* ignore : registre inaccessible → dark par défaut */ }
        return WindowsTheme.Dark;
    }

    /// <summary>
    /// Renvoie true si Windows est en thème clair.
    /// </summary>
    public static bool IsLight() => GetCurrentTheme() == WindowsTheme.Light;
}
