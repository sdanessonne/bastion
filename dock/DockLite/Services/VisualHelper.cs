using System.Windows;
using System.Windows.Media;

namespace DockLite.Views;

public static class VisualHelper
{
    public static T? FindChild<T>(DependencyObject parent) where T : DependencyObject
    {
        if (parent == null) return null;
        var count = VisualTreeHelper.GetChildrenCount(parent);
        for (int i = 0; i < count; i++)
        {
            var child = VisualTreeHelper.GetChild(parent, i);
            if (child is T match) return match;
            var deep = FindChild<T>(child);
            if (deep != null) return deep;
        }
        return null;
    }
}
