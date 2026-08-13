using System;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Threading.Tasks;
using System.Windows.Threading;

namespace DockLite.Services;

[SupportedOSPlatform("windows")]
public static class RemoteInputService
{
    // ================================================================
    // Win32 : SendInput + SetCursorPos
    // ================================================================

    [DllImport("user32.dll", SetLastError = true)]
    private static extern uint SendInput(uint nInputs, INPUT[] pInputs, int cbSize);

    [DllImport("user32.dll")]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetCursorPos(int x, int y);

    [DllImport("user32.dll")]
    private static extern int GetSystemMetrics(int nIndex);
    private const int SM_XVIRTUALSCREEN = 76;
    private const int SM_YVIRTUALSCREEN = 77;
    private const int SM_CXVIRTUALSCREEN = 78;
    private const int SM_CYVIRTUALSCREEN = 79;

    [StructLayout(LayoutKind.Sequential)]
    private struct INPUT
    {
        public int type;
        public InputUnion u;
    }

    [StructLayout(LayoutKind.Explicit)]
    private struct InputUnion
    {
        [FieldOffset(0)] public MOUSEINPUT mi;
        [FieldOffset(0)] public KEYBDINPUT ki;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct MOUSEINPUT
    {
        public int dx, dy;
        public uint mouseData;
        public uint dwFlags;
        public uint time;
        public IntPtr dwExtraInfo;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct KEYBDINPUT
    {
        public ushort wVk;
        public ushort wScan;
        public uint dwFlags;
        public uint time;
        public IntPtr dwExtraInfo;
    }

    private const int INPUT_MOUSE = 0;
    private const int INPUT_KEYBOARD = 1;

    private const uint MOUSEEVENTF_LEFTDOWN   = 0x0002;
    private const uint MOUSEEVENTF_LEFTUP     = 0x0004;
    private const uint MOUSEEVENTF_RIGHTDOWN  = 0x0008;
    private const uint MOUSEEVENTF_RIGHTUP    = 0x0010;
    private const uint MOUSEEVENTF_MIDDLEDOWN = 0x0020;
    private const uint MOUSEEVENTF_MIDDLEUP   = 0x0040;
    private const uint MOUSEEVENTF_WHEEL      = 0x0800;
    private const uint KEYEVENTF_KEYUP        = 0x0002;
    private const uint KEYEVENTF_UNICODE      = 0x0004;

    // ================================================================
    // Polling HTTP
    // ================================================================

    private static readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(5) };
    private static DispatcherTimer? _timer;
    private static bool _busy;
    private static long _lastConsumedId;

    public static void Start()
    {
        if (!AttachmentApi.IsConfigured) return;
        _timer = new DispatcherTimer { Interval = TimeSpan.FromMilliseconds(250) };
        _timer.Tick += async (_, _) => await Tick();
        _timer.Start();
    }

    private static async Task Tick()
    {
        if (_busy) return;
        _busy = true;
        try
        {
            var url = AttachmentApi.BaseUrl!.TrimEnd('/')
                    + $"/api/input-poll.php?machine={Uri.EscapeDataString(Environment.MachineName)}&since={_lastConsumedId}";

            using var req = new HttpRequestMessage(HttpMethod.Get, url);
            req.Headers.Add("X-API-Key", AttachmentApi.ApiKey);
            using var resp = await _http.SendAsync(req);
            if (!resp.IsSuccessStatusCode) return;

            var body = await resp.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(body);
            if (!doc.RootElement.TryGetProperty("events", out var events)) return;

            foreach (var ev in events.EnumerateArray())
            {
                try
                {
                    long id = ev.GetProperty("id").GetInt64();
                    string type = ev.GetProperty("type").GetString() ?? "";
                    var data = ev.GetProperty("data");
                    Dispatch(type, data);
                    if (id > _lastConsumedId) _lastConsumedId = id;
                }
                catch { /* skip bad event */ }
            }
        }
        catch { /* silent */ }
        finally { _busy = false; }
    }

    // ================================================================
    // Dispatch d'un événement
    // ================================================================

    private static void Dispatch(string type, JsonElement data)
    {
        switch (type)
        {
            case "mouse_move":
                SetCursorPos(data.GetProperty("x").GetInt32(), data.GetProperty("y").GetInt32());
                break;

            case "mouse_down":
                SetCursorPos(data.GetProperty("x").GetInt32(), data.GetProperty("y").GetInt32());
                SendMouse(MouseDownFlag(data.GetProperty("button").GetString() ?? "left"));
                break;

            case "mouse_up":
                SetCursorPos(data.GetProperty("x").GetInt32(), data.GetProperty("y").GetInt32());
                SendMouse(MouseUpFlag(data.GetProperty("button").GetString() ?? "left"));
                break;

            case "mouse_click":
                SetCursorPos(data.GetProperty("x").GetInt32(), data.GetProperty("y").GetInt32());
                string btn = data.GetProperty("button").GetString() ?? "left";
                SendMouse(MouseDownFlag(btn));
                SendMouse(MouseUpFlag(btn));
                break;

            case "mouse_double":
                SetCursorPos(data.GetProperty("x").GetInt32(), data.GetProperty("y").GetInt32());
                string btn2 = data.GetProperty("button").GetString() ?? "left";
                SendMouse(MouseDownFlag(btn2));
                SendMouse(MouseUpFlag(btn2));
                SendMouse(MouseDownFlag(btn2));
                SendMouse(MouseUpFlag(btn2));
                break;

            case "mouse_scroll":
                // delta positif = scroll vers le haut (convention WHEEL_DELTA = 120)
                int delta = data.GetProperty("delta").GetInt32();
                SendScroll(delta);
                break;

            case "key_down":
                SendKey((ushort)data.GetProperty("vk").GetInt32(), down: true);
                break;

            case "key_up":
                SendKey((ushort)data.GetProperty("vk").GetInt32(), down: false);
                break;

            case "text":
                SendText(data.GetProperty("text").GetString() ?? "");
                break;
        }
    }

    private static uint MouseDownFlag(string btn) => btn switch
    {
        "right" => MOUSEEVENTF_RIGHTDOWN,
        "middle" => MOUSEEVENTF_MIDDLEDOWN,
        _ => MOUSEEVENTF_LEFTDOWN
    };

    private static uint MouseUpFlag(string btn) => btn switch
    {
        "right" => MOUSEEVENTF_RIGHTUP,
        "middle" => MOUSEEVENTF_MIDDLEUP,
        _ => MOUSEEVENTF_LEFTUP
    };

    private static void SendMouse(uint flags, uint data = 0)
    {
        var input = new INPUT
        {
            type = INPUT_MOUSE,
            u = new InputUnion
            {
                mi = new MOUSEINPUT
                {
                    dx = 0, dy = 0,
                    mouseData = data,
                    dwFlags = flags,
                    time = 0,
                    dwExtraInfo = IntPtr.Zero
                }
            }
        };
        SendInput(1, new[] { input }, Marshal.SizeOf<INPUT>());
    }

    private static void SendScroll(int delta)
    {
        var input = new INPUT
        {
            type = INPUT_MOUSE,
            u = new InputUnion
            {
                mi = new MOUSEINPUT
                {
                    dx = 0, dy = 0,
                    mouseData = (uint)delta,
                    dwFlags = MOUSEEVENTF_WHEEL,
                    time = 0,
                    dwExtraInfo = IntPtr.Zero
                }
            }
        };
        SendInput(1, new[] { input }, Marshal.SizeOf<INPUT>());
    }

    private static void SendKey(ushort vk, bool down)
    {
        var input = new INPUT
        {
            type = INPUT_KEYBOARD,
            u = new InputUnion
            {
                ki = new KEYBDINPUT
                {
                    wVk = vk,
                    wScan = 0,
                    dwFlags = down ? 0u : KEYEVENTF_KEYUP,
                    time = 0,
                    dwExtraInfo = IntPtr.Zero
                }
            }
        };
        SendInput(1, new[] { input }, Marshal.SizeOf<INPUT>());
    }

    private static void SendText(string text)
    {
        foreach (var ch in text)
        {
            SendChar(ch, down: true);
            SendChar(ch, down: false);
        }
    }

    private static void SendChar(char ch, bool down)
    {
        var input = new INPUT
        {
            type = INPUT_KEYBOARD,
            u = new InputUnion
            {
                ki = new KEYBDINPUT
                {
                    wVk = 0,
                    wScan = ch,
                    dwFlags = KEYEVENTF_UNICODE | (down ? 0u : KEYEVENTF_KEYUP),
                    time = 0,
                    dwExtraInfo = IntPtr.Zero
                }
            }
        };
        SendInput(1, new[] { input }, Marshal.SizeOf<INPUT>());
    }
}
