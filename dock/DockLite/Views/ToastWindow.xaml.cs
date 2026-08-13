using System;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media.Animation;
using System.Windows.Threading;
using DockLite.Services;

namespace DockLite.Views;

public partial class ToastWindow : Window
{
    private readonly DispatcherTimer _autoCloseTimer;
    public event EventHandler<int>? OpenTicketRequested;
    public int TicketId { get; }

    public ToastWindow(TicketService.TicketReplyNotification notif)
    {
        InitializeComponent();
        TicketId = notif.TicketId;

        TitleText.Text = $"Réponse à votre ticket #{notif.TicketId}";
        SubjectText.Text = notif.TicketSubject;
        BodyText.Text = notif.Body;
        MetaText.Text = $"De : {notif.Author}  •  {notif.CreatedAt:HH:mm}";

        Loaded += OnLoaded;

        _autoCloseTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(12) };
        _autoCloseTimer.Tick += (_, _) => StartFadeOut();
        _autoCloseTimer.Start();
    }

    public static int OpenToastsCount { get; private set; }

    private void OnLoaded(object sender, RoutedEventArgs e)
    {
        var workArea = SystemParameters.WorkArea;
        var index = OpenToastsCount;
        OpenToastsCount++;

        Left = workArea.Right - ActualWidth - 16;
        Top  = workArea.Bottom - ActualHeight - 16 - (index * (ActualHeight + 10));

        Opacity = 0;
        var startLeft = workArea.Right + 20;
        var endLeft   = workArea.Right - ActualWidth - 16;

        Left = startLeft;
        var slide = new DoubleAnimation(startLeft, endLeft, TimeSpan.FromMilliseconds(280))
        {
            EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut }
        };
        var fade = new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(220));

        BeginAnimation(LeftProperty, slide);
        BeginAnimation(OpacityProperty, fade);
    }

    private void StartFadeOut()
    {
        _autoCloseTimer.Stop();
        var fade = new DoubleAnimation(Opacity, 0, TimeSpan.FromMilliseconds(220));
        fade.Completed += (_, _) =>
        {
            OpenToastsCount = Math.Max(0, OpenToastsCount - 1);
            Close();
        };
        BeginAnimation(OpacityProperty, fade);
    }

    private void Border_MouseLeftButtonUp(object sender, MouseButtonEventArgs e)
    {
        OpenTicketRequested?.Invoke(this, TicketId);
        StartFadeOut();
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e)
    {
        StartFadeOut();
    }
}
