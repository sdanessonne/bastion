using System;
using System.Collections.Generic;
using System.Linq;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Media;
using System.Windows.Threading;
using DockLite.Models;
using DockLite.Services;

namespace DockLite.Views;

/// <summary>
/// Bandeau déroulant en haut de l'écran. Topmost, sans chrome, largeur écran.
/// Le texte défile en boucle de droite à gauche avec une vitesse paramétrable.
/// </summary>
public partial class BroadcastBannerWindow : Window
{
    private const double Height32 = 32;
    private const string Separator = "   •   ";

    private readonly BroadcastService _service;
    private readonly DispatcherTimer _tick;
    private double _offset;
    private double _speedPxSec = 80;
    private DateTime _lastFrame;
    private List<Broadcast> _broadcasts = new();
    private int _currentTopId;

    public BroadcastBannerWindow(BroadcastService service)
    {
        InitializeComponent();
        _service = service;

        Height = Height32;
        Loaded += OnLoaded;

        _tick = new DispatcherTimer(DispatcherPriority.Render)
        {
            Interval = TimeSpan.FromMilliseconds(16) // ~60 fps
        };
        _tick.Tick += OnTick;
    }

    public void SetBroadcasts(List<Broadcast> list)
    {
        _broadcasts = list;
        var top = list.FirstOrDefault();
        if (top == null)
        {
            Close();
            return;
        }

        // Ajuste couleur + vitesse uniquement si le bandeau prioritaire change,
        // sinon la lecture en boucle reste fluide.
        if (top.Id != _currentTopId)
        {
            _currentTopId = top.Id;
            ApplyLevelColors(top.Level);
            _speedPxSec = Math.Max(20, Math.Min(400, top.SpeedPxSec));
        }

        // Le texte concatène tous les messages actifs (bandeaux multiples).
        var messages = string.Join(Separator, list.Select(b => b.Message.Trim()));
        TickerText.Text = messages + Separator + messages;

        // Bouton masquer : visible uniquement si TOUS les bandeaux sont masquables
        DismissBtn.Visibility = list.All(b => b.Dismissible) ? Visibility.Visible : Visibility.Collapsed;

        // Recale si le texte a rétréci au point que l'offset soit hors zone
        if (_offset < -TickerText.ActualWidth) _offset = ActualWidth;
    }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        PlaceAtTop();
        MakeClickThroughHeaderBits();
        _offset = ActualWidth;
        _lastFrame = DateTime.UtcNow;
        _tick.Start();
    }

    protected override void OnClosed(EventArgs e)
    {
        _tick.Stop();
        base.OnClosed(e);
    }

    private void PlaceAtTop()
    {
        var primary = SystemParameters.PrimaryScreenWidth;
        var virtualLeft = SystemParameters.VirtualScreenLeft;
        var virtualTop  = SystemParameters.VirtualScreenTop;

        Left   = virtualLeft;
        Top    = virtualTop;
        Width  = primary;
        Height = Height32;
    }

    private void MakeClickThroughHeaderBits()
    {
        // Évite que le bandeau ne vole le focus / soit sélectionné dans alt-tab
        var hwnd = new System.Windows.Interop.WindowInteropHelper(this).Handle;
        const int GWL_EXSTYLE = -20;
        const int WS_EX_TOOLWINDOW = 0x00000080;
        const int WS_EX_NOACTIVATE  = 0x08000000;
        var style = GetWindowLong(hwnd, GWL_EXSTYLE);
        SetWindowLong(hwnd, GWL_EXSTYLE, style | WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE);
    }

    private void OnTick(object? sender, EventArgs e)
    {
        var now = DateTime.UtcNow;
        var dt = (now - _lastFrame).TotalSeconds;
        _lastFrame = now;

        _offset -= _speedPxSec * dt;
        var textW = TickerText.ActualWidth;

        // Demi-texte : le texte est dupliqué (voir SetBroadcasts) pour donner l'illusion
        // d'un défilement continu ; on reboucle à la moitié.
        var halfW = textW / 2;
        if (_offset < -halfW)
        {
            _offset += halfW;
        }

        System.Windows.Controls.Canvas.SetLeft(TickerText, _offset);
    }

    private void ApplyLevelColors(string level)
    {
        Color bg, fg, accent;
        switch (level?.ToLowerInvariant())
        {
            case "urgent":
                bg = Color.FromRgb(0xDC, 0x26, 0x26);
                fg = Colors.White;
                accent = Color.FromArgb(0x55, 0, 0, 0);
                break;
            case "warning":
                bg = Color.FromRgb(0xF5, 0x9E, 0x0B);
                fg = Color.FromRgb(0x1A, 0x1A, 0x1A);
                accent = Color.FromArgb(0x33, 0, 0, 0);
                break;
            default:
                bg = Color.FromRgb(0x0E, 0xA5, 0xE9);
                fg = Colors.White;
                accent = Color.FromArgb(0x33, 0, 0, 0);
                break;
        }
        Background = new SolidColorBrush(bg);
        TickerText.Foreground = new SolidColorBrush(fg);
        AccentBorder.BorderBrush = new SolidColorBrush(accent);
    }

    private void DismissBtn_Click(object sender, RoutedEventArgs e)
    {
        // Masque uniquement les bandeaux dismissibles actuellement affichés
        foreach (var b in _broadcasts.Where(b => b.Dismissible))
            _service.Dismiss(b.Id);
    }

    [DllImport("user32.dll")]
    private static extern int GetWindowLong(IntPtr hwnd, int index);

    [DllImport("user32.dll")]
    private static extern int SetWindowLong(IntPtr hwnd, int index, int newStyle);
}
