using System;
using System.IO;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using DockLite.Models;
using DockLite.Services;

namespace DockLite.Views;

public partial class ProfileWindow : Window
{
    private ActiveDirectoryService.AdUserInfo _ad = new();
    private DirectoryProfileService.ProfileEntry? _existing;
    private bool _photoUploaded = false;

    public ProfileWindow()
    {
        InitializeComponent();
        Loaded += async (_, _) => await InitializeAsync();
    }

    private async Task InitializeAsync()
    {
        try
        {
            // 1) Lecture AD
            _ad = ActiveDirectoryService.GetCurrentUser();
            TxtFirstName.Text = _ad.FirstName;
            TxtLastName.Text  = _ad.LastName;
            TxtMatricule.Text = _ad.Matricule;
            TxtEmail.Text     = _ad.Email;

            TxtAdStatus.Text = _ad.FromAd
                ? "✓ Récupéré depuis Active Directory."
                : "⚠ Active Directory non joignable — informations devinées depuis votre compte Windows. Vérifiez et signalez à l'ALSSI si incorrect.";

            TxtHeader.Text = string.IsNullOrWhiteSpace(_ad.DisplayName)
                ? "Votre profil"
                : $"Bonjour {_ad.FirstName} 👋";

            // Avatar : photo AD si dispo, sinon initiales colorées
            UpdateAvatar();
            UpdatePhotoStatus();

            // 2a) Peuple le combo grades (avec en-têtes de corps non-sélectionnables)
            try
            {
                CmbGrade.Items.Clear();
                CmbGrade.Items.Add("— Sélectionnez votre grade —");
                foreach (var item in PoliceGrades.AllWithHeaders())
                {
                    CmbGrade.Items.Add(item);
                }
                CmbGrade.SelectedIndex = 0;
                // Empêche la sélection des en-têtes "── ... ──"
                CmbGrade.SelectionChanged += (_, _) =>
                {
                    var sel = CmbGrade.SelectedItem as string;
                    if (sel != null && PoliceGrades.IsHeader(sel))
                    {
                        // Décale d'un cran (sélectionne l'item suivant non-en-tête)
                        var idx = CmbGrade.SelectedIndex;
                        for (int i = idx + 1; i < CmbGrade.Items.Count; i++)
                        {
                            if (CmbGrade.Items[i] is string s && !PoliceGrades.IsHeader(s)) { CmbGrade.SelectedIndex = i; return; }
                        }
                        CmbGrade.SelectedIndex = 0;
                    }
                };
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine("Erreur init grade combo: " + ex);
            }

            // 2) Charger commissariats
            var cpns = await TicketService.GetCommissariatsAsync();
            CmbCommissariat.Items.Add(new CpnItem { Id = null, Display = "— Sélectionnez votre commissariat —" });
            foreach (var c in cpns)
            {
                var prefix = c.ParentId.HasValue ? "  └ " : "";
                CmbCommissariat.Items.Add(new CpnItem
                {
                    Id = c.Id,
                    ParentId = c.ParentId,
                    Display = prefix + c.Name + (string.IsNullOrEmpty(c.Code) ? "" : $" ({c.Code})"),
                });
            }
            CmbCommissariat.DisplayMemberPath = nameof(CpnItem.Display);
            CmbCommissariat.SelectedIndex = 0;
            CmbCommissariat.SelectionChanged += async (_, _) => await OnCommissariatChangedAsync();

            // 3) Charger profil existant si déjà saisi
            _existing = await DirectoryProfileService.LoadAsync(_ad.Matricule);
            if (_existing != null)
            {
                // Pré-remplit les champs identité depuis l'annuaire si AD est vide ou plus complet
                if (string.IsNullOrWhiteSpace(TxtFirstName.Text) && !string.IsNullOrWhiteSpace(_existing.FirstName))
                    TxtFirstName.Text = _existing.FirstName;
                if (string.IsNullOrWhiteSpace(TxtLastName.Text) && !string.IsNullOrWhiteSpace(_existing.LastName))
                    TxtLastName.Text = _existing.LastName;
                if (string.IsNullOrWhiteSpace(TxtEmail.Text) && !string.IsNullOrWhiteSpace(_existing.Email))
                    TxtEmail.Text = _existing.Email;

                TxtPhoneFixed.Text  = _existing.PhoneFixed ?? "";
                TxtPhoneMobile.Text = _existing.PhoneMobile ?? "";
                TxtPhoneNeo.Text    = _existing.PhoneNeo ?? "";

                // Pré-sélection du grade
                if (!string.IsNullOrWhiteSpace(_existing.Role))
                {
                    bool found = false;
                    foreach (var item in CmbGrade.Items)
                    {
                        if (item is string s && PoliceGrades.Clean(s) == _existing.Role)
                        {
                            CmbGrade.SelectedItem = item;
                            found = true;
                            break;
                        }
                    }
                    if (!found)
                    {
                        // Grade libre saisi manuellement précédemment → mode IsEditable
                        CmbGrade.Text = _existing.Role;
                    }
                }

                if (_existing.CommissariatId.HasValue)
                {
                    foreach (var item in CmbCommissariat.Items)
                    {
                        if (item is CpnItem ci && ci.Id == _existing.CommissariatId.Value)
                        {
                            CmbCommissariat.SelectedItem = item;
                            break;
                        }
                    }
                    // Charge la liste des services pour cette CPN puis pré-sélectionne
                    await LoadServicesForCpnAsync(_existing.CommissariatId.Value, _existing.ServiceId, _existing.Service);
                }

                if (_existing.LastSelfUpdate.HasValue)
                {
                    TxtLastUpdate.Text = "Dernière mise à jour : "
                        + _existing.LastSelfUpdate.Value.ToString("dd/MM/yyyy HH:mm")
                        + " (source : " + _existing.Source + ")";
                }

                // Photo annuaire : si présente et pas déjà rendue par AD, on la télécharge
                if (!string.IsNullOrWhiteSpace(_existing.PhotoPath))
                {
                    _ = LoadDirectoryPhotoAsync(_existing.PhotoPath);
                }
            }
            else
            {
                TxtSubHeader.Text = "Première utilisation : merci de compléter vos coordonnées professionnelles ci-dessous.";
            }
        }
        catch (Exception ex)
        {
            TxtError.Text = "Erreur initialisation : " + ex.Message;
        }

        UpdateCompleteness();
        // Recalcul à chaque modification de champ
        TxtFirstName.TextChanged   += (_, _) => UpdateCompleteness();
        TxtLastName.TextChanged    += (_, _) => UpdateCompleteness();
        TxtEmail.TextChanged       += (_, _) => UpdateCompleteness();
        TxtPhoneFixed.TextChanged  += (_, _) => UpdateCompleteness();
        TxtPhoneMobile.TextChanged += (_, _) => UpdateCompleteness();
        TxtPhoneNeo.TextChanged    += (_, _) => UpdateCompleteness();
        CmbGrade.SelectionChanged  += (_, _) => UpdateCompleteness();
        CmbCommissariat.SelectionChanged += (_, _) => UpdateCompleteness();
        CmbService.SelectionChanged      += (_, _) => UpdateCompleteness();
    }

    /// <summary>
    /// Calcule un % de complétude sur 8 critères et met à jour la barre de progression.
    /// </summary>
    private void UpdateCompleteness()
    {
        try
        {
            int filled = 0, total = 8;
            if (!string.IsNullOrWhiteSpace(TxtFirstName.Text)) filled++;
            if (!string.IsNullOrWhiteSpace(TxtLastName.Text))  filled++;
            if (!string.IsNullOrWhiteSpace(TxtEmail.Text))     filled++;
            if (!string.IsNullOrWhiteSpace(TxtPhoneFixed.Text) || !string.IsNullOrWhiteSpace(TxtPhoneNeo.Text)) filled++;
            if (!string.IsNullOrWhiteSpace(TxtPhoneMobile.Text)) filled++;
            // Grade (combo)
            var grade = CmbGrade.SelectedItem as string ?? "";
            if (!string.IsNullOrWhiteSpace(grade) && !grade.StartsWith("—") && !PoliceGrades.IsHeader(grade)) filled++;
            // Commissariat
            if (CmbCommissariat.SelectedItem is CpnItem ci && ci.Id != null) filled++;
            // Photo (badge visible)
            if (AvatarBadge?.Visibility == Visibility.Visible) filled++;

            int pct = (int)Math.Round(filled * 100.0 / total);
            TxtCompletenessLabel.Text = $"Complétude de votre fiche : {filled}/{total} champs renseignés";
            TxtCompletenessPct.Text   = pct + " %";

            // Couleurs dynamiques selon avancement
            var (fg, fill) = pct switch
            {
                >= 90 => ("#10B981", "#10B981"),  // vert
                >= 60 => ("#3B82F6", "#3B82F6"),  // bleu
                >= 30 => ("#F59E0B", "#F59E0B"),  // orange
                _     => ("#EF4444", "#EF4444"),  // rouge
            };
            var fgC = (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(fg);
            TxtCompletenessPct.Foreground = new System.Windows.Media.SolidColorBrush(fgC);
            CompFillBar.Background = new System.Windows.Media.SolidColorBrush(fgC);

            CompFillCol.Width = new System.Windows.GridLength(Math.Max(0.001, pct), System.Windows.GridUnitType.Star);
            CompRestCol.Width = new System.Windows.GridLength(Math.Max(0.001, 100 - pct), System.Windows.GridUnitType.Star);
        }
        catch { }
    }

    /// <summary>
    /// Quand l'utilisateur change de commissariat, on recharge la liste des services
    /// disponibles pour ce commissariat (BAC, BSU, GAV, etc. selon ce qui est configuré).
    /// </summary>
    private async Task OnCommissariatChangedAsync()
    {
        var cpn = CmbCommissariat.SelectedItem as CpnItem;
        if (cpn == null || cpn.Id == null)
        {
            CmbService.Items.Clear();
            CmbService.IsEnabled = false;
            return;
        }
        await LoadServicesForCpnAsync(cpn.Id.Value, null, null);
    }

    /// <summary>
    /// Remplit la combo Service avec les services actifs du commissariat. Si le commissariat
    /// est un sous-poste sans service propre, on retombe sur ceux du parent (CPN principal).
    /// `selectId` ou `legacyName` permet de pré-sélectionner l'item courant.
    /// </summary>
    private async Task LoadServicesForCpnAsync(int cpnId, int? selectId, string? legacyName)
    {
        try
        {
            CmbService.Items.Clear();
            CmbService.Items.Add(new SvcItem { Id = null, Display = "— Sélectionnez votre service —" });

            // 1) Charge les services directement attachés à ce commissariat
            var list = await DirectoryProfileService.GetServicesAsync(cpnId);

            // 2) Si vide ET sous-poste : remonter au CPN parent
            if (list.Count == 0)
            {
                var current = CmbCommissariat.SelectedItem as CpnItem;
                if (current?.ParentId != null)
                    list = await DirectoryProfileService.GetServicesAsync(current.ParentId.Value);
            }

            string? lastCategory = null;
            foreach (var s in list)
            {
                // Rupture visuelle entre catégories
                if (s.Category != lastCategory && lastCategory != null)
                {
                    CmbService.Items.Add(new SvcItem { Id = null, IsSeparator = true, Display = "──────────" });
                }
                lastCategory = s.Category;

                CmbService.Items.Add(new SvcItem
                {
                    Id      = s.Id,
                    Display = s.DisplayName + (string.IsNullOrEmpty(s.ShortName) ? "" : $"  ({s.ShortName})"),
                });
            }

            CmbService.DisplayMemberPath = nameof(SvcItem.Display);
            CmbService.IsEnabled = list.Count > 0;
            CmbService.SelectedIndex = 0;

            // Pré-sélection
            if (selectId.HasValue)
            {
                foreach (var item in CmbService.Items)
                    if (item is SvcItem si && si.Id == selectId.Value)
                    { CmbService.SelectedItem = item; break; }
            }
            else if (!string.IsNullOrWhiteSpace(legacyName))
            {
                // Tentative de matching sur le nom (legacy free-text)
                foreach (var item in CmbService.Items)
                    if (item is SvcItem si && si.Id != null
                        && si.Display.IndexOf(legacyName, StringComparison.OrdinalIgnoreCase) >= 0)
                    { CmbService.SelectedItem = item; break; }
            }

            // Si la combo est vide (pas de catalogue installé), affiche un placeholder
            if (list.Count == 0)
            {
                CmbService.Items.Clear();
                CmbService.Items.Add(new SvcItem { Id = null, Display = "(Aucun service configuré pour ce commissariat)" });
                CmbService.SelectedIndex = 0;
                CmbService.IsEnabled = false;
            }
        }
        catch (Exception ex)
        {
            TxtError.Text = "Chargement services : " + ex.Message;
        }
    }

    private async void BtnSave_Click(object sender, RoutedEventArgs e)
    {
        TxtError.Text = "";
        BtnSave.IsEnabled = false;

        try
        {
            var cpn = CmbCommissariat.SelectedItem as CpnItem;
            if (cpn == null || cpn.Id == null)
                throw new InvalidOperationException("Veuillez sélectionner votre commissariat.");

            var svc = CmbService.SelectedItem as SvcItem;
            int? serviceId = (svc != null && svc.Id.HasValue) ? svc.Id : (int?)null;
            // On stocke aussi le libellé en clair pour la rétrocompatibilité (ancien champ contacts.service)
            string serviceLabel = serviceId.HasValue
                ? (svc!.Display.Split('(')[0]).Trim()
                : "";

            // Lecture depuis les champs (l'utilisateur peut modifier prénom/nom/email)
            var firstName = TxtFirstName.Text?.Trim() ?? "";
            var lastName  = TxtLastName.Text?.Trim()  ?? "";
            var email     = TxtEmail.Text?.Trim()     ?? "";

            // Grade : lit le SelectedItem
            string roleRaw = (CmbGrade.SelectedItem as string) ?? "";
            if (PoliceGrades.IsHeader(roleRaw) || roleRaw.StartsWith("— ")) roleRaw = "";
            var role = PoliceGrades.Clean(roleRaw);

            // Validations basiques
            if (firstName.Length == 0) throw new InvalidOperationException("Prénom requis.");
            if (lastName.Length == 0)  throw new InvalidOperationException("Nom requis.");
            if (email.Length > 0 && !System.Text.RegularExpressions.Regex.IsMatch(email,
                @"^[^@\s]+@[^@\s]+\.[^@\s]+$"))
                throw new InvalidOperationException("Adresse e-mail invalide.");

            var profile = new DirectoryProfileService.ProfileEntry
            {
                Matricule       = _ad.Matricule,
                FirstName       = firstName,
                LastName        = lastName,
                DisplayName     = $"{firstName} {lastName}".Trim(),
                Email           = email,
                Role            = role,
                Service         = serviceLabel,
                ServiceId       = serviceId,
                CommissariatId  = cpn.Id,
                PhoneFixed      = TxtPhoneFixed.Text?.Trim() ?? "",
                PhoneMobile     = TxtPhoneMobile.Text?.Trim() ?? "",
                PhoneNeo        = TxtPhoneNeo.Text?.Trim() ?? "",
            };

            await DirectoryProfileService.UpsertAsync(profile);

            // Upload photo AD si dispo et pas encore uploadée pour cette session
            if (_ad.Thumbnail != null && _ad.Thumbnail.Length > 0 && !_photoUploaded)
            {
                try
                {
                    var mime = DetectImageMime(_ad.Thumbnail);
                    var path = await DirectoryProfileService.UploadPhotoAsync(_ad.Matricule, _ad.Thumbnail, mime);
                    if (!string.IsNullOrEmpty(path)) _photoUploaded = true;
                }
                catch
                {
                    // Photo upload best-effort : on ignore l'erreur
                }
            }

            // Marque dans le settings utilisateur que le profil a été rempli
            try { ProfileFlag.MarkCompleted(_ad.Matricule); } catch { }

            // Confirmation visuelle puis fermeture
            MessageBox.Show(this,
                "✓ Vos coordonnées ont été enregistrées dans l'annuaire.",
                "DockPolice", MessageBoxButton.OK, MessageBoxImage.Information);
            DialogResult = true;
            Close();
        }
        catch (Exception ex)
        {
            TxtError.Text = "Erreur : " + ex.Message;
        }
        finally
        {
            BtnSave.IsEnabled = true;
        }
    }

    /// <summary>
    /// "Choisir un fichier" — OpenFileDialog → upload direct vers l'annuaire.
    /// </summary>
    private async void BtnUploadPhoto_Click(object sender, RoutedEventArgs e)
    {
        var dlg = new Microsoft.Win32.OpenFileDialog
        {
            Title = "Sélectionnez votre photo de profil",
            Filter = "Images (*.jpg;*.jpeg;*.png;*.webp)|*.jpg;*.jpeg;*.png;*.webp|Tous fichiers|*.*",
            CheckFileExists = true,
        };
        if (dlg.ShowDialog() != true) return;

        var path = dlg.FileName;
        var fi = new FileInfo(path);
        if (!fi.Exists) return;
        if (fi.Length > 3 * 1024 * 1024)
        {
            MessageBox.Show("L'image dépasse 3 Mo. Compressez-la ou choisissez une autre photo.",
                "Trop volumineuse", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        await UploadCustomPhotoAsync(path);
    }

    /// <summary>
    /// "Prendre une photo" — lance l'app Caméra Windows, l'utilisateur prend la photo,
    /// puis on lui demande de la sélectionner dans son dossier "Pellicule".
    /// </summary>
    private async void BtnWebcamPhoto_Click(object sender, RoutedEventArgs e)
    {
        var cameraRoll = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.MyPictures),
            "Camera Roll");

        try
        {
            // Lance l'app Caméra de Windows (URI scheme natif)
            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
            {
                FileName = "microsoft.windows.camera:",
                UseShellExecute = true
            });
        }
        catch (Exception ex)
        {
            MessageBox.Show("Impossible d'ouvrir l'appli Caméra Windows : " + ex.Message
                + "\n\nUtilisez plutôt « Choisir un fichier ».",
                "Webcam indisponible", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        // Petit message d'instructions
        var ok = MessageBox.Show(
            "1. L'application Caméra de Windows va s'ouvrir.\n"
            + "2. Prenez votre photo (cadrez bien le visage).\n"
            + "3. Fermez la Caméra et cliquez sur OK ici.\n"
            + "4. Sélectionnez la photo dans votre dossier « Pellicule ».",
            "Capture webcam", MessageBoxButton.OKCancel, MessageBoxImage.Information);
        if (ok != MessageBoxResult.OK) return;

        // OpenFileDialog ouvert sur le dossier Camera Roll
        var dlg = new Microsoft.Win32.OpenFileDialog
        {
            Title = "Sélectionnez la photo prise",
            Filter = "Images (*.jpg;*.jpeg;*.png)|*.jpg;*.jpeg;*.png",
            InitialDirectory = Directory.Exists(cameraRoll)
                ? cameraRoll
                : Environment.GetFolderPath(Environment.SpecialFolder.MyPictures),
        };
        if (dlg.ShowDialog() != true) return;

        var fi = new FileInfo(dlg.FileName);
        if (fi.Length > 3 * 1024 * 1024)
        {
            MessageBox.Show("L'image dépasse 3 Mo.", "Trop volumineuse",
                MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }
        await UploadCustomPhotoAsync(dlg.FileName);
    }

    /// <summary>
    /// Upload commun : redimensionne (max 512×512), convertit en bytes, envoie via DirectoryProfileService.
    /// </summary>
    private async Task UploadCustomPhotoAsync(string filePath)
    {
        try
        {
            BtnUploadPhoto.IsEnabled = false;
            BtnWebcamPhoto.IsEnabled = false;
            TxtPhotoStatus.Text = "📤 Upload en cours…";

            byte[] data = ResizePhoto(filePath, 512);
            var ext = Path.GetExtension(filePath).TrimStart('.').ToLowerInvariant();
            var mime = ext switch
            {
                "png"  => "image/png",
                "webp" => "image/webp",
                _      => "image/jpeg",
            };

            var matricule = string.IsNullOrWhiteSpace(_ad.Matricule)
                ? Environment.UserName
                : _ad.Matricule;

            var path = await DirectoryProfileService.UploadPhotoAsync(matricule, data, mime);
            if (!string.IsNullOrEmpty(path))
            {
                _ad.Thumbnail = data;
                UpdateAvatar();
                _photoUploaded = true;
                AvatarBadge.Visibility = Visibility.Visible;
                TxtPhotoStatus.Text = "✓ Photo téléversée (" + (data.Length / 1024) + " Ko).";
            }
            else
            {
                TxtPhotoStatus.Text = "⚠ API d'upload non configurée.";
            }
        }
        catch (Exception ex)
        {
            TxtPhotoStatus.Text = "Échec : " + ex.Message;
            MessageBox.Show("Erreur upload : " + ex.Message, "Échec",
                MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            BtnUploadPhoto.IsEnabled = true;
            BtnWebcamPhoto.IsEnabled = true;
        }
    }

    /// <summary>Redimensionne une image en JPEG max 512×512 (proportionnel) côté client.</summary>
    private byte[] ResizePhoto(string filePath, int maxSize)
    {
        var src = new BitmapImage();
        src.BeginInit();
        src.CacheOption = BitmapCacheOption.OnLoad;
        src.UriSource = new Uri(filePath);
        src.EndInit();

        double scale = Math.Min(1.0, Math.Min((double)maxSize / src.PixelWidth, (double)maxSize / src.PixelHeight));
        if (scale >= 1.0)
        {
            // Pas besoin de redimensionner — lit le fichier brut
            return File.ReadAllBytes(filePath);
        }

        var transformed = new TransformedBitmap(src, new ScaleTransform(scale, scale));
        var encoder = new JpegBitmapEncoder { QualityLevel = 88 };
        encoder.Frames.Add(BitmapFrame.Create(transformed));
        using var ms = new MemoryStream();
        encoder.Save(ms);
        return ms.ToArray();
    }

    /// <summary>
    /// Télécharge la photo depuis l'annuaire (uploads/contacts/<photoPath>) et l'affiche.
    /// Remplace la photo AD si présente, ou les initiales sinon.
    /// </summary>
    private async Task LoadDirectoryPhotoAsync(string photoFile)
    {
        try
        {
            var cfg = ConfigService.Load();
            var apiBase = (cfg?.ApiBaseUrl ?? "").TrimEnd('/');
            if (string.IsNullOrEmpty(apiBase)) return;

            var url = $"{apiBase}/uploads/contacts/{Uri.EscapeDataString(photoFile)}";
            using var http = new System.Net.Http.HttpClient { Timeout = TimeSpan.FromSeconds(8) };
            var bytes = await http.GetByteArrayAsync(url);
            if (bytes == null || bytes.Length < 200) return;

            _ad.Thumbnail = bytes;
            UpdateAvatar();
            _photoUploaded = true;
            AvatarBadge.Visibility = Visibility.Visible;
            TxtPhotoStatus.Text = "✓ Photo annuaire chargée (" + (bytes.Length / 1024) + " Ko).";
        }
        catch (Exception ex)
        {
            System.Diagnostics.Debug.WriteLine("LoadDirectoryPhotoAsync: " + ex.Message);
            // Pas d'alerte : on garde l'affichage existant (initiales / AD)
        }
    }

    private async void BtnRefreshPhoto_Click(object sender, RoutedEventArgs e)
    {
        BtnRefreshPhoto.IsEnabled = false;
        try
        {
            // Re-lecture de la photo depuis AD
            var fresh = ActiveDirectoryService.GetCurrentUser();
            _ad.Thumbnail = fresh.Thumbnail;
            UpdateAvatar();

            if (_ad.Thumbnail == null || _ad.Thumbnail.Length == 0)
            {
                TxtPhotoStatus.Text = "Aucune photo n'est associée à votre compte AD.";
                return;
            }

            // Upload immédiat
            var mime = DetectImageMime(_ad.Thumbnail);
            var path = await DirectoryProfileService.UploadPhotoAsync(_ad.Matricule, _ad.Thumbnail, mime);
            if (!string.IsNullOrEmpty(path))
            {
                _photoUploaded = true;
                TxtPhotoStatus.Text = "✓ Photo synchronisée avec l'annuaire (" + (_ad.Thumbnail.Length / 1024) + " Ko).";
                AvatarBadge.Visibility = Visibility.Visible;
            }
            else
            {
                TxtPhotoStatus.Text = "Photo détectée mais l'API d'upload n'est pas configurée.";
            }
        }
        catch (Exception ex)
        {
            TxtPhotoStatus.Text = "Échec : " + ex.Message;
        }
        finally
        {
            BtnRefreshPhoto.IsEnabled = true;
        }
    }

    private void UpdateAvatar()
    {
        if (_ad.Thumbnail != null && _ad.Thumbnail.Length > 0)
        {
            try
            {
                var bmp = new BitmapImage();
                using var ms = new MemoryStream(_ad.Thumbnail);
                bmp.BeginInit();
                bmp.CacheOption = BitmapCacheOption.OnLoad;
                bmp.StreamSource = ms;
                bmp.EndInit();
                bmp.Freeze();
                AvatarPhotoBrush.ImageSource = bmp;
                AvatarPhotoMask.Visibility = Visibility.Visible;
                AvatarInitials.Visibility = Visibility.Collapsed;
                AvatarBadge.Visibility = Visibility.Visible;
                return;
            }
            catch { }
        }
        // Initiales colorées
        AvatarPhotoMask.Visibility = Visibility.Collapsed;
        AvatarInitials.Visibility = Visibility.Visible;
        AvatarBadge.Visibility = Visibility.Collapsed;
        AvatarInitials.Text = GetInitials(_ad.FirstName, _ad.LastName, _ad.Matricule);
        AvatarBorder.Background = new SolidColorBrush(ColorFromString(_ad.Matricule));
    }

    private void UpdatePhotoStatus()
    {
        if (_ad.Thumbnail != null && _ad.Thumbnail.Length > 0)
        {
            TxtPhotoStatus.Text = $"✓ Photo récupérée depuis Active Directory ({_ad.Thumbnail.Length / 1024} Ko). Sera enregistrée à la sauvegarde.";
        }
        else
        {
            TxtPhotoStatus.Text = "Aucune photo détectée dans Active Directory. Demandez à votre service informatique d'en ajouter une.";
        }
    }

    private static string DetectImageMime(byte[] bytes)
    {
        if (bytes.Length >= 4 && bytes[0] == 0xFF && bytes[1] == 0xD8) return "image/jpeg";
        if (bytes.Length >= 8 && bytes[0] == 0x89 && bytes[1] == 'P' && bytes[2] == 'N' && bytes[3] == 'G') return "image/png";
        if (bytes.Length >= 12 && bytes[0] == 'R' && bytes[1] == 'I' && bytes[2] == 'F' && bytes[3] == 'F'
            && bytes[8] == 'W' && bytes[9] == 'E' && bytes[10] == 'B' && bytes[11] == 'P') return "image/webp";
        return "image/jpeg"; // fallback raisonnable pour AD
    }

    private static string GetInitials(string fn, string ln, string fallback)
    {
        if (!string.IsNullOrWhiteSpace(fn) && !string.IsNullOrWhiteSpace(ln))
            return ($"{fn[0]}{ln[0]}").ToUpperInvariant();
        if (!string.IsNullOrWhiteSpace(fn)) return fn[0].ToString().ToUpperInvariant();
        return string.IsNullOrWhiteSpace(fallback) ? "?" : fallback[0].ToString().ToUpperInvariant();
    }

    private static Color ColorFromString(string s)
    {
        // Hash simple → palette
        var palette = new[] {
            Color.FromRgb(78,139,255), Color.FromRgb(34,197,94), Color.FromRgb(234,179,8),
            Color.FromRgb(239,68,68), Color.FromRgb(167,139,250), Color.FromRgb(6,182,212),
            Color.FromRgb(236,72,153), Color.FromRgb(132,204,22)
        };
        int h = 0;
        foreach (var c in (s ?? "")) h = ((h << 5) - h + c) & 0xFFFFFF;
        return palette[Math.Abs(h) % palette.Length];
    }

    private void BtnLater_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private class CpnItem
    {
        public int? Id { get; set; }
        public int? ParentId { get; set; }
        public string Display { get; set; } = "";
        public override string ToString() => Display;
    }

    private class SvcItem
    {
        public int? Id { get; set; }
        public bool IsSeparator { get; set; }
        public string Display { get; set; } = "";
        public override string ToString() => Display;
    }
}

/// <summary>
/// Petit flag local pour ne pas re-proposer la fenêtre profil tous les jours
/// si l'utilisateur l'a déjà remplie récemment.
/// </summary>
public static class ProfileFlag
{
    private static string FilePath => System.IO.Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
        "DockPolice", "profile.flag");

    public static void MarkCompleted(string matricule)
    {
        var dir = System.IO.Path.GetDirectoryName(FilePath)!;
        if (!System.IO.Directory.Exists(dir)) System.IO.Directory.CreateDirectory(dir);
        System.IO.File.WriteAllText(FilePath, matricule + "|" + DateTime.UtcNow.ToString("o"));
    }

    public static bool IsCompletedRecently(string matricule, TimeSpan maxAge)
    {
        try
        {
            if (!System.IO.File.Exists(FilePath)) return false;
            var raw = System.IO.File.ReadAllText(FilePath).Split('|');
            if (raw.Length < 2) return false;
            if (!string.Equals(raw[0], matricule, StringComparison.OrdinalIgnoreCase)) return false;
            if (!DateTime.TryParse(raw[1], null, System.Globalization.DateTimeStyles.RoundtripKind, out var when)) return false;
            return DateTime.UtcNow - when < maxAge;
        }
        catch { return false; }
    }
}
