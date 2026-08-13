using System.Text;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using DockLite.Services;

namespace DockLite.Views;

public partial class SystemInfoWindow : Window
{
    public SystemInfoWindow()
    {
        InitializeComponent();
        Populate();
    }

    private void Populate()
    {
        var info = SystemInfo.Collect();
        for (int i = 0; i < info.Count; i++)
        {
            InfoGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });

            var label = new TextBlock
            {
                Text = info[i].Label,
                Style = (Style)FindResource("Label"),
                Margin = new Thickness(0, 4, 16, 4)
            };
            Grid.SetRow(label, i);
            Grid.SetColumn(label, 0);
            InfoGrid.Children.Add(label);

            var value = new TextBlock
            {
                Text = info[i].Value,
                Style = (Style)FindResource("Value"),
                Margin = new Thickness(0, 4, 0, 4)
            };
            Grid.SetRow(value, i);
            Grid.SetColumn(value, 1);
            InfoGrid.Children.Add(value);
        }
    }

    private void Copy_Click(object sender, RoutedEventArgs e)
    {
        var sb = new StringBuilder();
        foreach (var entry in SystemInfo.Collect())
            sb.AppendLine($"{entry.Label,-22} : {entry.Value}");
        try { Clipboard.SetText(sb.ToString()); } catch { }
    }

    private void Close_Click(object sender, RoutedEventArgs e) => Close();

    private async void InstallExt_Click(object sender, RoutedEventArgs e)
    {
        var btn = sender as System.Windows.Controls.Button;
        if (btn == null) return;
        btn.IsEnabled = false;
        ExtProgress.Visibility = Visibility.Visible;
        ExtProgress.Value = 0;
        var origSubText = ExtSubText.Text;

        // Étapes affichées en sous-titre + progression %
        var steps = new[]
        {
            (0,   "📡 Envoi de la demande…"),
            (8,   "⏳ Attente de l'agent système…"),
            (25,  "📥 Téléchargement du XPI…"),
            (50,  "📜 Déploiement des policies Firefox…"),
            (70,  "📂 Installation dans les profils…"),
            (90,  "🦊 Redémarrage de Firefox…"),
            (100, "✓ Extension installée et configurée"),
        };

        try
        {
            var cfg = ConfigService.Load();
            var baseUrl = (cfg.ApiBaseUrl ?? "").TrimEnd('/');
            if (baseUrl.EndsWith("/api", System.StringComparison.OrdinalIgnoreCase))
                baseUrl = baseUrl.Substring(0, baseUrl.Length - 4);
            if (string.IsNullOrEmpty(baseUrl) || string.IsNullOrEmpty(cfg.ApiKey))
            {
                btn.Content = "✗ Config manquante";
                ExtSubText.Text = "ApiBaseUrl et ApiKey requis dans apps.json";
                return;
            }

            btn.Content = "Installation…";
            ExtSubText.Text = steps[0].Item2;
            ExtProgress.Value = steps[0].Item1;

            var winUser = System.Environment.UserName;
            var machine = System.Environment.MachineName;
            var url = $"{baseUrl}/api/vault-extension-deploy.php"
                    + $"?machine={System.Uri.EscapeDataString(machine)}"
                    + $"&windows_user={System.Uri.EscapeDataString(winUser)}";

            using var http = new System.Net.Http.HttpClient();
            http.DefaultRequestHeaders.Add("X-API-Key", cfg.ApiKey);
            http.Timeout = System.TimeSpan.FromSeconds(15);

            var resp = await http.GetAsync(url);
            if (!resp.IsSuccessStatusCode)
            {
                btn.Content = $"✗ HTTP {(int)resp.StatusCode}";
                ExtSubText.Text = "Échec d'appel à DockPolice";
                ExtProgress.Foreground = new System.Windows.Media.SolidColorBrush(
                    System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
                await System.Threading.Tasks.Task.Delay(4_000);
                return;
            }

            // Anime la progression sur ~13 s en passant par chaque étape
            // [0%, 8%, 25%, 50%, 70%, 90%, 100%] répartis sur le temps total
            var stepDelays = new[] { 200, 800, 2500, 4000, 2500, 2500, 500 }; // ms
            for (int i = 1; i < steps.Length; i++)
            {
                await AnimateProgressTo(ExtProgress, steps[i].Item1, stepDelays[i]);
                ExtSubText.Text = steps[i].Item2;
            }

            // Pendant 2 s on garde le check vert
            ExtProgress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
            btn.Content = "✓ Installé";
            btn.Background = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
            await System.Threading.Tasks.Task.Delay(2_500);
        }
        catch (System.Exception ex)
        {
            btn.Content = "✗ Erreur";
            ExtSubText.Text = ex.Message;
            ExtProgress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
            await System.Threading.Tasks.Task.Delay(4_000);
        }
        finally
        {
            // Reset visuel
            btn.IsEnabled = true;
            btn.Content = "Installer / configurer";
            btn.Background = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x4E, 0x8B, 0xFF));
            ExtSubText.Text = origSubText;
            ExtProgress.Visibility = Visibility.Collapsed;
            ExtProgress.Value = 0;
            ExtProgress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x4E, 0x8B, 0xFF));
        }
    }

    /// <summary>
    /// Anime la valeur d'une ProgressBar de sa valeur actuelle à `target` sur `durationMs`.
    /// </summary>
    private static async System.Threading.Tasks.Task AnimateProgressTo(
        System.Windows.Controls.ProgressBar bar, double target, int durationMs)
    {
        const int frameMs = 30;
        var start = bar.Value;
        var diff  = target - start;
        var steps = System.Math.Max(1, durationMs / frameMs);
        for (int i = 1; i <= steps; i++)
        {
            // Easing : ease-out (rapide au début, lent à la fin)
            var t = (double)i / steps;
            var eased = 1 - System.Math.Pow(1 - t, 2);
            bar.Value = start + diff * eased;
            await System.Threading.Tasks.Task.Delay(frameMs);
        }
        bar.Value = target;
    }

    /// <summary>
    /// Recherche le chemin de firefox.exe dans les emplacements standards.
    /// </summary>
    private static string? LocateFirefox()
    {
        var candidates = new[]
        {
            System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.ProgramFiles),       "Mozilla Firefox", "firefox.exe"),
            System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.ProgramFilesX86),    "Mozilla Firefox", "firefox.exe"),
            System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.ProgramFiles),       "Firefox ESR",     "firefox.exe"),
            System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.ProgramFiles),       "Mozilla Firefox ESR", "firefox.exe"),
            System.IO.Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.LocalApplicationData),"Mozilla Firefox", "firefox.exe"),
        };
        foreach (var p in candidates)
            if (!string.IsNullOrEmpty(p) && System.IO.File.Exists(p)) return p;

        // Tente aussi via le registre Windows (HKLM\SOFTWARE\Mozilla\...)
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Mozilla\Mozilla Firefox");
            var version = key?.GetValue("CurrentVersion") as string;
            if (!string.IsNullOrEmpty(version))
            {
                using var sub = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(
                    $@"SOFTWARE\Mozilla\Mozilla Firefox\{version}\Main");
                var path = sub?.GetValue("PathToExe") as string;
                if (!string.IsNullOrEmpty(path) && System.IO.File.Exists(path)) return path;
            }
        }
        catch { /* registre inaccessible */ }
        return null;
    }
}
