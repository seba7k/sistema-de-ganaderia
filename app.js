/* ============================================================
   Gestión ganadera — comportamiento de la interfaz

   Sin librerías. Todo lo que hay acá mejora una página que ya
   funciona sin JavaScript: si el archivo no carga, se sigue
   pudiendo navegar, filtrar por el formulario y leer las tablas.
   ============================================================ */

(function () {
  'use strict';

  /* --------------------------------------------------------
     Menú de la barra superior (escritorio)

     Los desplegables abren con clic y no al pasar el mouse: con
     hover se abren solos camino a otra cosa, que es lo que marea.
     -------------------------------------------------------- */
  function menuEscritorio() {
    const cerrarTodo = (excepto) => {
      document.querySelectorAll('[data-desplegable].abierto, [data-usuario].abierto')
        .forEach((el) => { if (el !== excepto) el.classList.remove('abierto'); });
    };

    document.querySelectorAll('[data-desplegable] > .enlace, [data-usuario] > .boton-usuario')
      .forEach((disparador) => {
        disparador.addEventListener('click', (evento) => {
          evento.stopPropagation();
          const contenedor = disparador.parentElement;
          const estaba = contenedor.classList.contains('abierto');
          cerrarTodo(contenedor);
          contenedor.classList.toggle('abierto', !estaba);
        });
      });

    document.addEventListener('click', () => cerrarTodo(null));
    document.addEventListener('keydown', (evento) => {
      if (evento.key === 'Escape') cerrarTodo(null);
    });
  }

  /* --------------------------------------------------------
     Menú lateral deslizante (teléfono)
     -------------------------------------------------------- */
  function cajonMovil() {
    const cajon = document.querySelector('[data-cajon]');
    const velo = document.querySelector('[data-velo]');
    if (!cajon || !velo) return;

    const abridores = document.querySelectorAll('[data-abrir-cajon]');
    const cerradores = document.querySelectorAll('[data-cerrar-cajon]');

    // En escritorio el menú es una barra lateral fija y siempre visible; solo
    // en pantallas angostas pasa a ser un cajón que se esconde.
    const esCajon = window.matchMedia('(max-width: 1079px)');

    const ocultarSiCorresponde = () => {
      const oculto = esCajon.matches && !cajon.classList.contains('abierto');
      cajon.toggleAttribute('inert', oculto);
      if (oculto) cajon.setAttribute('aria-hidden', 'true');
      else cajon.removeAttribute('aria-hidden');
    };

    const abrir = () => {
      cajon.classList.add('abierto');
      velo.classList.add('visible');
      document.body.classList.add('sin-scroll');
      ocultarSiCorresponde();
      const primero = cajon.querySelector('.opcion.activo, a, button');
      if (primero) primero.focus({ preventScroll: true });
    };

    const cerrar = () => {
      cajon.classList.remove('abierto');
      velo.classList.remove('visible');
      document.body.classList.remove('sin-scroll');
      ocultarSiCorresponde();
    };

    ocultarSiCorresponde();
    esCajon.addEventListener('change', () => {
      if (!esCajon.matches && cajon.classList.contains('abierto')) cerrar();
      ocultarSiCorresponde();
    });

    // El ítem activo queda a la vista aunque la lista sea más alta que la pantalla.
    const activo = cajon.querySelector('.cajon-nav .opcion.activo');
    if (activo && !esCajon.matches) activo.scrollIntoView({ block: 'nearest' });

    abridores.forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); abrir(); }));
    cerradores.forEach((b) => b.addEventListener('click', cerrar));
    velo.addEventListener('click', cerrar);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && cajon.classList.contains('abierto')) cerrar();
    });
  }

  /* --------------------------------------------------------
     Tablas: rótulo en cada celda para la vista de teléfono

     El CSS convierte cada fila en una ficha y muestra el rótulo
     con `content: attr(data-etiqueta)`. En vez de repetir el
     rótulo en el HTML de las ocho páginas, se copia acá desde el
     encabezado de la propia tabla.
     -------------------------------------------------------- */
  function rotularCeldas(tabla) {
    const encabezados = [...tabla.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    if (encabezados.length === 0) return;

    tabla.classList.add('fichas-en-movil');
    tabla.querySelectorAll('tbody tr').forEach((fila) => {
      [...fila.children].forEach((celda, i) => {
        if (!celda.hasAttribute('data-etiqueta') && encabezados[i]) {
          celda.setAttribute('data-etiqueta', encabezados[i]);
        }
      });
    });
  }

  /* --------------------------------------------------------
     Ordenar una tabla por la columna que se toque

     Los números vienen formateados a la chilena ($71.476, 1,33,
     37%), así que hay que interpretarlos antes de comparar: como
     texto, "1.234" quedaría antes que "987".
     -------------------------------------------------------- */
  function comoNumero(texto) {
    const limpio = texto.replace(/[^\d,.-]/g, '');
    if (limpio === '' || limpio === '-' || limpio === '—') return null;

    // Con coma decimal: los puntos son separadores de miles.
    const normalizado = limpio.includes(',')
      ? limpio.replace(/\./g, '').replace(',', '.')
      : limpio;

    const valor = parseFloat(normalizado);
    return Number.isNaN(valor) ? null : valor;
  }

  function ordenable(tabla) {
    const filas = [...tabla.querySelectorAll('tbody tr')];
    if (filas.length < 3) return;

    const cabeceras = [...tabla.querySelectorAll('thead th')];
    cabeceras.forEach((th, columna) => {
      th.classList.add('ordenable');
      th.setAttribute('title', 'Ordenar por ' + th.textContent.trim().toLowerCase());

      th.addEventListener('click', () => {
        const cuerpo = tabla.querySelector('tbody');
        const ascendente = !th.classList.contains('asc');

        cabeceras.forEach((otro) => otro.classList.remove('asc', 'desc'));
        th.classList.add(ascendente ? 'asc' : 'desc');

        const visibles = [...cuerpo.querySelectorAll('tr')]
          .filter((fila) => !fila.querySelector('td.vacio'));

        visibles.sort((a, b) => {
          const textoA = (a.children[columna]?.textContent || '').trim();
          const textoB = (b.children[columna]?.textContent || '').trim();
          const numA = comoNumero(textoA);
          const numB = comoNumero(textoB);

          const comparacion = (numA !== null && numB !== null)
            ? numA - numB
            : textoA.localeCompare(textoB, 'es', { sensitivity: 'base', numeric: true });

          return ascendente ? comparacion : -comparacion;
        });

        visibles.forEach((fila) => cuerpo.appendChild(fila));
      });
    });
  }

  /* --------------------------------------------------------
     Buscador instantáneo dentro de una tabla larga

     Filtra lo que ya está en pantalla, sin ir al servidor. El
     buscador del formulario sigue siendo el que consulta la base
     completa; este sirve para encontrar algo en la lista cargada.
     -------------------------------------------------------- */
  function buscadorInstantaneo(tabla, bloque) {
    const filas = [...tabla.querySelectorAll('tbody tr')].filter((f) => !f.querySelector('td.vacio'));
    if (filas.length < 8) return;

    const barra = document.createElement('div');
    barra.className = 'buscador-tabla';

    const campo = document.createElement('input');
    campo.type = 'search';
    campo.placeholder = 'Filtrar lo que se ve en esta tabla…';
    campo.setAttribute('aria-label', 'Filtrar filas de la tabla');

    const cuenta = document.createElement('span');
    cuenta.className = 'cuenta';
    cuenta.textContent = filas.length + ' filas';

    barra.append(campo, cuenta);

    const contenedor = tabla.closest('.tabla-scroll');
    if (!contenedor || !contenedor.parentNode) return;
    contenedor.parentNode.insertBefore(barra, contenedor);

    let sinResultados = null;

    campo.addEventListener('input', () => {
      const texto = campo.value.trim().toLowerCase();
      let visibles = 0;

      filas.forEach((fila) => {
        const coincide = texto === '' || fila.textContent.toLowerCase().includes(texto);
        fila.hidden = !coincide;
        if (coincide) visibles += 1;
      });

      cuenta.textContent = texto === ''
        ? filas.length + ' filas'
        : visibles + ' de ' + filas.length;

      if (visibles === 0 && !sinResultados) {
        sinResultados = document.createElement('p');
        sinResultados.className = 'vacio';
        sinResultados.style.margin = '0';
        sinResultados.textContent = 'Nada coincide con «' + campo.value.trim() + '» en esta lista.';
        contenedor.parentNode.insertBefore(sinResultados, contenedor.nextSibling);
      } else if (visibles > 0 && sinResultados) {
        sinResultados.remove();
        sinResultados = null;
      } else if (sinResultados) {
        sinResultados.textContent = 'Nada coincide con «' + campo.value.trim() + '» en esta lista.';
      }
    });
  }

  /* --------------------------------------------------------
     Filas que llevan a otra pantalla

     El enlace real vive en la celda (así funciona sin JavaScript
     y se puede abrir en otra pestaña); acá se agranda el área
     sensible a toda la fila.
     -------------------------------------------------------- */
  function filasEnlazadas() {
    document.querySelectorAll('tr[data-ir]').forEach((fila) => {
      fila.classList.add('fila-enlace');
      fila.addEventListener('click', (evento) => {
        if (evento.target.closest('a, button, input, select, summary')) return;
        window.location.href = fila.getAttribute('data-ir');
      });
    });
  }

  /* --------------------------------------------------------
     Cierre de sesión por inactividad

     El servidor cierra la sesión tras N minutos sin peticiones.
     Acá se avisa cinco minutos antes, y al cumplirse se lleva a
     la pantalla de ingreso: si no, quien vuelve al computador
     llenaría un formulario que se pierde al enviarlo.

     Tocar o escribir en la página cuenta como actividad: se le
     avisa al servidor como mucho una vez cada cinco minutos.
     -------------------------------------------------------- */
  function cierrePorInactividad() {
    const minutos = parseInt(document.body.dataset.inactividad || '0', 10);
    const salir = document.body.dataset.salir;
    const mantener = document.body.dataset.mantener;
    if (!minutos || !salir || !mantener) return;

    const DURACION = minutos * 60 * 1000;
    const AVISO_ANTES = Math.min(5 * 60 * 1000, DURACION / 2);
    const PAUSA_ENTRE_AVISOS = 5 * 60 * 1000;

    let limite = Date.now() + DURACION;
    let ultimoAviso = Date.now();
    let aviso = null;

    const renovar = () => {
      ultimoAviso = Date.now();
      // Va por POST y con el token de la página: el servidor ya no renueva por GET.
      const cuerpo = new FormData();
      cuerpo.append('csrf', document.body.dataset.csrf || '');
      return fetch(mantener, {
        method: 'POST',
        body: cuerpo,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      })
        .then((respuesta) => respuesta.json())
        .then((datos) => {
          if (!datos.activa) {
            window.location.href = salir;
            return;
          }
          limite = Date.now() + DURACION;
          if (aviso) {
            aviso.remove();
            aviso = null;
          }
        })
        .catch(() => { /* sin conexión: se reintenta con la próxima actividad */ });
    };

    ['click', 'keydown', 'touchstart'].forEach((evento) => {
      document.addEventListener(evento, () => {
        if (Date.now() - ultimoAviso > PAUSA_ENTRE_AVISOS) renovar();
      }, { passive: true });
    });

    const mostrarAviso = () => {
      aviso = document.createElement('div');
      aviso.className = 'aviso-sesion';
      aviso.setAttribute('role', 'alertdialog');
      aviso.setAttribute('aria-live', 'assertive');

      const texto = document.createElement('div');
      const titulo = document.createElement('strong');
      titulo.textContent = 'Tu sesión está por cerrarse';
      const cuenta = document.createElement('span');
      cuenta.setAttribute('data-cuenta', '');
      texto.append(titulo, cuenta);

      const boton = document.createElement('button');
      boton.type = 'button';
      boton.className = 'boton';
      boton.textContent = 'Seguir conectado';
      boton.addEventListener('click', (evento) => {
        evento.stopPropagation();
        renovar();
      });

      aviso.append(texto, boton);
      document.body.appendChild(aviso);
    };

    setInterval(() => {
      const resta = limite - Date.now();
      if (resta <= 0) {
        window.location.href = salir;
        return;
      }
      if (resta <= AVISO_ANTES) {
        if (!aviso) mostrarAviso();
        const segundos = Math.ceil(resta / 1000);
        aviso.querySelector('[data-cuenta]').textContent =
          'Se cierra en ' + Math.floor(segundos / 60) + ':' + String(segundos % 60).padStart(2, '0')
          + ' por ' + minutos + ' minutos sin actividad.';
      }
    }, 1000);
  }

  /* --------------------------------------------------------
     Anular un registro: se pide el motivo antes de enviar

     Queda en la bitácora junto con quién lo anuló. Sin motivo
     no se envía: el servidor lo rechazaría igual.
     -------------------------------------------------------- */
  function anularConMotivo() {
    document.querySelectorAll('form.anular').forEach((formulario) => {
      formulario.addEventListener('submit', (evento) => {
        const motivo = window.prompt('¿Por qué se anula este registro? Queda guardado con tu nombre.');
        if (motivo === null || motivo.trim() === '') {
          evento.preventDefault();
          return;
        }
        formulario.querySelector('[name="motivo"]').value = motivo.trim();
      });
    });
  }

  /* --------------------------------------------------------
     Ingreso de demostración: tocar una cuenta completa el formulario
     -------------------------------------------------------- */
  function cuentasDemo() {
    document.querySelectorAll('[data-cuenta]').forEach((boton) => {
      boton.addEventListener('click', () => {
        const correo = document.getElementById('email');
        const clave = document.getElementById('password');
        if (!correo || !clave) return;
        correo.value = boton.getAttribute('data-cuenta');
        clave.value = 'demo1234';
        const entrar = clave.form && clave.form.querySelector('button[type=submit]');
        if (entrar) entrar.focus();
      });
    });
  }

  /* --------------------------------------------------------
     Arranque
     -------------------------------------------------------- */
  function iniciar() {
    cuentasDemo();
    anularConMotivo();
    menuEscritorio();
    cajonMovil();
    filasEnlazadas();
    cierrePorInactividad();

    document.querySelectorAll('table').forEach((tabla) => {
      rotularCeldas(tabla);
      if (!tabla.hasAttribute('data-sin-orden')) ordenable(tabla);
      if (!tabla.hasAttribute('data-sin-buscador')) buscadorInstantaneo(tabla);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
