document.addEventListener('DOMContentLoaded', function() {
    console.log('🎉 Iniciando confetti');
    
    const canvas = document.getElementById('confetti-canvas');
    if (!canvas) return; // Salir si no hay canvas
    
    const ctx = canvas.getContext('2d');
    
    function resizeCanvas() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    const confetti = [];
    const colors = ['#FF7A00', '#FFB347', '#FFA500', '#28a745', '#20c997', '#007cba', '#dc3545', '#ffc107'];

    function createConfettiPiece() {
        return {
            x: Math.random() * canvas.width,
            y: -10,
            width: Math.random() * 8 + 4,
            height: Math.random() * 8 + 4,
            color: colors[Math.floor(Math.random() * colors.length)],
            speed: Math.random() * 3 + 2,
            rotation: Math.random() * 360,
            rotationSpeed: Math.random() * 6 - 3,
            gravity: 0.1,
            wind: Math.random() * 2 - 1
        };
    }

    function initConfetti() {
        for (let i = 0; i < 60; i++) {
            confetti.push(createConfettiPiece());
        }
    }

    function updateConfetti() {
        for (let i = confetti.length - 1; i >= 0; i--) {
            const piece = confetti[i];
            piece.y += piece.speed;
            piece.x += piece.wind;
            piece.speed += piece.gravity;
            piece.rotation += piece.rotationSpeed;
            
            if (piece.y > canvas.height + 10) {
                confetti.splice(i, 1);
            }
        }
    }

    function drawConfetti() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        confetti.forEach(piece => {
            ctx.save();
            ctx.translate(piece.x + piece.width / 2, piece.y + piece.height / 2);
            ctx.rotate((piece.rotation * Math.PI) / 180);
            ctx.fillStyle = piece.color;
            ctx.fillRect(-piece.width / 2, -piece.height / 2, piece.width, piece.height);
            ctx.restore();
        });
    }

    function animate() {
        updateConfetti();
        drawConfetti();
        requestAnimationFrame(animate);
    }

    initConfetti();
    animate();

    setTimeout(() => {
        for (let i = 0; i < 40; i++) {
            confetti.push(createConfettiPiece());
        }
    }, 800);

    setTimeout(() => {
        canvas.style.display = 'none';
    }, 8000);
});