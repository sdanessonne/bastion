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
        // ── CE QUE CE DOCK FAIT, ET CE QU'IL NE FAIT PLUS ────────────────────
        // Le code d'origine ouvrait ici huit canaux vers un backoffice : télémétrie
        // machine, commandes à distance, flux d'écran, pilotage clavier/souris,
        // tickets, bandeaux, habilitations, mises à jour.
        //
        // Bastion assure déjà chacun de ces rôles, avec sa propre source de vérité :
        // l'inventaire du parc, la prise de main par relais RustDesk (dont le
        // consentement par groupe a été construit exprès), les demandes
        // d'assistance, l'intranet, le store. Garder les deux ferait remonter DEUX
        // inventaires qui divergeraient, et ouvrirait un SECOND canal de prise de
        // main à côté du premier — sans que rien ne le signale.
        //
        // Ce dock est donc un LANCEUR : il affiche des icônes et démarre des
        // programmes. Il ne joint aucun serveur, n'ouvre aucun port, ne remonte
        // rien. Ce qui doit remonter passe par les mécanismes de Bastion.
        _config = ConfigService.Load();
        DockBorder.ContextMenu = BuildDockContextMenu();
        ArrowTrigger.ContextMenu = BuildDockContextMenu();
        Rebuild();
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

        // Les icônes « Demande SAV » et « Mon profil annuaire » ont été retirées :
        // elles ouvraient des fenêtres branchées sur le backoffice d'origine. Dans
        // Bastion, une demande d'assistance se dépose sur l'intranet et la fiche de
        // l'agent vit dans l'annuaire — un second chemin donnerait deux endroits où
        // chercher la même chose, et deux jeux de données à réconcilier.

        UpdateArrowVisibility();
        UpdatePosition();
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
