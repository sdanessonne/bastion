using System.Collections.Generic;

namespace DockLite.Models;

public enum DockPosition { Top, Bottom, Left, Right }

public class DockConfig
{
    public DockPosition Position { get; set; } = DockPosition.Top;
    public int IconSize { get; set; } = 64;
    public int MaxIconSize { get; set; } = 110;
    public bool AutoHide { get; set; } = true;
    public bool MagnifyOnHover { get; set; } = true;
    public int IconSpacing { get; set; } = 12;
    public bool ShowSystemInfo { get; set; } = true;

    /// <summary>Affiche l'icône « Demande d'assistance » dans la barre.</summary>
    public bool ShowSupportTicket { get; set; } = true;

    /// <summary>
    /// Page d'assistance de l'intranet Bastion, ouverte par l'icône du même nom.
    ///
    /// ── POURQUOI UN LIEN, ET NON UN FORMULAIRE DANS LE DOCK ───────────────────
    /// Le dock d'origine embarquait tout un système de tickets, avec sa base et sa
    /// file d'attente hors ligne. Bastion a déjà le sien : les demandes déposées
    /// par les agents sur l'intranet arrivent dans la console, page « Demandes
    /// d'assistance ». Un second formulaire aurait créé un second endroit où
    /// chercher une demande — et des agents persuadés d'avoir signalé une panne
    /// que personne n'aurait vue.
    ///
    /// L'adresse par défaut vise la passerelle sur le plan d'adressage standard.
    /// Le port 2080 répond par une REDIRECTION vers 2443 en HTTPS — les deux sont
    /// laissés passer par le portail captif (vérifié sur la passerelle en service :
    /// « users_to_router » autorise 2080 et 2443). Un poste qui n'a pas encore
    /// ouvert de session réseau peut donc quand même signaler sa panne, ce qui est
    /// précisément le moment où il en a besoin.
    ///
    /// Le certificat de 2443 est signé par l'autorité de Bastion, déployée aux
    /// postes par stratégie de groupe. Sur un poste qui ne l'a pas encore reçue, le
    /// navigateur affichera un avertissement : ce n'est pas une panne du dock.
    ///
    /// Modifiable dans apps.json si le plan d'adressage du site diffère.
    /// </summary>
    public string AssistanceUrl { get; set; } = "http://192.168.182.1:2080/portal/intranet/assistance.php";

    public List<DockItem> Items { get; set; } = new();
}
