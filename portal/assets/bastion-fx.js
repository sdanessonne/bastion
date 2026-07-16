/* Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt. */
/* Constellation de particules (réseau) — autonome, dégradé si non supporté. */
(function () {
  try {
    if (window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches) { return; }
    var c = document.getElementById('fx');
    if (!c || !c.getContext) { return; }
    var x = c.getContext('2d'), w, h, pts, DPR = Math.min(window.devicePixelRatio || 1, 2);
    function size() { w = c.width = innerWidth * DPR; h = c.height = innerHeight * DPR; c.style.width = innerWidth + 'px'; c.style.height = innerHeight + 'px'; }
    function init() { var n = Math.min(80, Math.floor(innerWidth / 20)); pts = []; for (var i = 0; i < n; i++) { pts.push({ x: Math.random() * w, y: Math.random() * h, vx: (Math.random() - 0.5) * 0.25 * DPR, vy: (Math.random() - 0.5) * 0.25 * DPR }); } }
    var LINK = 140 * DPR, LINK2 = LINK * LINK;
    function loop() {
      x.clearRect(0, 0, w, h);
      for (var i = 0; i < pts.length; i++) {
        var p = pts[i]; p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > w) { p.vx *= -1; } if (p.y < 0 || p.y > h) { p.vy *= -1; }
        x.beginPath(); x.arc(p.x, p.y, 1.6 * DPR, 0, 6.283); x.fillStyle = 'rgba(125,211,252,.7)'; x.fill();
        for (var j = i + 1; j < pts.length; j++) {
          var q = pts[j], dx = p.x - q.x, dy = p.y - q.y, d = dx * dx + dy * dy;
          if (d < LINK2) { x.globalAlpha = 1 - d / LINK2; x.strokeStyle = 'rgba(56,189,248,.38)'; x.lineWidth = DPR * 0.6; x.beginPath(); x.moveTo(p.x, p.y); x.lineTo(q.x, q.y); x.stroke(); x.globalAlpha = 1; }
        }
      }
      requestAnimationFrame(loop);
    }
    size(); init(); loop();
    var t; addEventListener('resize', function () { clearTimeout(t); t = setTimeout(function () { size(); init(); }, 200); });
  } catch (e) {}
})();
