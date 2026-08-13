using System;
using System.Globalization;
using System.Windows;
using System.Windows.Data;
using System.Windows.Media;

namespace DockLite.Views;

/// <summary>
/// Convertit une priorité (Basse/Normale/Haute/Urgente) en couleur.
/// Parameter : "fg" (plein) ou "bg" (transparence pour badge/chip).
/// </summary>
public class PriorityColorConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        var kind = (parameter as string) ?? "fg";
        var color = (value as string)?.ToLowerInvariant() switch
        {
            "basse"   => Color.FromRgb(0x6C, 0x76, 0x90),
            "normale" => Color.FromRgb(0x06, 0xB6, 0xD4),
            "haute"   => Color.FromRgb(0xF5, 0x9E, 0x0B),
            "urgente" => Color.FromRgb(0xEF, 0x44, 0x44),
            _         => Color.FromRgb(0x6C, 0x76, 0x90),
        };
        if (kind == "bg") color.A = 0x33;
        return new SolidColorBrush(color);
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotImplementedException();
}

/// <summary>
/// Convertit un statut (Ouvert/En cours/Résolu/Fermé) en couleur.
/// Parameter : "fg" ou "bg".
/// </summary>
public class StatusColorConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        var kind = (parameter as string) ?? "fg";
        var color = (value as string)?.ToLowerInvariant() switch
        {
            "ouvert"   => Color.FromRgb(0xEF, 0x44, 0x44),
            "en cours" => Color.FromRgb(0xF5, 0x9E, 0x0B),
            "résolu"   => Color.FromRgb(0x10, 0xB9, 0x81),
            "fermé"    => Color.FromRgb(0x6C, 0x76, 0x90),
            _          => Color.FromRgb(0x6C, 0x76, 0x90),
        };
        if (kind == "bg") color.A = 0x33;
        return new SolidColorBrush(color);
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotImplementedException();
}

/// <summary>
/// Retourne Visible si la chaîne n'est pas null/vide, Collapsed sinon.
/// </summary>
public class StringToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => string.IsNullOrWhiteSpace(value as string) ? Visibility.Collapsed : Visibility.Visible;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotImplementedException();
}

/// <summary>
/// Construit "il y a X min / h / j" à partir d'un DateTime.
/// </summary>
public class AgoConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        if (value is not DateTime dt) return "";
        var span = DateTime.Now - dt;
        if (span.TotalMinutes < 1)  return "à l'instant";
        if (span.TotalMinutes < 60) return $"il y a {(int)span.TotalMinutes} min";
        if (span.TotalHours   < 24) return $"il y a {(int)span.TotalHours} h";
        if (span.TotalDays    < 30) return $"il y a {(int)span.TotalDays} j";
        return dt.ToString("dd/MM/yyyy");
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotImplementedException();
}
