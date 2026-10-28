// Aviso de alertas do hotspot: consulta alertas_api.php e mostra um aviso fixo no rodape.
// A propria consulta dispara a verificacao (limitada a uma por 30 s no servidor).
(function () {
    var titulo = document.title;
    var barra = null;

    function desenhar(d) {
        if (!d.total) {
            if (barra) { barra.remove(); barra = null; }
            document.title = titulo;
            return;
        }
        if (!barra) {
            barra = document.createElement('a');
            barra.href = 'alertas.php';
            barra.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:70;' +
                'max-width:min(92vw,640px);padding:12px 18px;border-radius:14px;font:700 14px Raleway,sans-serif;' +
                'color:#fff;text-decoration:none;background:rgba(220,38,38,.95);box-shadow:0 10px 40px rgba(0,0,0,.5);';
            document.body.appendChild(barra);
        }
        var m = d.alertas[0] ? d.alertas[0].mensagem : '';
        barra.textContent = '⚠ ' + d.total + ' alerta' + (d.total > 1 ? 's' : '') + ': ' + m + '  → ver';
        document.title = '(' + d.total + ') ' + titulo;
    }

    function consultar() {
        if (document.hidden) return;
        fetch('alertas_api.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.success) desenhar(d); })
            .catch(function () { /* sem rede: tenta de novo no proximo ciclo */ });
    }

    consultar();
    setInterval(consultar, 20000);
    document.addEventListener('visibilitychange', consultar);
})();
