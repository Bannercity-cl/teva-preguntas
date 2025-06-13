document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('vote-form');
    const options = document.querySelectorAll('.survey-option');
    const voteStatus = document.getElementById('vote-status');
    const surveyOptions = document.querySelector('.survey-options');
    
    // ✅ AGREGAR: Variables para prevenir múltiples envíos
    let isSubmitting = false;
    let hasSubmittedSuccessfully = false;
    
    // Obtener datos del formulario
    if (!form) {
        console.error('Formulario de encuesta no encontrado');
        return;
    }
    
    const preselectedOption = parseInt(form.dataset.preselected || 0);
    const attemptCount = parseInt(form.dataset.attempts || 0);
    const isFirstVisit = (attemptCount === 0 && preselectedOption > 0);
    const ajaxUrl = form.dataset.ajaxUrl;
    
    console.log('Survey loaded - Current attempts:', attemptCount, 'Preselected:', preselectedOption, 'Is truly first visit:', isFirstVisit);
    
    // AUTO-VOTE solo en primera visita REAL (0 intentos previos)
    if (isFirstVisit) {
        console.log('🎯 Auto-vote activado para PRIMERA visita real (0 intentos)');
        
        let autoVoteExecuted = false;
        
        const autoVoteTimeout = setTimeout(() => {
            if (!autoVoteExecuted && !isSubmitting && !hasSubmittedSuccessfully) {
                console.log('⏰ Ejecutando auto-vote');
                autoVoteExecuted = true;
                submitVote(preselectedOption, true);
            }
        }, 1200); // ✅ AUMENTAR tiempo a 1.2 segundos para evitar lag
        
        // Permitir cancelar el auto-vote
        options.forEach(option => {
            option.addEventListener('click', function(e) {
                if (!autoVoteExecuted && !surveyOptions.classList.contains('disabled')) {
                    e.preventDefault();
                    console.log('🛑 Auto-vote cancelado por usuario');
                    clearTimeout(autoVoteTimeout);
                    autoVoteExecuted = true;
                    setupManualVoting();
                }
            });
        });
    } else {
        console.log('👤 Votación manual desde el inicio (intentos previos: ' + attemptCount + ')');
        setupManualVoting();
    }
    
    function setupManualVoting() {
        options.forEach(option => {
            // Remover listeners anteriores
            option.replaceWith(option.cloneNode(true));
        });
        
        // Re-obtener elementos después del clonado
        const newOptions = document.querySelectorAll('.survey-option');
        
        newOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                // ✅ PREVENIR múltiples clics
                if (surveyOptions.classList.contains('disabled') || isSubmitting || hasSubmittedSuccessfully) {
                    e.preventDefault();
                    console.log('🚫 Click ignorado - ya está procesando o completado');
                    return;
                }
                
                // Seleccionar la opción
                newOptions.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');
                this.querySelector('input').checked = true;
                
                // ✅ REDUCIR tiempo de delay para manual
                setTimeout(() => {
                    if (!isSubmitting && !hasSubmittedSuccessfully) {
                        submitVote(this.querySelector('input').value, false);
                    }
                }, 100); // Reducir de 200ms a 100ms para respuesta más rápida
            });
        });
    }
    
    function submitVote(selectedOption, isAutoVote = false) {
        // ✅ PREVENIR múltiples envíos
        if (isSubmitting || hasSubmittedSuccessfully) {
            console.log('🚫 Submit bloqueado - ya está procesando o completado');
            return;
        }
        
        isSubmitting = true; // ✅ Marcar como enviando
        console.log('Enviando voto para opción:', selectedOption, 'Auto-vote:', isAutoVote);
        
        // Deshabilitar opciones y mostrar estado
        surveyOptions.classList.add('disabled');
        voteStatus.style.display = 'block';
        voteStatus.classList.remove('success', 'error');
        
        const statusMessage = isAutoVote ? 'Procesando selección automática...' : 'Enviando respuesta...';
        
        voteStatus.innerHTML = `
            <div class="status-content">
                <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="m12 1 0 6m0 6 0 6"></path>
                    <path d="m12 1 0 6m0 6 0 6" transform="rotate(60 12 12)"></path>
                    <path d="m12 1 0 6m0 6 0 6" transform="rotate(120 12 12)"></path>
                </svg>
                <span class="status-text">${statusMessage}</span>
            </div>
        `;
        
        const formData = new FormData();
        formData.append('action', 'submit_vote');
        formData.append('survey_id', form.dataset.survey);
        formData.append('email', form.dataset.email);
        formData.append('option', selectedOption);
        formData.append('nonce', form.dataset.nonce);
        
        fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.text())
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed response:', data);
                
                if (data.success) {
                    hasSubmittedSuccessfully = true; // ✅ Marcar como completado exitosamente
                    
                    voteStatus.classList.add('success');
                    voteStatus.classList.remove('error');
                    
                    voteStatus.innerHTML = `
                        <div class="status-content">
                            <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20,6 9,17 4,12"></polyline>
                            </svg>
                            <span class="status-text">¡Respuesta enviada!</span>
                        </div>
                    `;
                    
                    const resultsUrl = form.dataset.resultsUrl;
                    
                    setTimeout(() => {
                        window.location.href = resultsUrl;
                    }, 1800); // ✅ AUMENTAR tiempo para mostrar mensaje de éxito
                } else {
                    handleVoteError(data.data || 'Error desconocido');
                }
            } catch (e) {
                console.error('Parse error:', e);
                handleVoteError('Respuesta inválida del servidor');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            handleVoteError('Error de conexión: ' + error.message);
        });
    }
    
    function handleVoteError(errorMessage) {
        isSubmitting = false; // ✅ Liberar flag en caso de error
        
        voteStatus.classList.add('error');
        voteStatus.classList.remove('success');
        
        voteStatus.innerHTML = `
            <div class="status-content">
                <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
                <span class="status-text">Error: ${errorMessage}</span>
            </div>
        `;
        
        // ✅ SOLO rehabilitar si NO es el error de "ya completado"
        if (!errorMessage.includes('completado') && !errorMessage.includes('exitosamente')) {
            setTimeout(() => {
                surveyOptions.classList.remove('disabled');
                voteStatus.style.display = 'none';
                voteStatus.classList.remove('success', 'error');
                setupManualVoting();
            }, 3000);
        } else {
            // Si ya completó, redirigir a resultados después de mostrar mensaje
            setTimeout(() => {
                const resultsUrl = form.dataset.resultsUrl;
                window.location.href = resultsUrl;
            }, 2000);
        }
    }
    
    // Efecto hover para las barras del gráfico
    const barSegments = document.querySelectorAll('.bar-segment');
    barSegments.forEach(segment => {
        segment.addEventListener('mouseenter', function() {
            this.style.transform = 'scaleY(1.2)';
            this.style.transition = 'transform 0.2s ease';
        });
        
        segment.addEventListener('mouseleave', function() {
            this.style.transform = 'scaleY(1)';
        });
    });
});