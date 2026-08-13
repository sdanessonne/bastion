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



}
