/* Tema claro/escuro do painel.
 *
 *   SNTheme.get()            -> 'dark' | 'light'
 *   SNTheme.set('light')     -> aplica e salva a escolha
 *   SNTheme.toggle()
 *   SNTheme.chart()          -> { tick, grid, legend } para o Chart.js
 *
 * Carregado no <head> (sem defer) para aplicar o tema antes da primeira pintura.
 * Evento 'sn-theme' (document) dispara a cada troca.
 */
(function () {
    const KEY = 'sn-theme';
    const root = document.documentElement;

    function read() {
        try { return localStorage.getItem(KEY) === 'light' ? 'light' : 'dark'; }
        catch (e) { return 'dark'; }
    }

    let current = read();
    root.setAttribute('data-theme', current);

    function paintButtons() {
        document.querySelectorAll('.theme-toggle').forEach(b => {
            const light = current === 'light';
            b.setAttribute('aria-label', light ? 'Ativar modo escuro' : 'Ativar modo claro');
            b.title = light ? 'Modo escuro' : 'Modo claro';
            b.querySelector('i').className = 'fa-solid ' + (light ? 'fa-moon' : 'fa-sun');
        });
    }

    function set(theme) {
        current = theme === 'light' ? 'light' : 'dark';
        root.setAttribute('data-theme', current);
        try { localStorage.setItem(KEY, current); } catch (e) {}
        paintButtons();
        document.dispatchEvent(new CustomEvent('sn-theme', { detail: current }));
    }

    window.SNTheme = {
        get: () => current,
        set: set,
        toggle: () => set(current === 'light' ? 'dark' : 'light'),
        chart: () => current === 'light'
            ? { tick: 'rgba(23,42,76,0.65)', grid: 'rgba(23,42,76,0.08)', legend: 'rgba(23,42,76,0.85)' }
            : { tick: 'rgba(255,255,255,0.5)', grid: 'rgba(255,255,255,0.06)', legend: 'rgba(255,255,255,0.8)' },
    };

    // Sincroniza entre abas abertas.
    window.addEventListener('storage', e => { if (e.key === KEY) set(read()); });

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'theme-toggle';
        btn.append(document.createElement('i'));
        btn.onclick = () => window.SNTheme.toggle();

        const sair = document.querySelector('.btn-sair');
        if (sair && sair.parentNode) {
            sair.parentNode.insertBefore(btn, sair);
        } else {
            btn.classList.add('theme-toggle-float');   // login e telas sem topbar
            document.body.append(btn);
        }
        paintButtons();
    });
})();
