using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using DockLite.Models;
using DockLite.Services;

namespace DockLite.Views;

public class DockIcon : Border
{
    private readonly ScaleTransform _scale;
    private readonly double _baseSize;

    public DockItem Item { get; }

    /// <summary>
    /// true si le chemin de l'item pointe vers un fichier absent (ex: Office présent sur un
    /// autre poste mais pas ici). L'icône est grisée et garde sa position dans le dock.
    /// </summary>
    public bool IsMissing { get; }

    public DockIcon(DockItem item, double baseSize, double spacing, UIElement? customVisual = null)
    {
        Item = item;
        _baseSize = baseSize;

        IsMissing = customVisual == null
                    && !string.IsNullOrEmpty(item.Path)
                    && Path.IsPathRooted(item.Path)
                    && !File.Exists(item.Path);

        var half = spacing / 2;
        Margin = new Thickness(half, 0, half, 0);
        Background = Brushes.Transparent;
        Cursor = IsMissing ? System.Windows.Input.Cursors.No : System.Windows.Input.Cursors.Hand;
        VerticalAlignment = VerticalAlignment.Bottom;
        HorizontalAlignment = HorizontalAlignment.Center;
        if (IsMissing) Opacity = 0.4;

        _scale = new ScaleTransform(1, 1);

        UIElement content;
        if (customVisual is FrameworkElement fe)
        {
            fe.Width = baseSize;
            fe.Height = baseSize;
            fe.RenderTransformOrigin = new Point(0.5, 1.0);
            fe.RenderTransform = _scale;
            content = fe;
        }
        else
        {
            var img = new Image
            {
                Width = baseSize,
                Height = baseSize,
                Stretch = Stretch.Uniform,
                Source = IconExtractor.GetIcon(item.Path),
                RenderTransformOrigin = new Point(0.5, 1.0),
                RenderTransform = _scale
            };
            content = img;
        }

        Child = content;

        var tooltipText = new TextBlock
        {
            Text = IsMissing ? $"{item.Name} (indisponible sur ce poste)" : item.Name,
            Foreground = Brushes.White,
            Background = new SolidColorBrush(Color.FromArgb(220, 30, 30, 30)),
            Padding = new Thickness(6, 2, 6, 2),
            FontSize = 12
        };
        ToolTip = new ToolTip
        {
            Content = tooltipText,
            Background = Brushes.Transparent,
            BorderThickness = new Thickness(0),
            HasDropShadow = false,
            Placement = System.Windows.Controls.Primitives.PlacementMode.Top
        };
    }

    public void ApplyScale(double scale)
    {
        _scale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
        _scale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
        _scale.ScaleX = scale;
        _scale.ScaleY = scale;
    }
}
