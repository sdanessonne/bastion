using System;
using System.Threading;
using System.Windows;
using DockLite.Services;

namespace DockLite;

public partial class App : Application
{
    // Mutex par session utilisateur : empêche deux instances dans la même session
    // (le service Windows pourrait tenter de relancer le dock alors qu'il tourne déjà)
    private Mutex? _instanceMutex;

    // Service de détection carte agent (PC/SC + cert store) — accessible globalement
    public static SmartCardService? SmartCard { get; private set; }

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        // Auto-update : si une nouvelle version est en attente, swap & quitte (le script relance la nouvelle exe)
        if (AutoUpdateService.TryApplyPendingUpdate())
        {
            // Le script swap-on-next-launch.cmd va exécuter la nouvelle version et fermer celle-ci
            Shutdown();
            Environment.Exit(0);
            return;
        }

        const string mutexName = "Local\\DockPoliceSingleInstance";
        _instanceMutex = new Mutex(initiallyOwned: true, mutexName, out bool createdNew);

        if (!createdNew)
        {
            // Une autre instance est déjà active dans cette session : on quitte silencieusement
            _instanceMutex.Dispose();
            _instanceMutex = null;
            Shutdown();
            Environment.Exit(0);
        }

        // Démarre la surveillance du lecteur de carte agent + serveur HTTP local 127.0.0.1:43782
        // (consommé par login.php pour afficher l'état du lecteur en temps réel)
        try
        {
            SmartCard = new SmartCardService();
            SmartCard.Start();
        }
        catch (Exception ex)
        {
            // Log pour debug — best-effort, l'app reste fonctionnelle
            try
            {
                var dir = System.IO.Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                    "DockPolice");
                System.IO.Directory.CreateDirectory(dir);
                System.IO.File.AppendAllText(
                    System.IO.Path.Combine(dir, "smartcard.log"),
                    $"[{DateTime.Now:O}] App.OnStartup SmartCard.Start() FAILED: {ex.GetType().Name}: {ex.Message}\r\n{ex.StackTrace}\r\n\r\n");
            }
            catch { }
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        try { SmartCard?.Dispose(); } catch { }
        try { _instanceMutex?.ReleaseMutex(); } catch { }
        _instanceMutex?.Dispose();
        base.OnExit(e);
    }
}
