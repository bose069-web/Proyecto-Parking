/* Tarraco Parkings · comportamiento del cliente.
   Sin dependencias: fetch nativo y mejoras progresivas. */
(function () {
    'use strict';

    // Formatea la matrícula mientras se escribe: mayúsculas, sin espacios ni guiones.
    document.querySelectorAll('[data-plate]').forEach(function (input) {
        input.addEventListener('blur', function () {
            input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    });

    // Confirmación para acciones destructivas o irreversibles.
    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Un envío, un POST. Sin esto, dos clics seguidos creaban dos reservas
    // porque el formulario se manda dos veces.
    document.querySelectorAll('form[method="post"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var botones = form.querySelectorAll('button[type="submit"]');
            botones.forEach(function (boton) {
                boton.setAttribute('aria-busy', 'true');
                boton.dataset.textoOriginal = boton.textContent;
                boton.textContent = 'Procesando…';
            });
            // Si el navegador vuelve atrás desde la página siguiente, el botón
            // no puede quedarse bloqueado para siempre.
            window.setTimeout(function () {
                botones.forEach(function (boton) {
                    boton.removeAttribute('aria-busy');
                    if (boton.dataset.textoOriginal) {
                        boton.textContent = boton.dataset.textoOriginal;
                    }
                });
            }, 8000);
        });
    });

    // Cálculo del precio en tiempo real.
    var quoteForm = document.querySelector('[data-quote-form]');
    if (!quoteForm) {
        return;
    }

    var output = document.getElementById('quote');
    var timer = null;

    // textContent y no innerHTML: el mensaje de error puede venir del servidor
    // y no debe interpretarse como HTML.
    function addQuoteLine(etiqueta, valor, esTotal) {
        var fila = document.createElement('div');
        fila.className = 'quote-line' + (esTotal ? ' total' : '');

        var izq = document.createElement('span');
        izq.textContent = etiqueta;

        var der = document.createElement('span');
        der.textContent = valor;

        fila.appendChild(izq);
        fila.appendChild(der);
        output.appendChild(fila);
    }

    function renderQuote(data) {
        if (!output) { return; }
        // textContent y no innerHTML: el mensaje de error lo escribe el servidor.
        output.textContent = '';

        if (!data.ok) {
            var aviso = document.createElement('p');
            aviso.className = 'hint';
            aviso.textContent = data.error || 'No se puede calcular el precio.';
            output.appendChild(aviso);
            return;
        }

        addQuoteLine('Duración', data.horas + ' h', false);
        addQuoteLine('Precio por hora', data.precio_hora, false);
        addQuoteLine('Importe base', data.base, false);
        addQuoteLine('Gestión (' + data.gestion_pct + ')', data.gestion, false);
        addQuoteLine('Total', data.total, true);
    }

    function refreshQuote() {
        var params = new URLSearchParams({
            id_parking: quoteForm.querySelector('[name="id_parking"]').value,
            id_vehiculo: quoteForm.querySelector('[name="id_vehiculo"]').value,
            entrada: quoteForm.querySelector('[name="entrada"]').value,
            salida: quoteForm.querySelector('[name="salida"]').value
        });

        if (!params.get('id_parking') || !params.get('id_vehiculo') ||
            !params.get('entrada') || !params.get('salida')) {
            return;
        }

        fetch('/quote.php?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(renderQuote)
            .catch(function () {
                if (output) {
                    var fallo = document.createElement('p');
                    fallo.className = 'hint';
                    fallo.textContent = 'No se ha podido calcular el precio.';
                    output.textContent = '';
                    output.appendChild(fallo);
                }
            });
    }

    quoteForm.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(refreshQuote, 350);
    });

    refreshQuote();
}());
