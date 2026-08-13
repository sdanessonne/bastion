using System;
using System.Threading;
using System.Windows;

namespace DockLite;

public partial class App : Application
{
    // Mutex par session utilisateur : empêche deux instances dans la même session.
    // Le dock est lancé à l'ouverture de session ; sans ce verrou, une seconde
    // ouverture (session RDP, reconnexion) donnerait deux barres superposées.
    private Mutex? _instanceMutex;

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        // ── CE QUI A ÉTÉ RETIRÉ ICI, ET POURQUOI ─────────────────────────────
        // Le démarrage d'origine faisait deux choses de plus.
        //
        // 1. Une MISE À JOUR AUTOMATIQUE qui remplaçait l'exécutable et relançait
        //    l'application. Sur un parc géré, un logiciel qui se met à jour tout
        //    seul contourne le store d'applications : la console afficherait une
        //    version, les postes en auraient une autre, et rien ne le signalerait.
        //    Le déploiement passe désormais par la stratégie de groupe, comme
        //    pour les autres logiciels.
        //
        // 2. Un service de LECTEUR DE CARTE AGENT qui ouvrait un serveur HTTP sur
        //    127.0.0.1:43782, consommé par la page de connexion du backoffice
        //    d'origine. Bastion n'a pas ce backoffice, et un port en écoute sur
        //    chaque poste sans que personne ne l'utilise est une surface d'attaque
        //    offerte pour rien.
        const string mutexName = "Local\\BastionDockSingleInstance";
        _instanceMutex = new Mutex(initiallyOwned: true, mutexName, out bool createdNew);

        if (!createdNew)
        {
            // Une autre instance est déjà active dans cette session : on quitte
            // silencieusement. Un message ici s'afficherait à chaque ouverture de
            // session sur les postes où le dock est déjà lancé.
            _instanceMutex.Dispose();
            _instanceMutex = null;
            Shutdown();
            Environment.Exit(0);
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        try { _instanceMutex?.ReleaseMutex(); } catch { }
        _instanceMutex?.Dispose();
        base.OnExit(e);
    }
}
