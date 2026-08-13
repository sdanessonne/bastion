using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Animation;
using DockLite.Models;
using DockLite.Services;
using Microsoft.Win32;

namespace DockLite.Views;

public partial class MainWindow : Window
{
    private DockConfig _config = new();
    private readonly List<DockIcon> _icons = new();
    private MenuItem? _autoHideMenu;
    private MenuItem? _magnifyMenu;
    private System.Windows.Threading.DispatcherTimer? _notifTimer;
    private NotificationState _notifState = new();
    private TicketWindow? _openTicketWindow;

    private double _shownTop;
    private double _hiddenTop;
    private bool _isShown = true;

    public MainWindow()
    {
        InitializeComponent();
        Loaded += OnLoaded;
        MouseLeftButtonDown += (_, e) =>
        {
            if (e.ButtonState == MouseButtonState.Pressed && Keyboard.Modifiers == ModifierKeys.Control)
                DragMove();
        };
    }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        _config = ConfigService.Load();
        TicketService.ConnectionString = _config.TicketConnectionString;
        AttachmentApi.BaseUrl = _config.ApiBaseUrl;
        AttachmentApi.ApiKey = _config.ApiKey;
        HabilitationService.BaseUrl = _config.ApiBaseUrl;
        HabilitationService.ApiKey  = _config.ApiKey;
        RemoteAssistCodeService.BaseUrl = _config.ApiBaseUrl;
        RemoteAssistCodeService.ApiKey  = _config.ApiKey;
        ThunderbirdService.BaseUrl = _config.ApiBaseUrl;
        ThunderbirdService.ApiKey  = _config.ApiKey;

        // Vérification + téléchargement update en arrière-plan (silencieux)
        _ = AutoUpdateService.CheckAndDownloadAsync();

        // Thunderbird : snapshot des profils + traitement des tâches de déploiement
        _ = System.Threading.Tasks.Task.Run(async () =>
        {
            try { await ThunderbirdService.ReportAsync(); } catch { }
            try { await ThunderbirdService.ProcessPendingDeploymentsAsync(); } catch { }
        });
        var tbTimer = new System.Windows.Threading.DispatcherTimer { Interval = TimeSpan.FromMinutes(5) };
        tbTimer.Tick += async (_, __) =>
        {
            try { await ThunderbirdService.ProcessPendingDeploymentsAsync(); } catch { }
        };
        tbTimer.Start();
        _notifState = NotificationStateService.Load();
        DockBorder.ContextMenu = BuildDockContextMenu();
        ArrowTrigger.ContextMenu = BuildDockContextMenu();
        Rebuild();
        _ = CheckForUpdatesAsync();
        StartNotificationPolling();

        // Télémétrie + commandes à distance : assurées par le service Windows DockPoliceAgent.
        // Si le service n'est pas installé/démarré, on bascule en fallback dans le WPF.
        if (!IsAgentServiceRunning())
        {
            MachineReporter.Start();
            RemoteCommandService.Start();
        }

        // Stream d'écran + pilotage à distance : côté WPF (le service en SYSTEM
        // n'a pas accès au desktop interactif de l'utilisateur)
        ScreenStreamService.Start();
        RemoteInputService.Start();

        // Auto-update : check périodique vs version courante côté backoffice.
        // En mode "notify_user" (défaut serveur), lève l'évènement OnUpdateAvailable
        // qu'on utilise pour afficher un toast à l'utilisateur. En mode "auto_apply"
        // ou "mandatory", déclenche directement le déploiement via agent_commands.
        UpdateCheckerService.OnUpdateAvailable += OnUpdateAvailable;
        UpdateCheckerService.Start();

        // Bandeau défilant départemental / CPN
        _broadcastService = new BroadcastService(_config.ApiBaseUrl, _config.ApiKey, _config.CommissariatCode);
        _broadcastService.Start();

        // Mode offline : retente d'envoyer les tickets en attente toutes les 60 s
        _offlineRetryTimer = new System.Windows.Threading.DispatcherTimer { Interval = TimeSpan.FromSeconds(60) };
        _offlineRetryTimer.Tick += async (_, __) => await OfflineTicketQueue.FlushAsync();
        _offlineRetryTimer.Start();
        _ = OfflineTicketQueue.FlushAsync();   // tentative immédiate au démarrage
    }

    private BroadcastService? _broadcastService;
    private System.Windows.Threading.DispatcherTimer? _offlineRetryTimer;

    private static bool IsAgentServiceRunning()
    {
        try
        {
            using var sc = new System.ServiceProcess.ServiceController("DockPoliceAgent");
            return sc.Status == System.ServiceProcess.ServiceControllerStatus.Running;
        }
        catch
        {
            return false;
        }
    }

    private void StartNotificationPolling()
    {
        if (!TicketService.IsConfigured || _config.NotificationPollSeconds <= 0) return;

        // Au tout premier lancement, ne pas notifier les anciens commentaires
        _ = InitializeNotificationBaselineAsync();

        _notifTimer = new System.Windows.Threading.DispatcherTimer
        {
            Interval = TimeSpan.FromSeconds(_config.NotificationPollSeconds)
        };
        _notifTimer.Tick += async (_, _) => await PollNotificationsAsync();
        _notifTimer.Start();
    }

    private async System.Threading.Tasks.Task InitializeNotificationBaselineAsync()
    {
        if (_notifState.LastSeenCommentId == 0)
        {
            _notifState.LastSeenCommentId = await TicketService.GetMaxCommentIdAsync();
            NotificationStateService.Save(_notifState);
        }
    }

    private bool _polling;
    private async System.Threading.Tasks.Task PollNotificationsAsync()
    {
        if (_polling) return;
        _polling = true;
        try
        {
            var newOnes = await TicketService.GetUnreadCommentsForUserAsync(
                Environment.UserName, _notifState.LastSeenCommentId);

            foreach (var notif in newOnes)
            {
                ShowToast(notif);
            }

            if (newOnes.Count > 0)
            {
                _notifState.LastSeenCommentId = newOnes.Max(n => n.CommentId);
                NotificationStateService.Save(_notifState);
            }
        }
        catch { /* silent — réessai au prochain tick */ }
        finally { _polling = false; }
    }

    private void ShowToast(TicketService.TicketReplyNotification notif)
    {
        var toast = new ToastWindow(notif);
        toast.OpenTicketRequested += (_, ticketId) =>
        {
            ShowTicketWindow(openMyTickets: true, focusTicketId: ticketId);
        };
        toast.Show();
    }

    /// <summary>
    /// Toast/MessageBox levé quand UpdateCheckerService détecte une nouvelle version
    /// côté serveur (mode notify_user, défaut). En mode auto_apply ou mandatory,
    /// le service déclenche le déploiement directement sans passer ici.
    /// </summary>
    private void OnUpdateAvailable(UpdateCheckerService.UpdateInfo info)
    {
        // Marshalling sur le thread UI (l'évènement vient d'un timer DispatcherTimer
        // mais on garantit la sécurité au cas où)
        Dispatcher.Invoke(() =>
        {
            try
            {
                var sizeMb = info.Size > 0 ? (info.Size / 1024.0 / 1024.0) : 0;
                var msg = "Une nouvelle version de DockPolice est disponible.\n\n" +
                          $"Version installée : {info.CurrentVersion}\n" +
                          $"Nouvelle version  : {info.LatestVersion}" +
                          (sizeMb > 0 ? $"  ({sizeMb:N1} Mo)\n" : "\n") +
                          (string.IsNullOrWhiteSpace(info.Notes) ? "" : "\n" + info.Notes + "\n") +
                          "\nL'agent local appliquera la mise à jour automatiquement (le poste " +
                          "redémarrera DockPolice). Lancer maintenant ?";

                var result = MessageBox.Show(
                    this, msg, "DockPolice — Mise à jour disponible",
                    MessageBoxButton.YesNo, MessageBoxImage.Information);

                if (result == MessageBoxResult.Yes)
                {
                    _ = System.Threading.Tasks.Task.Run(async () =>
                    {
                        var ok = await UpdateCheckerService.TriggerUpdateAsync(info);
                        Dispatcher.Invoke(() =>
                        {
                            if (ok)
                            {
                                MessageBox.Show(this,
                                    "Mise à jour planifiée.\n\nL'agent va l'appliquer dans les " +
                                    "prochaines secondes. DockPolice se relancera tout seul.",
                                    "DockPolice", MessageBoxButton.OK, MessageBoxImage.Information);
                            }
                            else
                            {
                                MessageBox.Show(this,
                                    "Impossible de déclencher la mise à jour à distance. " +
                                    "Vérifie la connexion réseau et l'état du service DockPolice.Agent.",
                                    "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
                            }
                        });
                    });
                }
            }
            catch { /* silent */ }
        });
    }

    private async System.Threading.Tasks.Task CheckForUpdatesAsync()
    {
        if (string.IsNullOrWhiteSpace(_config.UpdateCheckUrl)) return;
        await System.Threading.Tasks.Task.Delay(3000);

        try
        {
            var info = await UpdateChecker.CheckAsync(_config.UpdateCheckUrl);
            if (info == null) return;

            var msg = $"Une nouvelle version de DockPolice est disponible.\n\n" +
                      $"Version installée : {UpdateChecker.GetCurrentVersion()}\n" +
                      $"Nouvelle version  : {info.Version}\n\n" +
                      (string.IsNullOrEmpty(info.ReleaseNotes) ? "" : info.ReleaseNotes + "\n\n") +
                      "Voulez-vous télécharger la mise à jour ?";

            var result = MessageBox.Show(this, msg, "DockPolice — Mise à jour",
                MessageBoxButton.YesNo, MessageBoxImage.Information);

            if (result == MessageBoxResult.Yes && !string.IsNullOrEmpty(info.DownloadUrl))
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = info.DownloadUrl,
                    UseShellExecute = true
                });
            }
        }
        catch { }
    }

    private ContextMenu BuildDockContextMenu()
    {
        var menu = new ContextMenu();

        var addApp = new MenuItem { Header = "Ajouter une application..." };
        addApp.Click += AddApp_Click;
        menu.Items.Add(addApp);

        var openJson = new MenuItem { Header = "Ouvrir apps.json..." };
        openJson.Click += OpenJson_Click;
        menu.Items.Add(openJson);

        var detectPolice = new MenuItem { Header = "Détecter logiciels métiers..." };
        detectPolice.Click += DetectPolice_Click;
        menu.Items.Add(detectPolice);

        menu.Items.Add(new Separator());

        var position = new MenuItem { Header = "Position" };
        foreach (DockPosition pos in Enum.GetValues(typeof(DockPosition)))
        {
            var item = new MenuItem { Header = PositionLabel(pos), Tag = pos, IsCheckable = true, IsChecked = _config.Position == pos };
            item.Click += Position_Click;
            position.Items.Add(item);
        }
        menu.Items.Add(position);

        _autoHideMenu = new MenuItem { Header = "Masquage automatique", IsCheckable = true, IsChecked = _config.AutoHide };
        _autoHideMenu.Click += AutoHide_Click;
        menu.Items.Add(_autoHideMenu);

        _magnifyMenu = new MenuItem { Header = "Zoom au survol", IsCheckable = true, IsChecked = _config.MagnifyOnHover };
        _magnifyMenu.Click += Magnify_Click;
        menu.Items.Add(_magnifyMenu);

        var spacing = new MenuItem { Header = "Espacement des icônes" };
        foreach (var (label, value) in new[] { ("Compact (4 px)", 4), ("Normal (12 px)", 12), ("Large (20 px)", 20), ("Très large (32 px)", 32) })
        {
            var item = new MenuItem { Header = label, Tag = value, IsCheckable = true, IsChecked = _config.IconSpacing == value };
            item.Click += Spacing_Click;
            spacing.Items.Add(item);
        }
        menu.Items.Add(spacing);

        // Menu "Afficher icône Infos PC" retiré (icône supprimée du dock).

        var showSupport = new MenuItem { Header = "Afficher icône SAV", IsCheckable = true, IsChecked = _config.ShowSupportTicket };
        showSupport.Click += ShowSupportToggle_Click;
        menu.Items.Add(showSupport);

        menu.Items.Add(new Separator());

        var quit = new MenuItem { Header = "Quitter" };
        quit.Click += Quit_Click;
        menu.Items.Add(quit);

        return menu;
    }

    private static string PositionLabel(DockPosition pos) => pos switch
    {
        DockPosition.Top => "Haut",
        DockPosition.Bottom => "Bas",
        DockPosition.Left => "Gauche",
        DockPosition.Right => "Droite",
        _ => pos.ToString()
    };

    private void Rebuild()
    {
        ItemsHost.Items.Clear();
        _icons.Clear();

        var orientation = _config.Position == DockPosition.Left || _config.Position == DockPosition.Right
            ? Orientation.Vertical : Orientation.Horizontal;

        var template = new ItemsPanelTemplate();
        var panelFactory = new FrameworkElementFactory(typeof(StackPanel));
        panelFactory.SetValue(StackPanel.OrientationProperty, orientation);
        template.VisualTree = panelFactory;
        ItemsHost.ItemsPanel = template;

        foreach (var item in _config.Items)
        {
            var icon = new DockIcon(item, _config.IconSize, _config.IconSpacing);
            if (!icon.IsMissing)
                icon.MouseLeftButtonUp += (_, __) => Launch(item);
            icon.ContextMenu = BuildItemContextMenu(item);
            _icons.Add(icon);
            ItemsHost.Items.Add(icon);
        }

        // Icône "Infos PC" supprimée du dock — le rapport technique et le bouton
        // d'installation Firefox sont désormais accessibles depuis la "Demande SAV".

        if (_config.ShowSupportTicket)
        {
            var supItem = new DockItem { Name = "Demande SAV", Path = "" };
            var visual = CreateSupportVisual(_config.IconSize);
            var supIcon = new DockIcon(supItem, _config.IconSize, _config.IconSpacing, visual);
            supIcon.MouseLeftButtonUp += (_, __) => ShowTicketWindow();
            _icons.Add(supIcon);
            ItemsHost.Items.Add(supIcon);
        }

        if (_config.ShowProfile)
        {
            var profItem = new DockItem { Name = "Mon profil annuaire", Path = "" };
            var visual = CreateProfileVisual(_config.IconSize);
            var profIcon = new DockIcon(profItem, _config.IconSize, _config.IconSpacing, visual);
            profIcon.MouseLeftButtonUp += (_, __) => ShowProfileWindow();
            _icons.Add(profIcon);
            ItemsHost.Items.Add(profIcon);
        }

        UpdateArrowVisibility();
        UpdatePosition();

        // Au démarrage : si l'utilisateur n'a pas encore rempli son profil, ouvrir la fenêtre.
        if (_config.ShowProfile && TicketService.IsConfigured)
        {
            _ = MaybeShowProfileFirstRunAsync();
        }
    }

    private static UIElement CreateSupportVisual(double size)
    {
        var grid = new Grid { Width = size, Height = size };

        var ellipse = new System.Windows.Shapes.Ellipse
        {
            Fill = new LinearGradientBrush(
                Color.FromRgb(235, 120, 60),
                Color.FromRgb(180, 40, 30),
                new Point(0.5, 0), new Point(0.5, 1)),
            Stroke = new SolidColorBrush(Color.FromArgb(80, 255, 255, 255)),
            StrokeThickness = 1
        };

        var text = new TextBlock
        {
            Text = "?",
            Foreground = Brushes.White,
            FontSize = size * 0.62,
            FontFamily = new FontFamily("Segoe UI"),
            FontWeight = FontWeights.Bold,
            HorizontalAlignment = HorizontalAlignment.Center,
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(0, 0, 0, size * 0.05)
        };

        grid.Children.Add(ellipse);
        grid.Children.Add(text);
        return grid;
    }

    private static UIElement CreateProfileVisual(double size)
    {
        var grid = new Grid { Width = size, Height = size };

        var ellipse = new System.Windows.Shapes.Ellipse
        {
            Fill = new LinearGradientBrush(
                Color.FromRgb(78, 139, 255),
                Color.FromRgb(36, 78, 180),
                new Point(0.5, 0), new Point(0.5, 1)),
            Stroke = new SolidColorBrush(Color.FromArgb(80, 255, 255, 255)),
            StrokeThickness = 1
        };

        // Silhouette stylisée (tête + buste) en path
        var path = new System.Windows.Shapes.Path
        {
            Fill = Brushes.White,
            Width = size * 0.55,
            Height = size * 0.55,
            Stretch = Stretch.Uniform,
            HorizontalAlignment = HorizontalAlignment.Center,
            VerticalAlignment = VerticalAlignment.Center,
            Data = Geometry.Parse(
                "M 50 18 C 38 18 30 27 30 38 C 30 49 38 58 50 58 " +
                "C 62 58 70 49 70 38 C 70 27 62 18 50 18 Z " +
                "M 50 64 C 30 64 14 78 14 96 L 86 96 C 86 78 70 64 50 64 Z")
        };

        grid.Children.Add(ellipse);
        grid.Children.Add(path);
        return grid;
    }

    private async System.Threading.Tasks.Task MaybeShowProfileFirstRunAsync()
    {
        try
        {
            var ad = ActiveDirectoryService.GetCurrentUser();
            if (string.IsNullOrWhiteSpace(ad.Matricule)) return;

            // Si l'utilisateur a déjà rempli son profil dans les 90 derniers jours, ne rien faire.
            if (ProfileFlag.IsCompletedRecently(ad.Matricule, TimeSpan.FromDays(90))) return;

            // Vérification base : un enregistrement existe déjà ?
            var existing = await DirectoryProfileService.LoadAsync(ad.Matricule);
            if (existing != null && !string.IsNullOrWhiteSpace(existing.PhoneFixed) &&
                existing.CommissariatId.HasValue)
            {
                // Profil déjà rempli, on marque pour les prochaines fois
                ProfileFlag.MarkCompleted(ad.Matricule);
                return;
            }

            // Sinon, ouvrir la fenêtre profil après un petit délai pour ne pas bloquer le démarrage
            await System.Threading.Tasks.Task.Delay(2000);
            Dispatcher.Invoke(() => ShowProfileWindow());
        }
        catch
        {
            // best-effort, ne jamais bloquer le démarrage du dock
        }
    }

    private void ShowProfileWindow()
    {
        if (!TicketService.IsConfigured)
        {
            MessageBox.Show(
                "L'accès à la base est nécessaire pour gérer votre profil annuaire.\n\n" +
                "Demandez à votre administrateur de configurer DockPolice.",
                "DockPolice — Annuaire",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
            return;
        }
        try
        {
            var w = new ProfileWindow();
            w.Owner = null; // pas de parent (la dock window est chromeless/topmost)
            w.ShowInTaskbar = true;
            w.ShowDialog();
        }
        catch (Exception ex)
        {
            MessageBox.Show("Impossible d'ouvrir la fenêtre profil : " + ex.Message,
                "DockPolice — Annuaire", MessageBoxButton.OK, MessageBoxImage.Error);
        }
    }

    private void ShowTicketWindow(bool openMyTickets = false, int focusTicketId = 0)
    {
        if (!TicketService.IsConfigured)
        {
            MessageBox.Show(
                "Le système de tickets n'est pas configuré.\n\n" +
                "Demandez à votre administrateur de renseigner le champ\n" +
                "\"TicketConnectionString\" dans apps.json.",
                "DockPolice - SAV",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            return;
        }

        try
        {
            // Si la fenêtre est déjà ouverte, on la ramène au premier plan
            if (_openTicketWindow != null && _openTicketWindow.IsVisible)
            {
                if (openMyTickets) _openTicketWindow.SwitchToMyTickets(focusTicketId);
                _openTicketWindow.Activate();
                _openTicketWindow.Topmost = true;
                _openTicketWindow.Topmost = false;
                return;
            }

            _openTicketWindow = new TicketWindow(_config.CommissariatCode);
            _openTicketWindow.Closed += (_, _) => _openTicketWindow = null;
            if (openMyTickets) _openTicketWindow.SwitchToMyTickets(focusTicketId);
            _openTicketWindow.Show();
        }
        catch (Exception ex)
        {
            MessageBox.Show(this,
                $"Impossible d'ouvrir la fenêtre SAV :\n\n{ex.Message}\n\n{ex.StackTrace}",
                "DockPolice - Erreur",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
    }

    private static UIElement CreateInfoVisual(double size)
    {
        var grid = new Grid { Width = size, Height = size };

        var ellipse = new System.Windows.Shapes.Ellipse
        {
            Fill = new LinearGradientBrush(
                Color.FromRgb(80, 150, 230),
                Color.FromRgb(30, 80, 170),
                new Point(0.5, 0), new Point(0.5, 1)),
            Stroke = new SolidColorBrush(Color.FromArgb(80, 255, 255, 255)),
            StrokeThickness = 1
        };

        var text = new TextBlock
        {
            Text = "i",
            Foreground = Brushes.White,
            FontSize = size * 0.62,
            FontFamily = new FontFamily("Georgia"),
            FontStyle = FontStyles.Italic,
            FontWeight = FontWeights.Bold,
            HorizontalAlignment = HorizontalAlignment.Center,
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(0, 0, 0, size * 0.04)
        };

        grid.Children.Add(ellipse);
        grid.Children.Add(text);
        return grid;
    }

    private void ShowSystemInfo()
    {
        var win = new SystemInfoWindow { Owner = this };
        win.ShowDialog();
    }

    private void UpdateArrowVisibility()
    {
        ArrowTrigger.Visibility = (_config.AutoHide && _config.Position == DockPosition.Top)
            ? Visibility.Visible
            : Visibility.Collapsed;
    }

    private ContextMenu BuildItemContextMenu(DockItem item)
    {
        var menu = new ContextMenu();
        var remove = new MenuItem { Header = $"Supprimer « {item.Name} »" };
        remove.Click += (_, __) =>
        {
            _config.Items.Remove(item);
            ConfigService.Save(_config);
            Rebuild();
        };
        menu.Items.Add(remove);
        return menu;
    }

    private void Launch(DockItem item)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = item.Path,
                Arguments = item.Arguments ?? "",
                UseShellExecute = true,
                WorkingDirectory = Path.GetDirectoryName(item.Path) ?? ""
            };
            Process.Start(psi);
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Impossible de lancer {item.Name} :\n{ex.Message}",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private void UpdatePosition()
    {
        UpdateLayout();
        var screen = SystemParameters.WorkArea;
        var w = ActualWidth > 0 ? ActualWidth : RootStack.ActualWidth;
        var h = ActualHeight > 0 ? ActualHeight : RootStack.ActualHeight;
        var dockH = DockBorder.ActualHeight;

        double left = screen.Left;
        double top = screen.Top;

        switch (_config.Position)
        {
            case DockPosition.Top:
                left = screen.Left + (screen.Width - w) / 2;
                top = screen.Top;
                break;
            case DockPosition.Bottom:
                left = screen.Left + (screen.Width - w) / 2;
                top = screen.Bottom - h;
                break;
            case DockPosition.Left:
                left = screen.Left;
                top = screen.Top + (screen.Height - h) / 2;
                break;
            case DockPosition.Right:
                left = screen.Right - w;
                top = screen.Top + (screen.Height - h) / 2;
                break;
        }

        Left = left;
        _shownTop = top;
        _hiddenTop = top - dockH;

        Top = (_config.AutoHide && _config.Position == DockPosition.Top && !_isShown)
            ? _hiddenTop
            : _shownTop;
    }

    protected override void OnContentRendered(EventArgs e)
    {
        base.OnContentRendered(e);
        UpdatePosition();
        if (_config.AutoHide && _config.Position == DockPosition.Top)
        {
            _isShown = true;
            HideDock(instant: true);
        }
    }

    private void ItemsHost_MouseMove(object sender, MouseEventArgs e)
    {
        if (!_config.MagnifyOnHover || _icons.Count == 0) return;

        var panel = VisualHelper.FindChild<StackPanel>(ItemsHost);
        if (panel == null) return;

        var horizontal = panel.Orientation == Orientation.Horizontal;
        var mouse = e.GetPosition(panel);

        const double maxScale = 1.7;
        const double influence = 140;

        foreach (var icon in _icons)
        {
            var iconPos = icon.TranslatePoint(
                new Point(icon.ActualWidth / 2, icon.ActualHeight / 2), panel);
            var distance = horizontal
                ? Math.Abs(mouse.X - iconPos.X)
                : Math.Abs(mouse.Y - iconPos.Y);

            double scale = 1.0;
            if (distance < influence)
            {
                var t = 1.0 - (distance / influence);
                scale = 1.0 + (maxScale - 1.0) * Math.Pow(t, 2);
            }

            icon.ApplyScale(scale);
        }
    }

    private void ItemsHost_MouseLeave(object sender, MouseEventArgs e)
    {
        foreach (var icon in _icons)
            icon.ApplyScale(1.0);
    }

    private void Window_DragEnter(object sender, DragEventArgs e)
    {
        e.Effects = e.Data.GetDataPresent(DataFormats.FileDrop) ? DragDropEffects.Copy : DragDropEffects.None;
        e.Handled = true;
    }

    private void Window_Drop(object sender, DragEventArgs e)
    {
        if (!e.Data.GetDataPresent(DataFormats.FileDrop)) return;
        var files = (string[])e.Data.GetData(DataFormats.FileDrop);
        foreach (var f in files)
            AddPath(f);
        ConfigService.Save(_config);
        Rebuild();
    }

    private void AddPath(string path)
    {
        if (string.IsNullOrWhiteSpace(path)) return;
        var name = Path.GetFileNameWithoutExtension(path);
        if (string.IsNullOrEmpty(name)) name = Path.GetFileName(path);
        _config.Items.Add(new DockItem { Name = name, Path = path });
    }

    private void AddApp_Click(object sender, RoutedEventArgs e)
    {
        var dlg = new OpenFileDialog
        {
            Filter = "Applications (*.exe;*.lnk;*.bat;*.cmd)|*.exe;*.lnk;*.bat;*.cmd|Tous les fichiers (*.*)|*.*",
            Title = "Choisir une application"
        };
        if (dlg.ShowDialog() == true)
        {
            AddPath(dlg.FileName);
            ConfigService.Save(_config);
            Rebuild();
        }
    }

    private void Position_Click(object sender, RoutedEventArgs e)
    {
        if (sender is MenuItem mi && mi.Tag is DockPosition pos)
        {
            _config.Position = pos;
            ConfigService.Save(_config);
            DockBorder.ContextMenu = BuildDockContextMenu();
            _isShown = true;
            Rebuild();
            if (_config.AutoHide && _config.Position == DockPosition.Top)
                HideDock(instant: true);
        }
    }

    private void AutoHide_Click(object sender, RoutedEventArgs e)
    {
        if (_autoHideMenu == null) return;
        _config.AutoHide = _autoHideMenu.IsChecked;
        ConfigService.Save(_config);
        UpdateArrowVisibility();
        UpdatePosition();
        if (_config.AutoHide && _config.Position == DockPosition.Top)
        {
            _isShown = true;
            HideDock();
        }
        else
        {
            _isShown = false;
            ShowDock();
        }
    }

    private void Spacing_Click(object sender, RoutedEventArgs e)
    {
        if (sender is MenuItem mi && mi.Tag is int value)
        {
            _config.IconSpacing = value;
            ConfigService.Save(_config);
            DockBorder.ContextMenu = BuildDockContextMenu();
            ArrowTrigger.ContextMenu = BuildDockContextMenu();
            Rebuild();
        }
    }

    private void ShowSystemInfoToggle_Click(object sender, RoutedEventArgs e)
    {
        if (sender is MenuItem mi)
        {
            _config.ShowSystemInfo = mi.IsChecked;
            ConfigService.Save(_config);
            Rebuild();
        }
    }

    private void ShowSupportToggle_Click(object sender, RoutedEventArgs e)
    {
        if (sender is MenuItem mi)
        {
            _config.ShowSupportTicket = mi.IsChecked;
            ConfigService.Save(_config);
            Rebuild();
        }
    }

    private void Magnify_Click(object sender, RoutedEventArgs e)
    {
        if (_magnifyMenu == null) return;
        _config.MagnifyOnHover = _magnifyMenu.IsChecked;
        ConfigService.Save(_config);
        if (!_config.MagnifyOnHover)
            foreach (var icon in _icons) icon.ApplyScale(1.0);
    }

    private void Quit_Click(object sender, RoutedEventArgs e) => Close();

    private void DetectPolice_Click(object sender, RoutedEventArgs e)
    {
        int added = AppDiscovery.MergeInto(_config);
        if (added > 0)
        {
            ConfigService.Save(_config);
            Rebuild();
            MessageBox.Show($"{added} logiciel(s) métier(s) ajouté(s) au dock.",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Information);
        }
        else
        {
            MessageBox.Show("Aucun nouveau logiciel métier détecté.",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Information);
        }
    }

    private void OpenJson_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = ConfigService.ConfigFilePath,
                UseShellExecute = true
            };
            Process.Start(psi);
        }
        catch (Exception ex)
        {
            MessageBox.Show($"Impossible d'ouvrir {ConfigService.ConfigFilePath} :\n{ex.Message}",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private void ArrowTrigger_MouseEnter(object sender, MouseEventArgs e)
    {
        if (_config.AutoHide && _config.Position == DockPosition.Top)
            ShowDock();
    }

    private void ArrowTrigger_MouseLeave(object sender, MouseEventArgs e)
    {
        if (_config.AutoHide && _config.Position == DockPosition.Top && !DockBorder.IsMouseOver)
            HideDock();
    }

    private void DockBorder_MouseLeave(object sender, MouseEventArgs e)
    {
        if (_config.AutoHide && _config.Position == DockPosition.Top && !ArrowTrigger.IsMouseOver)
            HideDock();
    }

    private void ShowDock()
    {
        if (_isShown) return;
        _isShown = true;
        var anim = new DoubleAnimation(_shownTop, TimeSpan.FromMilliseconds(220))
        {
            EasingFunction = new QuadraticEase { EasingMode = EasingMode.EaseOut }
        };
        BeginAnimation(TopProperty, anim);
    }

    private void HideDock(bool instant = false)
    {
        if (!_isShown && !instant) return;
        _isShown = false;
        var duration = instant ? TimeSpan.Zero : TimeSpan.FromMilliseconds(240);
        var anim = new DoubleAnimation(_hiddenTop, duration)
        {
            EasingFunction = new QuadraticEase { EasingMode = EasingMode.EaseIn }
        };
        BeginAnimation(TopProperty, anim);
    }
}
