using System;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Ink;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;

namespace DockLite.Views;

/// <summary>
/// Fenêtre d'annotation : reçoit une image PNG, permet à l'utilisateur de la
/// surligner / dessiner dessus, et renvoie le PNG annoté via la propriété Result.
/// </summary>
public partial class AnnotateWindow : Window
{
    private readonly BitmapImage _src;
    private readonly DrawingAttributes _pen = new()
    {
        Color = Color.FromRgb(0xEF, 0x44, 0x44), // rouge
        Width = 4, Height = 4,
        StylusTip = StylusTip.Ellipse,
        FitToCurve = true,
        IsHighlighter = false,
    };

    /// <summary>PNG résultat (null si annulé).</summary>
    public byte[]? Result { get; private set; }

    public AnnotateWindow(byte[] sourcePng)
    {
        InitializeComponent();
        _src = new BitmapImage();
        using (var ms = new MemoryStream(sourcePng))
        {
            _src.BeginInit();
            _src.CacheOption = BitmapCacheOption.OnLoad;
            _src.StreamSource = ms;
            _src.EndInit();
            _src.Freeze();
        }
        BgImage.Source = _src;
        Ink.DefaultDrawingAttributes = _pen;
    }

    // ---- Outils ----

    private void Color_Click(object sender, RoutedEventArgs e)
    {
        if (sender is Button b && b.Tag is string hex)
        {
            _pen.Color = (Color)ColorConverter.ConvertFromString(hex);
            ResetActiveOutline(b);
        }
    }

    private void ResetActiveOutline(Button active)
    {
        // Reset border on all color buttons (siblings)
        if (active.Parent is StackPanel sp)
        {
            foreach (var child in sp.Children)
                if (child is Button cb && cb != active && cb.Tag is string)
                    cb.BorderBrush = Brushes.Transparent;
            active.BorderBrush = Brushes.White;
        }
    }

    private void Thin_Click(object sender, RoutedEventArgs e)
    { _pen.Width = 2; _pen.Height = 2; }
    private void Thick_Click(object sender, RoutedEventArgs e)
    { _pen.Width = 8; _pen.Height = 8; }

    private void Pen_Click(object sender, RoutedEventArgs e)
    {
        _pen.IsHighlighter = false;
        Ink.EditingMode = InkCanvasEditingMode.Ink;
    }
    private void Highlight_Click(object sender, RoutedEventArgs e)
    {
        _pen.IsHighlighter = true;
        _pen.Width = 16; _pen.Height = 16;
        Ink.EditingMode = InkCanvasEditingMode.Ink;
    }
    private void Eraser_Click(object sender, RoutedEventArgs e)
    {
        Ink.EditingMode = InkCanvasEditingMode.EraseByStroke;
    }

    private void Undo_Click(object sender, RoutedEventArgs e)
    {
        if (Ink.Strokes.Count > 0)
            Ink.Strokes.RemoveAt(Ink.Strokes.Count - 1);
    }
    private void Clear_Click(object sender, RoutedEventArgs e)
    {
        Ink.Strokes.Clear();
    }

    protected override void OnPreviewKeyDown(KeyEventArgs e)
    {
        if (e.Key == Key.Z && Keyboard.Modifiers.HasFlag(ModifierKeys.Control))
        {
            Undo_Click(this, new RoutedEventArgs());
            e.Handled = true;
        }
        base.OnPreviewKeyDown(e);
    }

    // ---- Validation ----

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        Result = null;
        DialogResult = false;
        Close();
    }

    private void Validate_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            // On rend l'image source + les traits dans un même bitmap aux dimensions originales
            int w = _src.PixelWidth;
            int h = _src.PixelHeight;
            var rtb = new RenderTargetBitmap(w, h, 96, 96, PixelFormats.Pbgra32);

            // DrawingVisual pour combiner l'image + les strokes (rendus à la taille originale)
            var visual = new DrawingVisual();
            using (var dc = visual.RenderOpen())
            {
                dc.DrawImage(_src, new Rect(0, 0, w, h));

                // Les strokes sont en coordonnées InkCanvas (taille affichée).
                // On calcule le ratio entre la taille affichée et la taille de l'image source.
                double scaleX = (double)w / Math.Max(1, Ink.ActualWidth);
                double scaleY = (double)h / Math.Max(1, Ink.ActualHeight);
                // L'image est en Stretch=Uniform : il y a probablement des bandes vides.
                // On calcule la zone réellement occupée par l'image dans le canvas affiché.
                double aspectSrc = (double)w / h;
                double aspectDsp = Ink.ActualWidth / Math.Max(1, Ink.ActualHeight);
                double offsetX = 0, offsetY = 0;
                double dispW = Ink.ActualWidth, dispH = Ink.ActualHeight;
                if (aspectDsp > aspectSrc)
                {
                    dispW = Ink.ActualHeight * aspectSrc;
                    offsetX = (Ink.ActualWidth - dispW) / 2;
                }
                else
                {
                    dispH = Ink.ActualWidth / aspectSrc;
                    offsetY = (Ink.ActualHeight - dispH) / 2;
                }
                scaleX = w / dispW;
                scaleY = h / dispH;

                dc.PushTransform(new TranslateTransform(-offsetX, -offsetY));
                dc.PushTransform(new ScaleTransform(scaleX, scaleY));
                foreach (var stroke in Ink.Strokes)
                {
                    stroke.Draw(dc);
                }
                dc.Pop();
                dc.Pop();
            }
            rtb.Render(visual);

            using var ms = new MemoryStream();
            var enc = new PngBitmapEncoder();
            enc.Frames.Add(BitmapFrame.Create(rtb));
            enc.Save(ms);
            Result = ms.ToArray();
            DialogResult = true;
            Close();
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, $"Échec du rendu de l'annotation : {ex.Message}",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }
}
