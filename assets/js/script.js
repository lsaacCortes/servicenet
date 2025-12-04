// 1. Cursor Personalizado
const cursor = document.querySelector('.cursor');
const follower = document.querySelector('.cursor-follower');

document.addEventListener('mousemove', (e) => {
    cursor.style.left = e.clientX + 'px';
    cursor.style.top = e.clientY + 'px';
    
    // Pequeno delay para o seguidor (efeito suave)
    setTimeout(() => {
        follower.style.left = e.clientX + 'px';
        follower.style.top = e.clientY + 'px';
    }, 50);
});

// Efeito Hover nos Links
document.querySelectorAll('a, button').forEach(item => {
    item.addEventListener('mouseenter', () => {
        follower.style.transform = 'translate(-50%, -50%) scale(1.5)';
        follower.style.borderColor = '#FFF';
    });
    item.addEventListener('mouseleave', () => {
        follower.style.transform = 'translate(-50%, -50%) scale(1)';
        follower.style.borderColor = '#FF5500';
    });
});

// 2. Animação ao Rolar (Scroll Reveal)
const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
        }
    });
});

const hiddenElements = document.querySelectorAll('.fade-in');
hiddenElements.forEach((el) => observer.observe(el));

// 3. Velocímetro Animado (Simples)
const speedCount = document.getElementById('speed-count');
let speed = 880;

setInterval(() => {
    // Oscila entre 880 e 920 para parecer real
    const change = Math.floor(Math.random() * 10) - 5; 
    let newSpeed = speed + change;
    
    if(newSpeed > 910) newSpeed = 910;
    if(newSpeed < 890) newSpeed = 890;
    
    speed = newSpeed;
    speedCount.innerText = speed;
}, 500);

/* ----------------------------------------------------------------
   EFEITO DE REDE NEURAL / PARTÍCULAS (CANVAS)
---------------------------------------------------------------- */
const canvas = document.getElementById('network-canvas');
const ctx = canvas.getContext('2d');

let particlesArray;

// Ajusta o tamanho do canvas para a tela inteira
canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

// Redimensionar canvas se a janela mudar
window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    initParticles(); // Recria as partículas
});

// Classe Partícula (Cada pontinho na tela)
class Particle {
    constructor() {
        this.x = Math.random() * canvas.width;
        this.y = Math.random() * canvas.height;
        this.directionX = (Math.random() * 2) - 1; // Velocidade aleatória X
        this.directionY = (Math.random() * 2) - 1; // Velocidade aleatória Y
        this.size = (Math.random() * 2) + 1; // Tamanho entre 1 e 3
        this.color = '#FF5500'; // Cor Laranja (Servicenet) ou use Branco
    }

    // Desenha o ponto
    draw() {
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2, false);
        ctx.fillStyle = 'rgba(255, 85, 0, 0.6)'; // Laranja com transparência
        ctx.fill();
    }

    // Atualiza a posição (movimento)
    update() {
        // Se bater na borda, inverte a direção
        if (this.x > canvas.width || this.x < 0) {
            this.directionX = -this.directionX;
        }
        if (this.y > canvas.height || this.y < 0) {
            this.directionY = -this.directionY;
        }

        // Move a partícula
        this.x += this.directionX;
        this.y += this.directionY;

        this.draw();
    }
}

// Cria o array de partículas
function initParticles() {
    particlesArray = [];
    // Número de partículas baseado no tamanho da tela
    // (Mais partículas em telas grandes, menos em celular para não travar)
    let numberOfParticles = (canvas.width * canvas.height) / 9000;
    
    for (let i = 0; i < numberOfParticles; i++) {
        particlesArray.push(new Particle());
    }
}

// Animação Loop (Conexões)
function animateParticles() {
    requestAnimationFrame(animateParticles);
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    for (let i = 0; i < particlesArray.length; i++) {
        particlesArray[i].update();
    }
    
    connect(); // Chama a função que desenha as linhas
}

// Desenha linhas entre partículas próximas (Simula a Rede)
function connect() {
    let opacityValue = 1;
    for (let a = 0; a < particlesArray.length; a++) {
        for (let b = a; b < particlesArray.length; b++) {
            // Calcula distância (Pitágoras)
            let distance = ((particlesArray[a].x - particlesArray[b].x) * (particlesArray[a].x - particlesArray[b].x))
                         + ((particlesArray[a].y - particlesArray[b].y) * (particlesArray[a].y - particlesArray[b].y));
            
            // Se a distância for menor que um limite, desenha a linha
            if (distance < (canvas.width/7) * (canvas.height/7)) {
                opacityValue = 1 - (distance / 20000);
                
                // Cor da Linha (Branco/Azulado bem suave)
                ctx.strokeStyle = 'rgba(100, 200, 255,' + opacityValue + ')'; 
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(particlesArray[a].x, particlesArray[a].y);
                ctx.lineTo(particlesArray[b].x, particlesArray[b].y);
                ctx.stroke();
            }
        }
    }
}

// Inicia tudo
initParticles();
animateParticles();

/* ----------------------------------------------------------------
   PRE-LOADER LOGIC
---------------------------------------------------------------- */
window.addEventListener('load', () => {
    const preloader = document.getElementById('preloader');
    
    // Pequeno delay proposital para o usuário ver a marca (opcional)
    // Se quiser que suma instantaneamente, remova o setTimeout
    setTimeout(() => {
        preloader.classList.add('hide-loader');
        
        // Remove o elemento do HTML depois que a animação acabar para liberar memória
        setTimeout(() => {
            preloader.remove();
        }, 1000); 
    }, 1000); // Espera 1 segundo antes de sumir
});


/* ----------------------------------------------------------------
   LÓGICA DO CARROSSEL DE PLANOS
---------------------------------------------------------------- */
const track = document.getElementById('plansTrack');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');

// Ao clicar na direita
nextBtn.addEventListener('click', () => {
    // Pega a largura do card + o espaço (gap)
    const cardWidth = track.querySelector('.plan-card').offsetWidth + 20; 
    track.scrollLeft += cardWidth;
});

// Ao clicar na esquerda
prevBtn.addEventListener('click', () => {
    const cardWidth = track.querySelector('.plan-card').offsetWidth + 20;
    track.scrollLeft -= cardWidth;
});

/* ----------------------------------------------------------------
   LÓGICA DO FAQ (ACORDEÃO)
---------------------------------------------------------------- */
const faqItems = document.querySelectorAll('.faq-item');

faqItems.forEach(item => {
    const question = item.querySelector('.faq-question');
    
    question.addEventListener('click', () => {
        // 1. Fecha todos os outros (opcional - se quiser que fique só um aberto por vez)
        faqItems.forEach(otherItem => {
            if (otherItem !== item) {
                otherItem.classList.remove('active');
            }
        });

        // 2. Alterna o atual (Abre ou Fecha)
        item.classList.toggle('active');
    });
});