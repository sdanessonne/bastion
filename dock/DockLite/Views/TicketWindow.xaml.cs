using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Controls;
using DockLite.Models;
using DockLite.Services;

namespace DockLite.Views;

public partial class TicketWindow : Window
{
    private readonly string _commissariatCode;
    private Commissariat? _commissariat;
    private bool _myTicketsLoaded;
    private bool _submitting;

    public TicketWindow(string commissariatCode)
    {
        InitializeComponent();
        _commissariatCode = commissariatCode ?? "";

        CategoryBox.ItemsSource = new[]
        {
            "Matériel", "Logiciel métier", "Logiciel bureautique",
            "Réseau / Internet", "Impression / Scanner",
            "Compte / Accès", "Messagerie", "Autre"
        };
        CategoryBox.SelectedIndex = 0;

        PriorityBox.ItemsSource = new[] { "Basse", "Normale", "Haute", "Urgente" };
        PriorityBox.SelectedIndex = 1;

        PopulateContext();
        _ = LoadCommissariatAsync();
        _ = PreFillEmailFromDirectoryAsync();
        ApplyWindowsThemeAccent();
        ShowUpdatePendingBannerIfNeeded();
    }

    private void ShowUpdatePendingBannerIfNeeded()
    {
        try
        {
            if (AutoUpdateService.HasPendingUpdate())
            {
                var v = AutoUpdateService.PendingVersion();
                UpdatePendingBanner.Visibility = Visibility.Visible;
                if (!string.IsNullOrWhiteSpace(v))
                    UpdatePendingText.Text = $"✓ Mise à jour {v} prête — elle s'installera au prochain démarrage de DockPolice.";
            }
        }
        catch { }
    }

    // ============================================================
    // Bouton "Permettre prise en main" → modal avec code 6 chiffres + countdown
    // ============================================================
    private async void RemoteAssistBtn_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            RemoteAssistBtn.IsEnabled = false;
            RemoteAssistBtn.Content = "⏳ Génération…";

            var matricule = Environment.UserName;
            var machine   = Environment.MachineName;
            var result = await RemoteAssistCodeService.RequestAsync(matricule, machine);
            if (result == null || !result.Ok) throw new Exception("Réponse vide.");

            ShowRemoteAssistDialog(result.Code, result.ExpiresIn);
        }
        catch (Exception ex)
        {
            MessageBox.Show("Impossible de générer le code : " + ex.Message,
                "Prise en main", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            RemoteAssistBtn.IsEnabled = true;
            RemoteAssistBtn.Content = BuildRemoteAssistBtnContent();
        }
    }

    private System.Windows.Controls.StackPanel BuildRemoteAssistBtnContent()
    {
        var sp = new System.Windows.Controls.StackPanel { Orientation = System.Windows.Controls.Orientation.Horizontal };
        sp.Children.Add(new System.Windows.Controls.TextBlock { Text = "🖥️", FontSize = 14, Margin = new Thickness(0, 0, 6, 0) });
        sp.Children.Add(new System.Windows.Controls.TextBlock { Text = "Permettre prise en main", FontWeight = FontWeights.SemiBold });
        return sp;
    }

    private void ShowRemoteAssistDialog(string code, int expiresIn)
    {
        var dlg = new Window
        {
            Title = "Code de prise en main",
            Width = 460, Height = 380,
            WindowStartupLocation = WindowStartupLocation.CenterOwner,
            Owner = this,
            Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x0B, 0x0F, 0x19)),
            ResizeMode = ResizeMode.NoResize,
        };
        var root = new System.Windows.Controls.StackPanel { Margin = new Thickness(28) };
        root.Children.Add(new System.Windows.Controls.TextBlock
        {
            Text = "🖥️ Prise en main par le technicien", FontSize = 18, FontWeight = FontWeights.SemiBold,
            Foreground = System.Windows.Media.Brushes.White, Margin = new Thickness(0, 0, 0, 8)
        });
        root.Children.Add(new System.Windows.Controls.TextBlock
        {
            Text = "Communiquez ce code au technicien — il en a besoin pour se connecter à votre poste.",
            Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x94, 0xA3, 0xB8)),
            FontSize = 12, TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0, 0, 0, 18)
        });

        var codeText = new System.Windows.Controls.TextBlock
        {
            Text = code,
            FontSize = 56, FontWeight = FontWeights.Bold,
            Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x4E, 0x8B, 0xFF)),
            HorizontalAlignment = HorizontalAlignment.Center,
            FontFamily = new System.Windows.Media.FontFamily("Consolas")
        };
        var codeBorder = new System.Windows.Controls.Border
        {
            Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x1A, 0x24, 0x40)),
            BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x4E, 0x8B, 0xFF)),
            BorderThickness = new Thickness(2),
            CornerRadius = new System.Windows.CornerRadius(12),
            Padding = new Thickness(20, 14, 20, 14),
            Child = codeText,
            HorizontalAlignment = HorizontalAlignment.Center,
        };
        root.Children.Add(codeBorder);

        var copyBtn = new System.Windows.Controls.Button
        {
            Content = "📋 Copier le code", Padding = new Thickness(14, 6, 14, 6),
            HorizontalAlignment = HorizontalAlignment.Center, Margin = new Thickness(0, 12, 0, 0),
            Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x1A, 0x21, 0x37)),
            Foreground = System.Windows.Media.Brushes.White,
            BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x2A, 0x33, 0x50)),
        };
        copyBtn.Click += (_, _) => { try { Clipboard.SetText(code); copyBtn.Content = "✓ Copié"; } catch { } };
        root.Children.Add(copyBtn);

        var countdown = new System.Windows.Controls.TextBlock
        {
            FontSize = 13, HorizontalAlignment = HorizontalAlignment.Center,
            Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0xF5, 0x9E, 0x0B)),
            Margin = new Thickness(0, 18, 0, 0),
        };
        root.Children.Add(countdown);

        var closeBtn = new System.Windows.Controls.Button
        {
            Content = "Fermer", Padding = new Thickness(20, 8, 20, 8),
            HorizontalAlignment = HorizontalAlignment.Center, Margin = new Thickness(0, 18, 0, 0),
        };
        closeBtn.Click += (_, _) => dlg.Close();
        root.Children.Add(closeBtn);

        dlg.Content = root;

        // Compte à rebours
        int remaining = expiresIn;
        var timer = new System.Windows.Threading.DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
        timer.Tick += (_, _) =>
        {
            remaining--;
            countdown.Text = remaining > 0
                ? $"⏱ Code valable encore {remaining / 60}m {remaining % 60:D2}s"
                : "⚠ Code expiré — fermez puis regénérez si besoin.";
            if (remaining <= 0)
            {
                timer.Stop();
                codeText.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x64, 0x74, 0x8B));
            }
        };
        timer.Start();
        dlg.Closed += (_, _) => timer.Stop();
        countdown.Text = $"⏱ Code valable encore {remaining / 60}m {remaining % 60:D2}s";

        dlg.ShowDialog();
    }

    /// <summary>
    /// Adapte les couleurs de l'app à la préférence Windows Light / Dark.
    /// (Le thème de base reste sombre — on ajuste l'accent et le fond pour un mode clair confort.)
    /// </summary>
    private void ApplyWindowsThemeAccent()
    {
        try
        {
            var theme = WindowsThemeService.GetCurrentTheme();
            // En mode Light Windows, on adoucit le fond noir (gris très foncé) pour moins de contraste extrême
            // (le thème reste cohérent et lisible — on ne bascule pas en blanc complet)
            if (theme == WindowsThemeService.WindowsTheme.Light)
            {
                this.Background = new System.Windows.Media.SolidColorBrush(
                    System.Windows.Media.Color.FromRgb(0x1A, 0x21, 0x37)); // #1A2137
            }
            else if (theme == WindowsThemeService.WindowsTheme.HighContrast)
            {
                this.Background = System.Windows.Media.Brushes.Black;
                this.Foreground = System.Windows.Media.Brushes.White;
            }
        }
        catch { }
    }

    // ============================================================
    // Drag & Drop : un fichier glissé sur la fenêtre → nouvelle demande pré-remplie
    // ============================================================
    private void Window_DragEnter(object sender, System.Windows.DragEventArgs e)
    {
        if (e.Data.GetDataPresent(System.Windows.DataFormats.FileDrop))
        {
            e.Effects = System.Windows.DragDropEffects.Copy;
            DropOverlay.Visibility = Visibility.Visible;
        }
        else e.Effects = System.Windows.DragDropEffects.None;
        e.Handled = true;
    }

    private void Window_DragLeave(object sender, System.Windows.DragEventArgs e)
    {
        DropOverlay.Visibility = Visibility.Collapsed;
    }

    private void Window_Drop(object sender, System.Windows.DragEventArgs e)
    {
        DropOverlay.Visibility = Visibility.Collapsed;
        if (!e.Data.GetDataPresent(System.Windows.DataFormats.FileDrop)) return;
        var files = e.Data.GetData(System.Windows.DataFormats.FileDrop) as string[];
        if (files == null || files.Length == 0) return;

        // Stocke pour upload après création — envoyés via AttachmentApi.UploadAsync au submit
        _pendingDroppedFiles = files.Where(System.IO.File.Exists).ToList();

        // Bascule sur l'onglet "Nouvelle demande" et pré-affiche le nom du fichier
        if (_pendingDroppedFiles.Count > 0)
        {
            var firstName = System.IO.Path.GetFileName(_pendingDroppedFiles[0]);
            SubjectBox.Focus();
            // Insère un message dans la description si vide
            if (string.IsNullOrWhiteSpace(DescriptionBox.Text))
            {
                DescriptionBox.Text = "Pièce(s) jointe(s) : " + string.Join(", ", _pendingDroppedFiles.Select(System.IO.Path.GetFileName));
            }
            SetStatus($"📎 {_pendingDroppedFiles.Count} fichier(s) joint(s) — la demande sera créée avec ces pièces.", "loading");
        }
    }

    private List<string> _pendingDroppedFiles = new();

    /// <summary>
    /// Pré-remplit le champ E-mail à partir de la fiche annuaire (table contacts).
    /// Ne touche PAS le champ si l'utilisateur a déjà saisi quelque chose.
    /// </summary>
    private async System.Threading.Tasks.Task PreFillEmailFromDirectoryAsync()
    {
        try
        {
            var profile = await DirectoryProfileService.LoadAsync(Environment.UserName);
            if (profile == null) return;
            if (!string.IsNullOrWhiteSpace(profile.Email)
                && string.IsNullOrWhiteSpace(EmailBox?.Text))
            {
                EmailBox.Text = profile.Email;
                EmailBox.ToolTip = "Adresse pré-remplie depuis votre fiche annuaire — modifiable dans votre profil.";
            }
        }
        catch { /* silencieux : si l'annuaire n'est pas atteignable, on laisse l'utilisateur saisir */ }
    }

    private void PopulateContext()
    {
        MachineText.Text = Environment.MachineName;
        UserText.Text = Environment.UserName;
        OsText.Text = RuntimeInformation.OSDescription;

        var info = SystemInfo.Collect();
        IpText.Text = info.FirstOrDefault(e => e.Label == "Adresse IP")?.Value ?? "—";

        CommissariatText.Text = "Détection en cours...";
    }

    private async System.Threading.Tasks.Task LoadCommissariatAsync()
    {
        try
        {
            // Plusieurs IPs peuvent être présentes (Wi-Fi, VirtualBox, WSL…). On teste
            // dans l'ordre de priorité (passerelle par défaut + adaptateur physique) et
            // on garde la première qui matche une plage commissariat en base.
            foreach (var ip in SystemInfo.GetCandidateIPv4s())
            {
                var byIp = await TicketService.ResolveByIpAsync(ip);
                if (byIp != null)
                {
                    _commissariat = byIp;
                    CommissariatText.Text = $"{byIp.Name}"
                        + (string.IsNullOrEmpty(byIp.Coverage) ? "" : $"  —  {byIp.Coverage}")
                        + $"   🔍 détecté auto via IP {ip}";
                    return;
                }
            }

            if (!string.IsNullOrEmpty(_commissariatCode))
            {
                _commissariat = await TicketService.FindCommissariatByCodeAsync(_commissariatCode);
                if (_commissariat != null)
                {
                    CommissariatText.Text = $"{_commissariat.Name}   ✋ choisi manuellement";
                    return;
                }
            }

            CommissariatText.Text = "⚠ Non défini — aucune IP du poste ne correspond à une plage enregistrée";
        }
        catch (Exception ex)
        {
            CommissariatText.Text = $"⚠ {ex.Message}";
        }
    }

    private void SetStatus(string text, string kind = "error")
    {
        if (string.IsNullOrEmpty(text))
        {
            StatusLabel.Text = "";
            StatusPill.Visibility = Visibility.Collapsed;
            return;
        }
        StatusLabel.Text = text;
        StatusPill.Visibility = Visibility.Visible;
        switch (kind)
        {
            case "loading":
                StatusPill.Background  = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x1A, 0x21, 0x37));
                StatusPill.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x2A, 0x33, 0x50));
                StatusLabel.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x96, 0xA1, 0xC0));
                break;
            case "success":
                StatusPill.Background  = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromArgb(0x33, 0x10, 0xB9, 0x81));
                StatusPill.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
                StatusLabel.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x34, 0xD3, 0x99));
                break;
            default: // error
                StatusPill.Background  = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromArgb(0x33, 0xEF, 0x44, 0x44));
                StatusPill.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
                StatusLabel.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0xFC, 0xA5, 0xA5));
                break;
        }
    }

    private async void Submit_Click(object sender, RoutedEventArgs e)
    {
        // Garde anti-double-clic / double-Entrée
        if (_submitting) return;

        SetStatus("");

        if (string.IsNullOrWhiteSpace(SubjectBox.Text))
        {
            SetStatus("Le sujet est obligatoire.");
            SubjectBox.Focus();
            return;
        }
        if (string.IsNullOrWhiteSpace(DescriptionBox.Text))
        {
            SetStatus("La description est obligatoire.");
            DescriptionBox.Focus();
            return;
        }
        if (!TicketService.IsConfigured)
        {
            SetStatus("Connexion MySQL non configurée dans apps.json.");
            return;
        }

        _submitting = true;
        SubmitButton.IsEnabled = false;
        SubmitButton.Content = "Analyse…";
        SetStatus("Vérification des doublons et suggestion auto…", "loading");

        var subject     = SubjectBox.Text.Trim();
        var description = DescriptionBox.Text.Trim();
        var category    = CategoryBox.SelectedItem?.ToString() ?? "Matériel";
        var priority    = PriorityBox.SelectedItem?.ToString() ?? "Normale";

        // 1. Classification automatique : si l'utilisateur est resté sur les défauts,
        //    on adopte la suggestion (catégorie & priorité calculées à partir du texte).
        var classif = await TicketIntelligence.ClassifyAsync(subject, description);
        if (classif != null && classif.Confidence >= 50)
        {
            if (category == "Matériel" && !string.IsNullOrEmpty(classif.Category))
            {
                category = classif.Category;
                var idx = CategoryBox.Items.IndexOf(category);
                if (idx >= 0) CategoryBox.SelectedIndex = idx;
            }
            if (priority == "Normale" && !string.IsNullOrEmpty(classif.Priority) && classif.Priority != "Normale")
            {
                priority = classif.Priority;
                var idx = PriorityBox.Items.IndexOf(priority);
                if (idx >= 0) PriorityBox.SelectedIndex = idx;
            }
        }

        // 2. Détection de doublons sur la même machine
        var similar = await TicketIntelligence.FindSimilarAsync(Environment.MachineName, subject, category);
        if (similar.Count > 0)
        {
            var first = similar[0];
            var msg = $"Un ticket similaire existe déjà pour ce poste :\n\n" +
                      $"#{first.Id} — {first.Subject}\n" +
                      $"État : {first.Status} · Priorité : {first.Priority}\n\n" +
                      "Voulez-vous quand même créer un nouveau ticket ?";
            var choice = MessageBox.Show(msg, "DockPolice — Doublon possible",
                MessageBoxButton.YesNo, MessageBoxImage.Question, MessageBoxResult.No);
            if (choice == MessageBoxResult.No)
            {
                _submitting = false;
                SubmitButton.IsEnabled = true;
                SubmitButton.Content = "Envoyer la demande";
                SetStatus("Création annulée — consultez le ticket existant dans « Mes tickets ».", "loading");
                return;
            }
        }

        SubmitButton.Content = "Envoi…";
        SetStatus("Envoi en cours…", "loading");

        try
        {
            var ticket = new SupportTicket
            {
                MachineName = Environment.MachineName,
                UserName = Environment.UserName,
                IpAddress = IpText.Text,
                CommissariatId = _commissariat?.Id,
                Email = (EmailBox.Text ?? "").Trim(),
                Category = category,
                Priority = priority,
                Subject = subject,
                Description = description
            };

            int id = await TicketService.CreateTicketAsync(ticket);

            // Upload des fichiers droppés sur la fenêtre (drag & drop)
            if (_pendingDroppedFiles.Count > 0 && AttachmentApi.IsConfigured)
            {
                foreach (var f in _pendingDroppedFiles)
                {
                    try
                    {
                        var bytes = await System.IO.File.ReadAllBytesAsync(f);
                        var name  = System.IO.Path.GetFileName(f);
                        await AttachmentApi.UploadAsync(id, Environment.UserName, bytes, name);
                    }
                    catch { /* best-effort */ }
                }
                _pendingDroppedFiles.Clear();
            }

            // Reset du formulaire et bascule sur "Mes tickets"
            SubjectBox.Text = "";
            DescriptionBox.Text = "";
            _myTicketsLoaded = false;
            SwitchToMyTickets(id);

            MessageBox.Show(
                $"Ticket n°{id} créé avec succès.\n\nUn technicien le prendra en charge rapidement.",
                "DockPolice - SAV",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
        catch (Exception ex)
        {
            // Mode offline : si l'envoi échoue (MySQL injoignable), on stocke
            // localement et on retentera périodiquement en tâche de fond.
            try
            {
                var ticket = new SupportTicket
                {
                    MachineName = Environment.MachineName,
                    UserName = Environment.UserName,
                    IpAddress = IpText.Text,
                    CommissariatId = _commissariat?.Id,
                    Email = (EmailBox.Text ?? "").Trim(),
                    Category = category,
                    Priority = priority,
                    Subject = subject,
                    Description = description
                };
                OfflineTicketQueue.Enqueue(ticket, ex.Message);
                SubjectBox.Text = "";
                DescriptionBox.Text = "";
                SetStatus("Hors ligne : ticket sauvegardé localement, envoi automatique dès le rétablissement réseau.", "loading");
            }
            catch
            {
                SetStatus($"Erreur : {ex.Message}");
            }
        }
        finally
        {
            _submitting = false;
            SubmitButton.IsEnabled = true;
            SubmitButton.Content = "Envoyer la demande";
        }
    }

    /// <summary>
    /// Toggle l'affichage du rapport technique complet (CPU, RAM, BIOS, disques…)
    /// dans l'onglet "Nouvelle demande". Premier clic : remplit la grille.
    /// </summary>
    private void BtnToggleSysInfo_Click(object sender, RoutedEventArgs e)
    {
        if (SysInfoPanel.Visibility == Visibility.Visible)
        {
            SysInfoPanel.Visibility = Visibility.Collapsed;
            BtnToggleSysInfo.Content = "▼ Afficher les détails techniques complets (CPU, RAM, BIOS, disques…)";
            return;
        }

        // Première ouverture : peuple la grille
        if (SysInfoGrid.Children.Count == 0)
        {
            try
            {
                var info = SystemInfo.Collect();
                for (int i = 0; i < info.Count; i++)
                {
                    SysInfoGrid.RowDefinitions.Add(new System.Windows.Controls.RowDefinition { Height = System.Windows.GridLength.Auto });

                    var label = new System.Windows.Controls.TextBlock
                    {
                        Text = info[i].Label,
                        Foreground = (System.Windows.Media.Brush)FindResource("TextDim"),
                        FontSize = 11,
                        Margin = new System.Windows.Thickness(0, 3, 12, 3),
                    };
                    System.Windows.Controls.Grid.SetRow(label, i);
                    System.Windows.Controls.Grid.SetColumn(label, 0);
                    SysInfoGrid.Children.Add(label);

                    var value = new System.Windows.Controls.TextBlock
                    {
                        Text = info[i].Value,
                        Foreground = (System.Windows.Media.Brush)FindResource("Text"),
                        FontSize = 12,
                        TextWrapping = System.Windows.TextWrapping.Wrap,
                        Margin = new System.Windows.Thickness(0, 3, 0, 3),
                    };
                    System.Windows.Controls.Grid.SetRow(value, i);
                    System.Windows.Controls.Grid.SetColumn(value, 1);
                    SysInfoGrid.Children.Add(value);
                }
            }
            catch (Exception ex)
            {
                SysInfoStatus.Visibility = Visibility.Visible;
                SysInfoStatus.Text = "⚠ " + ex.Message;
            }
        }
        SysInfoPanel.Visibility = Visibility.Visible;
        BtnToggleSysInfo.Content = "▲ Masquer les détails techniques";
    }

    /// <summary>
    /// Insère le rapport technique complet à la fin du champ Description du ticket.
    /// </summary>
    private void BtnAttachSysInfo_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var info = SystemInfo.Collect();
            var sb = new System.Text.StringBuilder();
            sb.AppendLine();
            sb.AppendLine("--- Informations machine (auto-jointes) ---");
            foreach (var entry in info)
                sb.AppendLine(string.Format("{0,-22} : {1}", entry.Label, entry.Value));
            sb.AppendLine("--- fin ---");

            var current = (DescriptionBox.Text ?? "").TrimEnd();
            DescriptionBox.Text = (current.Length > 0 ? current + "\n" : "") + sb.ToString();
            DescriptionBox.Focus();
            DescriptionBox.CaretIndex = DescriptionBox.Text.Length;

            BtnAttachSysInfo.Content = "✓ Joint";
            _ = Task.Run(async () =>
            {
                await Task.Delay(2000);
                Dispatcher.Invoke(() => BtnAttachSysInfo.Content = "📋 Joindre dans la description");
            });
        }
        catch (Exception ex)
        {
            MessageBox.Show("Erreur récupération infos machine : " + ex.Message,
                "Erreur", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    /// <summary>
    /// Lance l'installation/configuration de l'extension Firefox Coffre-fort + 2FA.
    /// Logique reprise de SystemInfoWindow (déclenche l'API vault-extension-deploy).
    /// </summary>
    // ============================================================
    // Market — bibliothèque XPI Firefox (Coffre-fort + BarrageNet)
    // ============================================================

    private async Task InstallMarketExtension(
        System.Windows.Controls.Button btn,
        System.Windows.Controls.ProgressBar progress,
        System.Windows.Controls.TextBlock subText,
        string deployEndpoint,        // ex : "vault-extension-deploy.php"
        string defaultSubText,
        System.Windows.Media.Color accentColor)
    {
        btn.IsEnabled = false;
        progress.Visibility = Visibility.Visible;
        progress.Value = 0;
        var origText = subText.Text;
        var origBg = btn.Background;

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
            if (baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase))
                baseUrl = baseUrl.Substring(0, baseUrl.Length - 4);
            if (string.IsNullOrEmpty(baseUrl) || string.IsNullOrEmpty(cfg.ApiKey))
            {
                btn.Content = "✗ Config manquante";
                subText.Text = "ApiBaseUrl et ApiKey requis dans apps.json";
                return;
            }

            btn.Content = "Installation…";
            subText.Text = steps[0].Item2;
            progress.Value = steps[0].Item1;

            var winUser = Environment.UserName;
            var machine = Environment.MachineName;
            // L'endpoint peut déjà contenir une query string (ex : market-deploy.php?code=xxx).
            // On choisit donc dynamiquement le séparateur ? ou &.
            var sep = deployEndpoint.Contains('?') ? '&' : '?';
            var url = $"{baseUrl}/api/{deployEndpoint}"
                    + $"{sep}machine={Uri.EscapeDataString(machine)}"
                    + $"&windows_user={Uri.EscapeDataString(winUser)}";

            using var http = new System.Net.Http.HttpClient();
            http.DefaultRequestHeaders.Add("X-API-Key", cfg.ApiKey);
            http.Timeout = TimeSpan.FromSeconds(15);

            var resp = await http.GetAsync(url);
            if (!resp.IsSuccessStatusCode)
            {
                btn.Content = $"✗ HTTP {(int)resp.StatusCode}";
                subText.Text = "Échec d'appel à DockPolice";
                progress.Foreground = new System.Windows.Media.SolidColorBrush(
                    System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
                await Task.Delay(4000);
                return;
            }

            var stepDelays = new[] { 200, 800, 2500, 4000, 2500, 2500, 500 };
            for (int i = 1; i < steps.Length; i++)
            {
                await AnimateProgressTo(progress, steps[i].Item1, stepDelays[i]);
                subText.Text = steps[i].Item2;
            }

            progress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
            btn.Content = "✓ Installé";
            btn.Background = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
            await Task.Delay(2500);
        }
        catch (Exception ex)
        {
            btn.Content = "✗ Erreur";
            subText.Text = ex.Message;
            progress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
            await Task.Delay(4000);
        }
        finally
        {
            btn.IsEnabled = true;
            // On ne réécrit plus btn.Content ni btn.Background ici : l'appelant
            // gère le label final selon le succès (« ✓ À jour ») ou l'échec.
            subText.Text = defaultSubText;
            progress.Visibility = Visibility.Collapsed;
            progress.Value = 0;
            progress.Foreground = new System.Windows.Media.SolidColorBrush(accentColor);
        }
    }

    private async Task UninstallMarketExtension(
        System.Windows.Controls.Button uninstallBtn,
        System.Windows.Controls.Button installBtn,
        System.Windows.Controls.ProgressBar progress,
        System.Windows.Controls.TextBlock subText,
        string code,
        string name)
    {
        var confirm = MessageBox.Show(
            $"Désinstaller « {name} » de Firefox sur ce poste ?\n\n" +
            "L'extension passera en mode bloqué et Firefox la désinstallera au prochain démarrage. " +
            "Vous pourrez la réinstaller à tout moment depuis le Market.",
            "Désinstallation",
            MessageBoxButton.YesNo, MessageBoxImage.Question);
        if (confirm != MessageBoxResult.Yes) return;

        uninstallBtn.IsEnabled = false;
        installBtn.IsEnabled   = false;
        var origText = subText.Text;
        subText.Text = "🗑️ Désinstallation demandée…";
        progress.Visibility = Visibility.Visible;
        progress.IsIndeterminate = true;
        progress.Foreground = new System.Windows.Media.SolidColorBrush(
            System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));

        try
        {
            var cfg = ConfigService.Load();
            var baseUrl = (cfg.ApiBaseUrl ?? "").TrimEnd('/');
            if (baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase))
                baseUrl = baseUrl.Substring(0, baseUrl.Length - 4);
            if (string.IsNullOrEmpty(baseUrl) || string.IsNullOrEmpty(cfg.ApiKey))
            {
                subText.Text = "✗ Configuration ApiBaseUrl/ApiKey manquante.";
                return;
            }
            var url = $"{baseUrl}/api/market-uninstall.php"
                    + $"?code={Uri.EscapeDataString(code)}"
                    + $"&machine={Uri.EscapeDataString(Environment.MachineName)}";

            using var http = new System.Net.Http.HttpClient { Timeout = TimeSpan.FromSeconds(15) };
            http.DefaultRequestHeaders.Add("X-API-Key", cfg.ApiKey);
            var resp = await http.GetAsync(url);
            if (!resp.IsSuccessStatusCode)
            {
                subText.Text = $"✗ Erreur HTTP {(int)resp.StatusCode}";
                await Task.Delay(3500);
                return;
            }

            // Animation de progression simulée pendant que l'agent traite
            var steps = new[] {
                (15, "📡 Demande envoyée à l'agent…"),
                (35, "📜 Modification de policies.json…"),
                (60, "🗑️ Suppression du XPI…"),
                (85, "🦊 Fermeture de Firefox…"),
                (100, "✓ Désinstallation programmée"),
            };
            progress.IsIndeterminate = false;
            foreach (var (pct, msg) in steps)
            {
                await AnimateProgressTo(progress, pct, 700);
                subText.Text = msg;
            }

            uninstallBtn.Content = "✓ Désinstallée";
            uninstallBtn.Background = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
            uninstallBtn.Foreground = System.Windows.Media.Brushes.White;
            // Le bouton Installer redevient activable et reprend son label initial
            installBtn.Content = "Installer";
            installBtn.Background = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0x4E, 0x8B, 0xFF));
            await Task.Delay(2500);
            subText.Text = "L'extension sera supprimée au prochain démarrage de Firefox.";
        }
        catch (Exception ex)
        {
            subText.Text = "✗ " + ex.Message;
            progress.Foreground = new System.Windows.Media.SolidColorBrush(
                System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44));
            await Task.Delay(4000);
        }
        finally
        {
            installBtn.IsEnabled = true;
            uninstallBtn.IsEnabled = true;
            progress.Visibility = Visibility.Collapsed;
            progress.Value = 0;
            progress.IsIndeterminate = false;
        }
    }

    private void DownloadMarketXpi(string xpiEndpoint)
    {
        try
        {
            var cfg = ConfigService.Load();
            var baseUrl = (cfg.ApiBaseUrl ?? "").TrimEnd('/');
            if (baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase))
                baseUrl = baseUrl.Substring(0, baseUrl.Length - 4);
            if (string.IsNullOrEmpty(baseUrl)) return;
            var url = $"{baseUrl}/api/{xpiEndpoint}";
            // Ouvre l'URL dans le navigateur par défaut → l'utilisateur récupère le .xpi
            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
            {
                FileName = url,
                UseShellExecute = true,
            });
        }
        catch { /* silencieux */ }
    }

    // ============================================================
    // Chargement dynamique du catalogue Market depuis l'API
    // ============================================================

    private bool _marketLoaded = false;

    private async void MarketTab_GotFocus(object sender, RoutedEventArgs e)
    {
        if (_marketLoaded) return;
        await LoadMarketCatalog();
    }

    private async Task LoadMarketCatalog()
    {
        try
        {
            var cfg = ConfigService.Load();
            var baseUrl = (cfg.ApiBaseUrl ?? "").TrimEnd('/');
            if (baseUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase))
                baseUrl = baseUrl.Substring(0, baseUrl.Length - 4);
            if (string.IsNullOrEmpty(baseUrl) || string.IsNullOrEmpty(cfg.ApiKey))
            {
                MarketLoadingText.Text = "✗ Configuration ApiBaseUrl/ApiKey manquante.";
                return;
            }

            using var http = new System.Net.Http.HttpClient { Timeout = TimeSpan.FromSeconds(10) };
            http.DefaultRequestHeaders.Add("X-API-Key", cfg.ApiKey);
            var listUrl = $"{baseUrl}/api/market-list.php?machine={Uri.EscapeDataString(Environment.MachineName)}";
            var resp = await http.GetAsync(listUrl);
            if (!resp.IsSuccessStatusCode)
            {
                MarketLoadingText.Text = $"✗ Erreur HTTP {(int)resp.StatusCode} lors du chargement du catalogue.";
                return;
            }
            var body = await resp.Content.ReadAsStringAsync();
            using var doc = System.Text.Json.JsonDocument.Parse(body);
            var root = doc.RootElement;
            if (!root.GetProperty("ok").GetBoolean())
            {
                MarketLoadingText.Text = "✗ Le serveur a renvoyé une erreur.";
                return;
            }

            MarketCards.Children.Clear();
            int count = 0;
            if (root.TryGetProperty("extensions", out var arr))
            {
                foreach (var ext in arr.EnumerateArray())
                {
                    MarketCards.Children.Add(BuildMarketCard(ext));
                    count++;
                }
            }
            MarketLoading.Visibility = Visibility.Collapsed;
            MarketEmpty.Visibility = (count == 0) ? Visibility.Visible : Visibility.Collapsed;
            _marketLoaded = true;
        }
        catch (Exception ex)
        {
            MarketLoadingText.Text = "✗ " + ex.Message;
        }
    }

    private System.Windows.Controls.Border BuildMarketCard(System.Text.Json.JsonElement ext)
    {
        string GetStr(string key) => ext.TryGetProperty(key, out var p) && p.ValueKind != System.Text.Json.JsonValueKind.Null ? p.GetString() ?? "" : "";

        var name        = GetStr("name");
        var installState = GetStr("install_state");      // not_installed | update_available | up_to_date | newer_local
        var installedVer = GetStr("installed_version");
        var icon        = GetStr("icon_emoji");
        var shortText   = GetStr("short_text");
        var longText    = GetStr("long_text");
        var policyText  = GetStr("policy_text");
        var version     = GetStr("version");
        var sizeKb      = ext.TryGetProperty("size_kb", out var sz) ? sz.GetInt32() : 0;
        var ffMin       = GetStr("ff_min_version");
        var badgeLabel  = GetStr("badge_label");
        var deployEp    = GetStr("deploy_endpoint");
        var xpiEp       = GetStr("xpi_endpoint");
        var color1Hex   = GetStr("accent_color");   if (string.IsNullOrEmpty(color1Hex)) color1Hex = "#4E8BFF";
        var color2Hex   = GetStr("accent_color2");  if (string.IsNullOrEmpty(color2Hex)) color2Hex = color1Hex;
        var color1      = (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(color1Hex);
        var color2      = (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(color2Hex);

        // Card Border
        var card = new System.Windows.Controls.Border
        {
            Background = (System.Windows.Media.Brush)FindResource("Bg2"),
            BorderBrush = (System.Windows.Media.Brush)FindResource("Border"),
            BorderThickness = new Thickness(1),
            CornerRadius = new CornerRadius(12),
            Padding = new Thickness(18),
            Margin = new Thickness(0, 8, 0, 12),
        };
        var grid = new System.Windows.Controls.Grid();
        grid.ColumnDefinitions.Add(new System.Windows.Controls.ColumnDefinition { Width = System.Windows.GridLength.Auto });
        grid.ColumnDefinitions.Add(new System.Windows.Controls.ColumnDefinition { Width = new System.Windows.GridLength(1, System.Windows.GridUnitType.Star) });
        grid.ColumnDefinitions.Add(new System.Windows.Controls.ColumnDefinition { Width = System.Windows.GridLength.Auto });

        // Logo
        var logoBorder = new System.Windows.Controls.Border
        {
            Width = 64, Height = 64, CornerRadius = new CornerRadius(14),
            VerticalAlignment = VerticalAlignment.Top, Margin = new Thickness(0, 0, 16, 0),
            Background = new System.Windows.Media.LinearGradientBrush(color1, color2, 45),
        };
        logoBorder.Child = new System.Windows.Controls.TextBlock
        {
            Text = string.IsNullOrEmpty(icon) ? "🧩" : icon,
            FontSize = 32,
            HorizontalAlignment = HorizontalAlignment.Center,
            VerticalAlignment = VerticalAlignment.Center,
        };
        System.Windows.Controls.Grid.SetColumn(logoBorder, 0);
        grid.Children.Add(logoBorder);

        // Détails (StackPanel)
        var details = new System.Windows.Controls.StackPanel { VerticalAlignment = VerticalAlignment.Center };
        var titleRow = new System.Windows.Controls.StackPanel { Orientation = System.Windows.Controls.Orientation.Horizontal };
        titleRow.Children.Add(new System.Windows.Controls.TextBlock {
            Text = name, Foreground = System.Windows.Media.Brushes.White,
            FontWeight = FontWeights.SemiBold, FontSize = 15
        });
        titleRow.Children.Add(MakeBadge("DSI · validé", "#1E3A8A", "#A8C7FF"));
        if (!string.IsNullOrEmpty(badgeLabel))
            titleRow.Children.Add(MakeBadge(badgeLabel, "#0F2D1E", "#34D399"));
        // Badge d'état d'installation (selon API)
        switch (installState)
        {
            case "update_available":
                titleRow.Children.Add(MakeBadge($"↑ MAJ disponible", "#3D2912", "#FBBF24"));
                break;
            case "up_to_date":
                titleRow.Children.Add(MakeBadge("✓ À jour", "#0F2D1E", "#34D399"));
                break;
            case "newer_local":
                titleRow.Children.Add(MakeBadge("Local plus récent", "#1E3A3D", "#7DD9E8"));
                break;
        }
        details.Children.Add(titleRow);

        details.Children.Add(new System.Windows.Controls.TextBlock {
            Text = shortText,
            Foreground = (System.Windows.Media.Brush)FindResource("TextDim"),
            FontSize = 12, Margin = new Thickness(0, 4, 0, 0),
            TextWrapping = TextWrapping.Wrap
        });
        if (!string.IsNullOrEmpty(longText))
        {
            details.Children.Add(new System.Windows.Controls.TextBlock {
                Text = longText,
                Foreground = (System.Windows.Media.Brush)FindResource("TextMuted"),
                FontSize = 11, Margin = new Thickness(0, 4, 0, 0),
                TextWrapping = TextWrapping.Wrap
            });
        }
        if (!string.IsNullOrEmpty(policyText))
        {
            details.Children.Add(new System.Windows.Controls.TextBlock {
                Text = policyText,
                Foreground = (System.Windows.Media.Brush)FindResource("TextMuted"),
                FontSize = 11, Margin = new Thickness(0, 4, 0, 0),
                TextWrapping = TextWrapping.Wrap
            });
        }
        var subText = new System.Windows.Controls.TextBlock {
            Text = $"Version {version} · {sizeKb} Ko · Compatible Firefox {ffMin}+",
            Foreground = (System.Windows.Media.Brush)FindResource("TextMuted"),
            FontSize = 11, Margin = new Thickness(0, 8, 0, 0),
        };
        details.Children.Add(subText);
        var progress = new System.Windows.Controls.ProgressBar {
            Visibility = Visibility.Collapsed, Height = 4, Margin = new Thickness(0, 8, 0, 0),
            Minimum = 0, Maximum = 100, Value = 0,
            Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x2A, 0x33, 0x50)),
            Foreground = new System.Windows.Media.SolidColorBrush(color1),
            BorderThickness = new Thickness(0),
        };
        details.Children.Add(progress);
        System.Windows.Controls.Grid.SetColumn(details, 1);
        grid.Children.Add(details);

        // Actions (StackPanel vertical)
        var actions = new System.Windows.Controls.StackPanel {
            VerticalAlignment = VerticalAlignment.Center, Margin = new Thickness(14, 0, 0, 0)
        };
        // ===== Adaptation du bouton selon l'état d'installation =====
        string btnLabel;
        System.Windows.Media.Color btnColor;
        switch (installState)
        {
            case "update_available":
                btnLabel = "Mettre à jour";
                btnColor = System.Windows.Media.Color.FromRgb(0xF5, 0x9E, 0x0B); // orange MAJ
                break;
            case "up_to_date":
                btnLabel = "Réinstaller";
                btnColor = System.Windows.Media.Color.FromRgb(0x47, 0x55, 0x69); // gris secondaire
                break;
            case "newer_local":
                btnLabel = "Réinstaller (force)";
                btnColor = System.Windows.Media.Color.FromRgb(0x47, 0x55, 0x69);
                break;
            default:                                                              // not_installed
                btnLabel = "Installer";
                btnColor = color1;
                break;
        }

        var installBtn = new System.Windows.Controls.Button {
            Content = btnLabel, Padding = new Thickness(16, 8, 16, 8),
            Background = new System.Windows.Media.SolidColorBrush(btnColor),
            Foreground = System.Windows.Media.Brushes.White,
            BorderThickness = new Thickness(0), Cursor = System.Windows.Input.Cursors.Hand,
            MinWidth = 140,
        };
        // Affiche aussi la version installée → version cible si MAJ
        if (!string.IsNullOrEmpty(installedVer))
        {
            var v = ext.TryGetProperty("version", out var vp) && vp.ValueKind == System.Text.Json.JsonValueKind.String ? vp.GetString() : "";
            subText.Text = installState == "update_available"
                ? $"Installée v{installedVer} · disponible v{v}"
                : $"Installée v{installedVer}";
        }
        var defaultSubText = subText.Text;
        installBtn.Click += async (_, __) => {
            await InstallMarketExtension(installBtn, progress, subText, deployEp, defaultSubText, btnColor);
            // Après installation réussie, on remet à jour le bouton
            installBtn.Content = "✓ À jour";
            installBtn.Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81));
        };
        actions.Children.Add(installBtn);
        var downloadBtn = new System.Windows.Controls.Button {
            Content = "Télécharger .xpi", Padding = new Thickness(16, 7, 16, 7), Margin = new Thickness(0, 8, 0, 0),
            Background = System.Windows.Media.Brushes.Transparent,
            Foreground = new System.Windows.Media.SolidColorBrush(color2),
            BorderBrush = new System.Windows.Media.SolidColorBrush(color2),
            BorderThickness = new Thickness(1), Cursor = System.Windows.Input.Cursors.Hand,
            MinWidth = 140,
        };
        downloadBtn.Click += (_, __) => DownloadMarketXpi(xpiEp);
        actions.Children.Add(downloadBtn);

        // Bouton Désinstaller — toujours visible. Le PS de désinstallation est idempotent :
        // s'il n'y a rien à désinstaller, il marque simplement l'extension 'blocked'
        // dans policies.json (no-op fonctionnel pour Firefox).
        var code = GetStr("code");
        var uninstallBtn = new System.Windows.Controls.Button {
            Content = "Désinstaller", Padding = new Thickness(16, 7, 16, 7), Margin = new Thickness(0, 8, 0, 0),
            Background = System.Windows.Media.Brushes.Transparent,
            Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44)),
            BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x9B, 0x1C, 0x1C)),
            BorderThickness = new Thickness(1), Cursor = System.Windows.Input.Cursors.Hand,
            MinWidth = 140,
        };
        uninstallBtn.Click += async (_, __) => await UninstallMarketExtension(uninstallBtn, installBtn, progress, subText, code, name);
        actions.Children.Add(uninstallBtn);
        System.Windows.Controls.Grid.SetColumn(actions, 2);
        grid.Children.Add(actions);

        card.Child = grid;
        return card;
    }

    private static System.Windows.Controls.Border MakeBadge(string text, string bgHex, string fgHex)
    {
        var bg = (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(bgHex);
        var fg = (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(fgHex);
        return new System.Windows.Controls.Border
        {
            Background = new System.Windows.Media.SolidColorBrush(bg),
            CornerRadius = new CornerRadius(4),
            Padding = new Thickness(6, 2, 6, 2),
            Margin = new Thickness(8, 0, 0, 0),
            Child = new System.Windows.Controls.TextBlock {
                Text = text, FontSize = 10, FontWeight = FontWeights.SemiBold,
                Foreground = new System.Windows.Media.SolidColorBrush(fg),
            }
        };
    }

    private static async Task AnimateProgressTo(
        System.Windows.Controls.ProgressBar bar, double target, int durationMs)
    {
        const int frameMs = 30;
        var start = bar.Value;
        var diff = target - start;
        var steps = Math.Max(1, durationMs / frameMs);
        for (int i = 1; i <= steps; i++)
        {
            var t = (double)i / steps;
            var eased = 1 - Math.Pow(1 - t, 2);
            bar.Value = start + diff * eased;
            await Task.Delay(frameMs);
        }
        bar.Value = target;
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    // ================================================================
    // Onglet "Mes tickets"
    // ================================================================

    private async void MyTicketsTab_GotFocus(object sender, RoutedEventArgs e)
    {
        if (!_myTicketsLoaded) await LoadMyTicketsAsync();
    }

    private async void RefreshMyTickets_Click(object sender, RoutedEventArgs e)
    {
        await LoadMyTicketsAsync();
    }

    private List<TicketRow> _allTickets = new();
    private string _statusFilter = "active"; // défaut : Ouvert + En cours

    private async System.Threading.Tasks.Task LoadMyTicketsAsync()
    {
        if (!TicketService.IsConfigured)
        {
            MyTicketsCount.Text = "Connexion MySQL non configurée.";
            return;
        }

        MyTicketsCount.Text = "Chargement...";
        try
        {
            var tickets = await TicketService.GetTicketsByUserAsync(Environment.UserName, 100);
            var apiBase = (ConfigService.Load()?.ApiBaseUrl ?? "").TrimEnd('/');
            _allTickets = tickets.Select(t => new TicketRow
            {
                Id = t.Id,
                CreatedAt = t.CreatedAt,
                CreatedAtDisplay = t.CreatedAt.ToString("dd/MM/yyyy HH:mm"),
                Subject = t.Subject,
                Category = t.Category,
                Priority = t.Priority,
                Status = t.Status,
                Description = t.Description,
                AssignedTo = t.AssignedTo,
                ResolvedAt = t.ResolvedAt,
                CsatScore = t.CsatScore,
                TechName = t.TechName,
                TechPhone = t.TechPhone,
                TechEmail = t.TechEmail,
                TechAvatar = t.TechAvatar,
                TechRole = t.TechRole,
                TechAvatarUrl = (!string.IsNullOrWhiteSpace(t.TechAvatar) && !string.IsNullOrEmpty(apiBase))
                    ? $"{apiBase}/uploads/avatars/{t.TechAvatar}"
                    : ""
            }).ToList();

            int open = tickets.Count(t => t.Status == "Ouvert" || t.Status == "En cours");

            // Si aucun ticket actif (Ouvert/En cours) → bascule sur "Tous"
            // (utile à la première utilisation ou quand tous les tickets sont résolus)
            if (open == 0 && _statusFilter == "active" && tickets.Count > 0)
            {
                _statusFilter = "all";
                if (FilterAll != null) FilterAll.IsChecked = true;
            }

            ApplyTicketFilters();

            MyTicketsCount.Text = $"{tickets.Count} ticket(s) — {open} en cours";
            _myTicketsLoaded = true;
            ShowEmptyDetail();
        }
        catch (Exception ex)
        {
            MyTicketsCount.Text = $"Erreur : {ex.Message}";
        }
    }

    private void ApplyTicketFilters()
    {
        // Tri : ticket le plus récent en premier (created_at DESC)
        var query = _allTickets.OrderByDescending(t => t.CreatedAt).AsEnumerable();

        // Filtre par statut
        if (_statusFilter == "active")
        {
            // Ouverts + En cours
            query = query.Where(t =>
                string.Equals(t.Status, "Ouvert",  StringComparison.OrdinalIgnoreCase) ||
                string.Equals(t.Status, "En cours", StringComparison.OrdinalIgnoreCase));
        }
        else if (_statusFilter != "all")
        {
            query = query.Where(t => string.Equals(t.Status, _statusFilter, StringComparison.OrdinalIgnoreCase));
        }

        var search = SearchBox?.Text?.Trim();
        if (!string.IsNullOrEmpty(search))
        {
            query = query.Where(t =>
                (t.Subject ?? "").IndexOf(search, StringComparison.OrdinalIgnoreCase) >= 0
                || (t.Category ?? "").IndexOf(search, StringComparison.OrdinalIgnoreCase) >= 0
                || (t.AssignedTo ?? "").IndexOf(search, StringComparison.OrdinalIgnoreCase) >= 0
                || ("#" + t.Id).IndexOf(search, StringComparison.OrdinalIgnoreCase) >= 0);
        }

        MyTicketsList.ItemsSource = query.ToList();
    }

    private void SearchBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        if (SearchPlaceholder != null)
            SearchPlaceholder.Visibility = string.IsNullOrEmpty(SearchBox.Text)
                ? Visibility.Visible : Visibility.Collapsed;
        ApplyTicketFilters();
    }

    private void FilterPill_Checked(object sender, RoutedEventArgs e)
    {
        if (sender is RadioButton rb && rb.Tag is string tag)
        {
            _statusFilter = tag;
            if (_myTicketsLoaded) ApplyTicketFilters();
        }
    }

    private void ShowEmptyDetail()
    {
        if (DetailBox != null)   DetailBox.Visibility   = Visibility.Collapsed;
        if (EmptyDetail != null) EmptyDetail.Visibility = Visibility.Visible;
    }

    private int _currentTicketId;

    private async void MyTicketsList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (MyTicketsList.SelectedItem is not TicketRow row)
        {
            ShowEmptyDetail();
            _currentTicketId = 0;
            return;
        }

        _currentTicketId = row.Id;
        DetailHeader.Text = $"#{row.Id} — {row.Subject}";
        DetailMeta.Text = $"{row.Status} • {row.Priority} • {row.Category} • assigné à {row.TechDisplayName}";
        DetailBody.Text = row.Description ?? "";
        DetailBox.Visibility   = Visibility.Visible;
        EmptyDetail.Visibility = Visibility.Collapsed;

        UpdateTechCard(row);

        // Affiche le panneau de notation si Résolu/Clos et pas encore noté
        UpdateCsatPanel(row);
        UpdateReplyZoneState(row);

        await LoadCommentsAsync(row.Id);
    }

    /// <summary>
    /// Désactive la zone de réponse (envoi de message, pièces jointes, capture)
    /// dès que le ticket est Résolu / Clos / Clôturé.
    /// </summary>
    private void UpdateReplyZoneState(TicketRow row)
    {
        bool closed = row.Status == "Résolu" || row.Status == "Resolu"
                   || row.Status == "Clos"   || row.Status == "Clôturé"
                   || row.Status == "Fermé";

        if (ReplyBox != null)
        {
            ReplyBox.IsEnabled = !closed;
            ReplyBox.Opacity = closed ? 0.5 : 1.0;
            ReplyBox.ToolTip = closed
                ? "Ce ticket est clos — pour relancer une demande, créez-en un nouveau."
                : null;
            if (closed) ReplyBox.Text = "";
        }
        if (SendReplyButton != null)
        {
            SendReplyButton.IsEnabled = !closed;
            SendReplyButton.Opacity = closed ? 0.5 : 1.0;
        }
        if (AttachButton != null)     { AttachButton.IsEnabled     = !closed; AttachButton.Opacity     = closed ? 0.5 : 1.0; }
        if (ScreenshotButton != null) { ScreenshotButton.IsEnabled = !closed; ScreenshotButton.Opacity = closed ? 0.5 : 1.0; }
    }

    private void UpdateTechCard(TicketRow row)
    {
        if (TechCard == null) return;
        if (!row.HasTech)
        {
            TechCard.Visibility = Visibility.Collapsed;
            return;
        }
        TechCard.Visibility = Visibility.Visible;
        TechInitial.Text = row.TechInitial;
        TechNameText.Text = row.TechDisplayName;
        TechUsernameText.Text = "@" + (row.AssignedTo ?? "");

        // Rôle badge
        if (!string.IsNullOrWhiteSpace(row.TechRole))
        {
            TechRoleBadge.Text = " " + row.TechRole.ToUpperInvariant() + " ";
            TechRoleBadge.Visibility = Visibility.Visible;
        }
        else TechRoleBadge.Visibility = Visibility.Collapsed;

        // Téléphone
        if (!string.IsNullOrWhiteSpace(row.TechPhone))
        {
            TechPhoneText.Text = row.TechPhone;
            TechPhoneBtn.Tag = row.TechPhone;
            TechPhoneBtn.Visibility = Visibility.Visible;
        }
        else TechPhoneBtn.Visibility = Visibility.Collapsed;

        // Avatar : tente de charger l'image distante, fallback sur initiale en cas d'échec
        TechAvatarImg.Visibility = Visibility.Collapsed;
        if (row.HasTechAvatar)
        {
            try
            {
                var bmp = new System.Windows.Media.Imaging.BitmapImage();
                bmp.BeginInit();
                bmp.CacheOption = System.Windows.Media.Imaging.BitmapCacheOption.OnLoad;
                bmp.UriSource = new Uri(row.TechAvatarUrl, UriKind.Absolute);
                bmp.EndInit();
                bmp.DownloadCompleted += (s, e) =>
                {
                    TechAvatarBrush.ImageSource = bmp;
                    TechAvatarImg.Visibility = Visibility.Visible;
                };
                bmp.DownloadFailed += (s, e) =>
                {
                    TechAvatarImg.Visibility = Visibility.Collapsed;
                };
            }
            catch { TechAvatarImg.Visibility = Visibility.Collapsed; }
        }
    }

    private void TechPhone_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not System.Windows.Controls.Button btn) return;
        var phone = btn.Tag as string;
        if (string.IsNullOrWhiteSpace(phone)) return;

        try
        {
            // 1) Tentative tel: (compose direct si Teams/Skype/dialer Windows configuré)
            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
            {
                FileName = "tel:" + phone.Replace(" ", ""),
                UseShellExecute = true
            });
        }
        catch
        {
            // 2) Fallback : copie dans le presse-papier
            try { System.Windows.Clipboard.SetText(phone); }
            catch { /* ignore */ }
        }
        // Toujours copier en backup
        try { System.Windows.Clipboard.SetText(phone); } catch { }
    }

    private bool _csatLocked;

    private void SetCsatStarsLocked(bool locked)
    {
        for (int i = 1; i <= 5; i++)
        {
            if (FindName($"Star{i}") is TextBlock tb)
            {
                tb.Cursor = locked ? System.Windows.Input.Cursors.Arrow : System.Windows.Input.Cursors.Hand;
                tb.Opacity = locked ? 0.6 : 1.0;
                tb.ToolTip = locked ? "Note déjà enregistrée — modification impossible" : null;
            }
        }
    }

    private void UpdateCsatPanel(TicketRow row)
    {
        if (CsatPanel == null) return;
        bool isResolved = row.Status == "Résolu" || row.Status == "Resolu" || row.Status == "Clos" || row.Status == "Clôturé";
        if (isResolved && (row.CsatScore == null || row.CsatScore == 0))
        {
            // Première notation possible
            _csatLocked = false;
            CsatPanel.Visibility = Visibility.Visible;
            CsatThanks.Visibility = Visibility.Collapsed;
            for (int i = 1; i <= 5; i++)
            {
                if (FindName($"Star{i}") is TextBlock tb) tb.Text = "☆";
            }
            CsatComment.Text = "";
            CsatComment.IsEnabled = true; CsatComment.Opacity = 1.0;
            CsatSubmitButton.IsEnabled = true; CsatSubmitButton.Opacity = 1.0;
            CsatSubmitButton.ToolTip = null;
            SetCsatStarsLocked(false);
            _pickedScore = 0;
        }
        else if (isResolved && row.CsatScore.HasValue && row.CsatScore.Value > 0)
        {
            // Ticket déjà noté : tout en lecture seule + boutons grisés
            _csatLocked = true;
            CsatPanel.Visibility = Visibility.Visible;
            CsatThanks.Visibility = Visibility.Visible;
            CsatThanks.Text = $"✓ Vous avez noté ce ticket {row.CsatScore.Value}/5 — merci !";
            for (int i = 1; i <= 5; i++)
            {
                if (FindName($"Star{i}") is TextBlock tb) tb.Text = i <= row.CsatScore.Value ? "★" : "☆";
            }
            // Désactivation visuelle
            CsatComment.IsEnabled = false; CsatComment.Opacity = 0.5;
            CsatSubmitButton.IsEnabled = false; CsatSubmitButton.Opacity = 0.5;
            CsatSubmitButton.ToolTip = "Note déjà envoyée";
            SetCsatStarsLocked(true);
        }
        else
        {
            _csatLocked = false;
            CsatPanel.Visibility = Visibility.Collapsed;
        }
    }

    private int _pickedScore;

    private void Star_Click(object sender, System.Windows.Input.MouseButtonEventArgs e)
    {
        if (_csatLocked) return; // Note déjà envoyée → clic ignoré
        if (sender is TextBlock tb && tb.Tag is string tagStr && int.TryParse(tagStr, out int n))
        {
            _pickedScore = n;
            for (int i = 1; i <= 5; i++)
                if (FindName($"Star{i}") is TextBlock t) t.Text = i <= n ? "★" : "☆";
        }
    }

    private async void CsatSubmit_Click(object sender, RoutedEventArgs e)
    {
        if (_pickedScore < 1 || _pickedScore > 5) { MessageBox.Show("Sélectionnez de 1 à 5 étoiles."); return; }
        if (_currentTicketId <= 0) return;
        try
        {
            CsatSubmitButton.IsEnabled = false;
            var ok = await TicketService.RateTicketAsync(_currentTicketId, _pickedScore, CsatComment.Text, Environment.UserName);
            if (ok)
            {
                CsatThanks.Visibility = Visibility.Visible;
                CsatThanks.Text = $"✓ Merci pour votre retour ({_pickedScore}/5) !";
                // Met à jour le cache local
                var t = _allTickets.FirstOrDefault(x => x.Id == _currentTicketId);
                if (t != null) t.CsatScore = _pickedScore;
            }
            else
            {
                MessageBox.Show("Notation impossible (ticket non résolu ou déjà noté).");
            }
        }
        catch (Exception ex) { MessageBox.Show("Erreur : " + ex.Message); }
        finally { CsatSubmitButton.IsEnabled = true; }
    }

    private async System.Threading.Tasks.Task LoadCommentsAsync(int ticketId)
    {
        CommentsPanel.Children.Clear();
        try
        {
            // Charge en parallèle les commentaires et la liste des pièces jointes
            var commentsTask = TicketService.GetCommentsAsync(ticketId);
            var attachmentsTask = AttachmentApi.IsConfigured
                ? AttachmentApi.ListAsync(ticketId, Environment.UserName)
                : System.Threading.Tasks.Task.FromResult(new System.Collections.Generic.List<AttachmentInfo>());

            await System.Threading.Tasks.Task.WhenAll(commentsTask, attachmentsTask);

            var comments = commentsTask.Result;
            var attachments = attachmentsTask.Result;

            // Index des PJ par nom (les commentaires auto contiennent le nom du fichier).
            // En cas de fichiers homonymes uploadés plusieurs fois, on garde le plus récent.
            var attByName = attachments
                .GroupBy(a => a.OriginalName, StringComparer.OrdinalIgnoreCase)
                .ToDictionary(g => g.Key, g => g.OrderByDescending(a => a.UploadedAt).First(), StringComparer.OrdinalIgnoreCase);

            if (comments.Count == 0)
            {
                CommentsPanel.Children.Add(new TextBlock
                {
                    Text = "Aucune réponse pour l'instant. Vous pouvez écrire un message ci-dessous.",
                    Foreground = System.Windows.Media.Brushes.Gray,
                    FontStyle = FontStyles.Italic,
                    Margin = new Thickness(0, 4, 0, 4)
                });
            }
            else
            {
                foreach (var c in comments)
                {
                    bool fromMe = string.Equals(c.Author, Environment.UserName, StringComparison.OrdinalIgnoreCase);

                    // Si c'est un commentaire auto signalant une PJ, on tente de la trouver
                    AttachmentInfo? linkedAttachment = null;
                    if ((c.Body.StartsWith("📎 Pièce jointe") || c.Body.StartsWith("🖼 Pièce jointe"))
                        && c.Body.Contains(" : "))
                    {
                        var fileName = c.Body.Substring(c.Body.IndexOf(" : ") + 3).Trim();
                        attByName.TryGetValue(fileName, out linkedAttachment);
                    }

                    CommentsPanel.Children.Add(BuildCommentBubble(c.Author, c.CreatedAt, c.Body, fromMe, linkedAttachment));
                }
            }
            CommentsScroll.ScrollToEnd();
        }
        catch (Exception ex)
        {
            CommentsPanel.Children.Add(new TextBlock
            {
                Text = $"Erreur : {ex.Message}",
                Foreground = System.Windows.Media.Brushes.Salmon,
                Margin = new Thickness(0, 4, 0, 4)
            });
        }
    }

    private static UIElement BuildCommentBubble(string author, DateTime when, string body, bool fromMe, AttachmentInfo? attachment = null)
    {
        // Blue gradient for "moi" (l'agent demandeur), green/teal for "support"
        var bubble = new Border
        {
            CornerRadius = new CornerRadius(14, 14, fromMe ? 4 : 14, fromMe ? 14 : 4),
            Padding = new Thickness(14, 10, 14, 10),
            Margin = new Thickness(fromMe ? 60 : 0, 4, fromMe ? 0 : 60, 4),
            HorizontalAlignment = fromMe ? HorizontalAlignment.Right : HorizontalAlignment.Left,
            MaxWidth = 520,
            BorderThickness = new Thickness(fromMe ? 0 : 1, 0, 0, 0),
            BorderBrush = fromMe
                ? System.Windows.Media.Brushes.Transparent
                : new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81))
        };

        if (fromMe)
        {
            var grad = new System.Windows.Media.LinearGradientBrush
            {
                StartPoint = new System.Windows.Point(0, 0),
                EndPoint   = new System.Windows.Point(1, 1)
            };
            grad.GradientStops.Add(new System.Windows.Media.GradientStop(System.Windows.Media.Color.FromRgb(0x3B, 0x82, 0xF6), 0));
            grad.GradientStops.Add(new System.Windows.Media.GradientStop(System.Windows.Media.Color.FromRgb(0x63, 0x66, 0xF1), 1));
            bubble.Background = grad;
        }
        else
        {
            var grad = new System.Windows.Media.LinearGradientBrush
            {
                StartPoint = new System.Windows.Point(0, 0),
                EndPoint   = new System.Windows.Point(1, 1)
            };
            grad.GradientStops.Add(new System.Windows.Media.GradientStop(System.Windows.Media.Color.FromRgb(0x13, 0x4E, 0x4A), 0));
            grad.GradientStops.Add(new System.Windows.Media.GradientStop(System.Windows.Media.Color.FromRgb(0x11, 0x5E, 0x59), 1));
            bubble.Background = grad;
        }

        var stack = new StackPanel();
        var meta = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0, 0, 0, 5) };
        meta.Children.Add(new TextBlock
        {
            Text = author,
            Foreground = System.Windows.Media.Brushes.White,
            Opacity = 0.85,
            FontSize = 11,
            FontWeight = FontWeights.SemiBold,
        });
        meta.Children.Add(new TextBlock
        {
            Text = $"  ·  {when:dd/MM/yyyy HH:mm}",
            Foreground = System.Windows.Media.Brushes.White,
            Opacity = 0.55,
            FontSize = 10.5,
        });
        stack.Children.Add(meta);
        stack.Children.Add(new TextBlock
        {
            Text = body,
            Foreground = System.Windows.Media.Brushes.White,
            FontSize = 13,
            LineHeight = 19,
            LineStackingStrategy = LineStackingStrategy.BlockLineHeight,
            TextWrapping = TextWrapping.Wrap
        });

        if (attachment != null)
        {
            // Aperçu image si MIME = image/*
            if (attachment.MimeType.StartsWith("image/", StringComparison.OrdinalIgnoreCase))
            {
                var img = new System.Windows.Controls.Image
                {
                    MaxWidth = 320,
                    MaxHeight = 220,
                    Margin = new Thickness(0, 6, 0, 0),
                    Cursor = System.Windows.Input.Cursors.Hand,
                    Stretch = System.Windows.Media.Stretch.Uniform
                };
                _ = LoadImageAsync(img, attachment);
                img.MouseLeftButtonUp += (_, _) => OpenAttachmentExternal(attachment);
                stack.Children.Add(img);
            }
            else
            {
                var link = new TextBlock
                {
                    Margin = new Thickness(0, 4, 0, 0),
                    Cursor = System.Windows.Input.Cursors.Hand,
                    Foreground = System.Windows.Media.Brushes.LightSkyBlue,
                    TextDecorations = System.Windows.TextDecorations.Underline
                };
                link.Inlines.Add(new System.Windows.Documents.Run($"⬇ {attachment.OriginalName} ({FormatSize(attachment.SizeBytes)})"));
                link.MouseLeftButtonUp += (_, _) => OpenAttachmentExternal(attachment);
                stack.Children.Add(link);
            }
        }

        bubble.Child = stack;
        return bubble;
    }

    private static async System.Threading.Tasks.Task LoadImageAsync(System.Windows.Controls.Image target, AttachmentInfo att)
    {
        try
        {
            var bytes = await AttachmentApi.DownloadAsync(att.Id, Environment.UserName);
            using var ms = new MemoryStream(bytes);
            var bmp = new System.Windows.Media.Imaging.BitmapImage();
            bmp.BeginInit();
            bmp.CacheOption = System.Windows.Media.Imaging.BitmapCacheOption.OnLoad;
            bmp.StreamSource = ms;
            bmp.EndInit();
            bmp.Freeze();
            target.Source = bmp;
        }
        catch
        {
            target.Source = null;
        }
    }

    private static void OpenAttachmentExternal(AttachmentInfo att)
    {
        try
        {
            var url = AttachmentApi.DownloadUrl(att.Id, Environment.UserName);
            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
            {
                FileName = url,
                UseShellExecute = true
            });
        }
        catch { }
    }

    private static string FormatSize(long bytes)
    {
        if (bytes < 1024) return $"{bytes} o";
        if (bytes < 1024 * 1024) return $"{bytes / 1024.0:0.#} Ko";
        return $"{bytes / (1024.0 * 1024):0.#} Mo";
    }

    private async void Attach_Click(object sender, RoutedEventArgs e)
    {
        if (_currentTicketId <= 0) return;

        var dlg = new Microsoft.Win32.OpenFileDialog
        {
            Title = "Joindre un fichier au ticket",
            Filter = "Tous fichiers (*.*)|*.*"
        };
        if (dlg.ShowDialog(this) != true) return;

        await UploadAndRefresh(System.IO.File.ReadAllBytes(dlg.FileName), System.IO.Path.GetFileName(dlg.FileName), GuessMime(dlg.FileName));
    }

    private async void Screenshot_Click(object sender, RoutedEventArgs e)
    {
        if (_currentTicketId <= 0) return;

        // Cache la fenêtre temporairement pour ne pas l'inclure dans la capture
        var wasShown = this.WindowState;
        this.WindowState = WindowState.Minimized;
        await System.Threading.Tasks.Task.Delay(300);

        try
        {
            var png = ScreenCapture.CaptureAllScreensPng();
            this.WindowState = wasShown;
            this.Activate();

            // Étape annotation : l'utilisateur peut surligner/dessiner avant l'envoi.
            var annotate = new AnnotateWindow(png) { Owner = this };
            var ok = annotate.ShowDialog();
            if (ok != true) return;
            var finalPng = annotate.Result ?? png;

            await UploadAndRefresh(finalPng, ScreenCapture.SuggestedFilename(), "image/png");
        }
        catch (Exception ex)
        {
            this.WindowState = wasShown;
            MessageBox.Show(this, $"Capture échouée : {ex.Message}", "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async System.Threading.Tasks.Task UploadAndRefresh(byte[] data, string fileName, string mime)
    {
        if (!AttachmentApi.IsConfigured)
        {
            MessageBox.Show(this,
                "L'envoi de pièces jointes nécessite ApiBaseUrl et ApiKey dans apps.json.",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        AttachButton.IsEnabled = false;
        ScreenshotButton.IsEnabled = false;
        try
        {
            await AttachmentApi.UploadAsync(_currentTicketId, Environment.UserName, data, fileName, mime);
            await LoadCommentsAsync(_currentTicketId);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, $"Échec d'envoi : {ex.Message}", "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            AttachButton.IsEnabled = true;
            ScreenshotButton.IsEnabled = true;
        }
    }

    private static string GuessMime(string path)
    {
        var ext = System.IO.Path.GetExtension(path).ToLowerInvariant();
        return ext switch
        {
            ".png" => "image/png",
            ".jpg" or ".jpeg" => "image/jpeg",
            ".gif" => "image/gif",
            ".bmp" => "image/bmp",
            ".pdf" => "application/pdf",
            ".txt" or ".log" => "text/plain",
            ".zip" => "application/zip",
            ".doc" => "application/msword",
            ".docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            ".xls" => "application/vnd.ms-excel",
            ".xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            _ => "application/octet-stream"
        };
    }

    private async void SendReply_Click(object sender, RoutedEventArgs e)
    {
        await SendReplyAsync();
    }

    private void ReplyBox_KeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        // Ctrl+Entrée ou Maj+Entrée pour envoyer
        if (e.Key == System.Windows.Input.Key.Enter
            && (System.Windows.Input.Keyboard.Modifiers == System.Windows.Input.ModifierKeys.Control
                || System.Windows.Input.Keyboard.Modifiers == System.Windows.Input.ModifierKeys.Shift))
        {
            e.Handled = true;
            _ = SendReplyAsync();
        }
    }

    private async System.Threading.Tasks.Task SendReplyAsync()
    {
        if (_currentTicketId <= 0 || string.IsNullOrWhiteSpace(ReplyBox.Text)) return;

        var body = ReplyBox.Text.Trim();
        SendReplyButton.IsEnabled = false;
        ReplyBox.IsEnabled = false;
        try
        {
            await TicketService.AddCommentAsync(_currentTicketId, Environment.UserName, body);
            ReplyBox.Text = "";
            await LoadCommentsAsync(_currentTicketId);
        }
        catch (Exception ex)
        {
            MessageBox.Show(this, $"Échec d'envoi : {ex.Message}", "DockPolice", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally
        {
            SendReplyButton.IsEnabled = true;
            ReplyBox.IsEnabled = true;
            ReplyBox.Focus();
        }
    }

    public void SwitchToMyTickets(int ticketId = 0)
    {
        // Active l'onglet "Mes tickets"
        if (this.Content is System.Windows.Controls.DockPanel dp)
        {
            foreach (var child in dp.Children)
            {
                if (child is System.Windows.Controls.TabControl tc)
                {
                    tc.SelectedIndex = 1;
                    break;
                }
            }
        }
        _ = SwitchToMyTicketsAsync(ticketId);
    }

    private async System.Threading.Tasks.Task SwitchToMyTicketsAsync(int ticketId)
    {
        if (!_myTicketsLoaded) await LoadMyTicketsAsync();
        if (ticketId > 0)
        {
            foreach (var item in MyTicketsList.Items)
            {
                if (item is TicketRow r && r.Id == ticketId)
                {
                    MyTicketsList.SelectedItem = item;
                    MyTicketsList.ScrollIntoView(item);
                    break;
                }
            }
        }
    }

    // ============================================================
    // Onglet "Habilitations" — demande logiciel + profil + PDF Dialogue
    // ============================================================
    private bool _habilCatalogLoaded;
    private List<HabilitationSoftware> _habilCatalog = new();
    private string? _habilPdfPath;

    private async void HabilTab_GotFocus(object sender, RoutedEventArgs e)
    {
        if (!_habilCatalogLoaded)
        {
            try
            {
                HabilSoftwareBox.IsEnabled = false;
                _habilCatalog = await HabilitationService.GetCatalogAsync();
                HabilSoftwareBox.ItemsSource = _habilCatalog;
                HabilSoftwareBox.IsEnabled = true;
                _habilCatalogLoaded = true;
            }
            catch (Exception ex)
            {
                ShowHabilStatus("⚠ Catalogue indisponible : " + ex.Message, isError: true);
            }
        }
        await ReloadHabilListAsync();
    }

    private async void HabilListRefresh_Click(object sender, RoutedEventArgs e)
    {
        await ReloadHabilListAsync();
    }

    /// <summary>
    /// Recharge la liste des habilitations de l'agent et la peuple dans HabilList.
    /// </summary>
    private async Task ReloadHabilListAsync()
    {
        try
        {
            HabilListRefreshBtn.IsEnabled = false;
            var matricule = Environment.UserName;
            var items = await HabilitationService.ListMineAsync(matricule);

            var rows = items.Select(h => new HabilListRow
            {
                Ref = h.Ref,
                Software = h.Software,
                ProfileSuffix = string.IsNullOrWhiteSpace(h.Profile) ? "" : "· " + h.Profile,
                StatusLabel = h.StatusLabel,
                StatusBg = HabilStatusBgBrush(h.Status),
                StatusFg = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Colors.White),
                ProgressPctText = h.ProgressPct + " %",
                // Star units : ratio gauche/droite proportionnel — toujours 100 au total
                // pct=100 → 100*/0* (fill complet) ; pct=60 → 60*/40* ; pct=0 → 0.001*/100*
                ProgressColumnWidth = new System.Windows.GridLength(Math.Max(0.001, h.ProgressPct), System.Windows.GridUnitType.Star),
                RestColumnWidth     = new System.Windows.GridLength(Math.Max(0.001, 100 - h.ProgressPct), System.Windows.GridUnitType.Star),
                DateInfo = BuildHabilDateInfo(h),
            }).ToList();

            HabilList.ItemsSource = rows;
            HabilListEmpty.Visibility = rows.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
            HabilListSubtitle.Text = rows.Count == 0
                ? "Aucune demande enregistrée."
                : $"{rows.Count} habilitation(s) · {rows.Count(r => r.StatusLabel.StartsWith("Active"))} active(s)";
        }
        catch (Exception ex)
        {
            HabilListEmpty.Visibility = Visibility.Visible;
            HabilListEmpty.Text = "⚠ " + ex.Message;
        }
        finally
        {
            HabilListRefreshBtn.IsEnabled = true;
        }
    }

    private static System.Windows.Media.SolidColorBrush HabilStatusBgBrush(string status)
    {
        var c = status switch
        {
            "active"    => System.Windows.Media.Color.FromRgb(0x10, 0xB9, 0x81),  // vert
            "demande"   => System.Windows.Media.Color.FromRgb(0xF5, 0x9E, 0x0B),  // orange
            "en_cours"  => System.Windows.Media.Color.FromRgb(0x3B, 0x82, 0xF6),  // bleu
            "suspendue" => System.Windows.Media.Color.FromRgb(0x94, 0xA3, 0xB8),  // gris
            "expiree"   => System.Windows.Media.Color.FromRgb(0xEF, 0x44, 0x44),  // rouge
            "revoquee"  => System.Windows.Media.Color.FromRgb(0x7F, 0x1D, 0x1D),  // rouge sombre
            _           => System.Windows.Media.Color.FromRgb(0x64, 0x74, 0x8B),
        };
        return new System.Windows.Media.SolidColorBrush(c);
    }
    private static string BuildHabilDateInfo(HabilitationItem h)
    {
        var parts = new List<string>();
        if (h.CreatedAt.HasValue) parts.Add("📅 Créée " + h.CreatedAt.Value.ToString("dd/MM/yyyy"));
        if (h.GrantedAt.HasValue) parts.Add("✓ Accordée " + h.GrantedAt.Value.ToString("dd/MM/yyyy"));
        if (h.ExpiryDate.HasValue)
        {
            var diff = (h.ExpiryDate.Value - DateTime.Now).TotalDays;
            string suffix = diff < 0 ? "⚠ expirée" : (diff < 90 ? $"expire dans {(int)diff} j ⚠" : "expire " + h.ExpiryDate.Value.ToString("dd/MM/yyyy"));
            parts.Add(suffix);
        }
        if (h.HasPdf) parts.Add("📎 PDF joint");
        if (!string.IsNullOrWhiteSpace(h.CpnName)) parts.Add("· " + h.CpnName);
        return string.Join(" · ", parts);
    }

    /// <summary>
    /// Ligne d'affichage utilisée par le DataTemplate de HabilList.
    /// (Les couleurs sont stockées en string ; le binding accepte les hex via converter implicite WPF.)
    /// </summary>
    private class HabilListRow
    {
        public string Ref { get; set; } = "";
        public string Software { get; set; } = "";
        public string ProfileSuffix { get; set; } = "";
        public string StatusLabel { get; set; } = "";
        public System.Windows.Media.Brush StatusBg { get; set; }
            = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Colors.Gray);
        public System.Windows.Media.Brush StatusFg { get; set; }
            = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Colors.White);
        public string ProgressPctText { get; set; } = "";
        public System.Windows.GridLength ProgressColumnWidth { get; set; } = new System.Windows.GridLength(0, System.Windows.GridUnitType.Star);
        public System.Windows.GridLength RestColumnWidth     { get; set; } = new System.Windows.GridLength(100, System.Windows.GridUnitType.Star);
        public string DateInfo { get; set; } = "";
    }

    private void HabilSoftware_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        var s = HabilSoftwareBox.SelectedItem as HabilitationSoftware;
        if (s == null) { HabilProfileBox.IsEnabled = false; HabilProfileBox.ItemsSource = null; return; }
        HabilProfileBox.ItemsSource = s.Profiles;
        HabilProfileBox.SelectedIndex = -1;
        HabilProfileBox.IsEnabled = true;
    }

    private void HabilPdfPick_Click(object sender, RoutedEventArgs e)
    {
        var dlg = new Microsoft.Win32.OpenFileDialog
        {
            Filter = "PDF Dialogue avec avis chef de service (*.pdf)|*.pdf",
            Title = "Sélectionnez le PDF Dialogue signé par le chef de service"
        };
        if (dlg.ShowDialog() == true)
        {
            var fi = new System.IO.FileInfo(dlg.FileName);
            if (fi.Length > 10 * 1024 * 1024)
            {
                MessageBox.Show("Le PDF dépasse 10 Mo.", "Trop volumineux", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }
            if (fi.Length < 1024)
            {
                MessageBox.Show("Le PDF est trop petit / vide.", "Fichier invalide", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }
            _habilPdfPath = dlg.FileName;
            HabilPdfPathBox.Text = $"✓ {fi.Name}  ({Math.Round(fi.Length / 1024.0)} Ko)";
            HabilPdfPathBox.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(110, 231, 183));
            // Bordure verte pour indiquer que le requis est rempli
            HabilPdfWrap.Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(14, 42, 31));
            HabilPdfWrap.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(16, 185, 129));
        }
    }

    private async void HabilSubmit_Click(object sender, RoutedEventArgs e)
    {
        var sw = HabilSoftwareBox.SelectedItem as HabilitationSoftware;
        var profile = HabilProfileBox.SelectedItem as string;
        if (sw == null) { ShowHabilStatus("Sélectionnez un logiciel.", isError: true); return; }
        if (string.IsNullOrWhiteSpace(profile))
        {
            ShowHabilStatus("Sélectionnez un profil.", isError: true); return;
        }
        if (string.IsNullOrEmpty(_habilPdfPath) || !System.IO.File.Exists(_habilPdfPath))
        {
            ShowHabilStatus("⚠ PDF Dialogue obligatoire (avec l'avis du chef de service).", isError: true);
            return;
        }

        try
        {
            HabilSubmitBtn.IsEnabled = false;
            HabilSubmitBtn.Content = "⏳ Envoi en cours…";

            var matricule = Environment.UserName;
            // Tente de récupérer le display name depuis la session backoffice (fallback : matricule)
            var agentName = matricule;

            var result = await HabilitationService.CreateAsync(
                matricule, agentName, sw.Key, profile,
                HabilScopeBox.Text?.Trim() ?? "",
                HabilNotesBox.Text?.Trim() ?? "",
                _habilPdfPath
            );

            if (result.Ok)
            {
                ShowHabilStatus($"✓ Demande {result.Ref} enregistrée. {result.Message}", isError: false);
                // Reset du formulaire
                HabilSoftwareBox.SelectedIndex = -1;
                HabilProfileBox.ItemsSource = null;
                HabilProfileBox.IsEnabled = false;
                HabilScopeBox.Text = "";
                HabilNotesBox.Text = "";
                HabilPdfPathBox.Text = "Aucun PDF sélectionné — fichier requis";
                HabilPdfPathBox.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(252, 165, 165));
                HabilPdfWrap.Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(31, 15, 15));
                HabilPdfWrap.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(127, 29, 29));
                _habilPdfPath = null;
            }
            else
            {
                ShowHabilStatus("⚠ " + (result.Message ?? "Échec inconnu"), isError: true);
            }
        }
        catch (Exception ex)
        {
            ShowHabilStatus("⚠ Erreur : " + ex.Message, isError: true);
        }
        finally
        {
            HabilSubmitBtn.IsEnabled = true;
            HabilSubmitBtn.Content = "🪪 Envoyer la demande";
        }
    }

    private void ShowHabilStatus(string msg, bool isError)
    {
        HabilStatusBox.Visibility = Visibility.Visible;
        HabilStatusText.Text = msg;
        if (isError)
        {
            HabilStatusBox.Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(45, 15, 15));
            HabilStatusBox.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(239, 68, 68));
            HabilStatusText.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(252, 165, 165));
        }
        else
        {
            HabilStatusBox.Background = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(14, 42, 31));
            HabilStatusBox.BorderBrush = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(16, 185, 129));
            HabilStatusText.Foreground = new System.Windows.Media.SolidColorBrush(System.Windows.Media.Color.FromRgb(110, 231, 183));
        }
    }

    private class TicketRow
    {
        public int Id { get; set; }
        public DateTime CreatedAt { get; set; }
        public string CreatedAtDisplay { get; set; } = "";
        public string Subject { get; set; } = "";
        public string Category { get; set; } = "";
        public string Priority { get; set; } = "";
        public string Status { get; set; } = "";
        public string Description { get; set; } = "";
        public string? AssignedTo { get; set; }
        public DateTime? ResolvedAt { get; set; }
        public int? CsatScore { get; set; }

        // Tech info (jointure backoffice users)
        public string? TechName { get; set; }
        public string? TechPhone { get; set; }
        public string? TechEmail { get; set; }
        public string? TechAvatar { get; set; }
        public string? TechRole { get; set; }

        public string TechDisplayName => string.IsNullOrWhiteSpace(TechName) ? (AssignedTo ?? "") : TechName!;
        public string TechInitial => string.IsNullOrEmpty(TechDisplayName) ? "?" : TechDisplayName[0].ToString().ToUpperInvariant();
        public bool HasTech => !string.IsNullOrWhiteSpace(AssignedTo);
        public bool HasTechPhone => HasTech && !string.IsNullOrWhiteSpace(TechPhone);
        public bool HasTechAvatar => HasTech && !string.IsNullOrWhiteSpace(TechAvatar) && !string.IsNullOrEmpty(TechAvatarUrl);
        public string TechAvatarUrl { get; set; } = "";
    }
}
