using System;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Media.Animation;
using System.Windows.Threading;

namespace DockLite.Views;

/// <summary>
/// Bandeau rouge topmost affiché en haut de l'écran pendant qu'un technicien
/// prend la main sur le poste. Affiche le prénom et le téléphone du technicien.
/// Non-cliquable (IsHitTestVisible=False) pour ne jamais gêner l'utilisateur.
/// </summary>
public partial class RemoteControlBanner : Window
{
    private readonly DispatcherTimer _blink;
    private bool _dotOn = true;

    public RemoteControlBanner()
    {
        InitializeComponent();
        Loaded += OnLoaded;

        // Clignotement de la pastille (indicateur "live")
        _blink = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(700) };
        _blink.Tick += (_, _) =>
        {
            _dotOn = !_dotOn;
            LiveDot.Opacity = _dotOn ? 1.0 : 0.25;
        };
        _blink.Start();
    }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        // Occupe toute la largeur de l'écran principal, collé en haut
        var wa = SystemParameters.WorkArea;
        Left = SystemParameters.VirtualScreenLeft;
        Top  = 0;
        Width = SystemParameters.PrimaryScreenWidth;

        // Empêche l'apparition dans Alt+Tab et la capture par le partage d'écran
        MakeToolWindow();
    }

    /// <summary>Met à jour le texte technicien (prénom + téléphone).</summary>
    public void SetTechnician(string? name, string? phone)
    {
        var who = string.IsNullOrWhiteSpace(name) ? "un technicien" : name!;
        var tel = string.IsNullOrWhiteSpace(phone) ? "" : $"  ·  ☎ {phone}";
        TechInfo.Text = $"Technicien : {who}{tel}";
    }

    // ---- Win32 : fenêtre "tool window" (pas dans Alt+Tab) ----
    [DllImport("user32.dll", SetLastError = true)]
    private static extern int GetWindowLong(IntPtr hWnd, int nIndex);
    [DllImport("user32.dll")]
    private static extern int SetWindowLong(IntPtr hWnd, int nIndex, int dwNewLong);

    private const int GWL_EXSTYLE = -20;
    private const int WS_EX_TOOLWINDOW = 0x00000080;
    private const int WS_EX_NOACTIVATE = 0x08000000;

    private void MakeToolWindow()
    {
        var helper = new System.Windows.Interop.WindowInteropHelper(this);
        var ex = GetWindowLong(helper.Handle, GWL_EXSTYLE);
        SetWindowLong(helper.Handle, GWL_EXSTYLE, ex | WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE);
    }
}
