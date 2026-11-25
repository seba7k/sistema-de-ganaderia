/* ============================================================
   Gestión ganadera — App de terreno (offline-first)

   Todo lo que se registra se guarda primero en el teléfono
   (IndexedDB) y se envía al servidor cuando hay señal. Cada
   registro lleva un identificador único: si el envío se corta a
   mitad de camino, el reintento no lo duplica.

   Sin librerías: el teléfono de la manga no siempre tiene buena
   señal para descargarlas.
   ============================================================ */

(() => {
  'use strict';

  // index.php?r=/api/… funciona en cualquier hosting, tenga o no mod_rewrite.
  const API = new URL('../index.php', window.location.href).href;

  const app = document.getElementById('app');
  const avisoEl = document.getElementById('aviso');
  const modalEl = document.getElementById('modal');

  /* Íconos de trazo, dibujados acá: sin emojis, que cambian de aspecto en cada teléfono. */
  const svg = (trazo) => '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + trazo + '</svg>';
  const ICONOS = {
    marca: '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M12 2 22 12 12 22 2 12z" fill="currentColor"/></svg>',
    volver: svg('<path d="m15 18-6-6 6-6"/>'),
    ir: svg('<path d="m9 18 6-6-6-6"/>'),
    sincronizar: svg('<path d="M21 12a9 9 0 0 1-15.5 6.3L3 16"/><path d="M3 12a9 9 0 0 1 15.5-6.3L21 8"/><path d="M21 3v5h-5"/><path d="M3 21v-5h5"/>'),
    pesaje: svg('<path d="M12 3v18"/><path d="M7 21h10"/><path d="M5 7h14"/><path d="m5 7-3 7a3.5 3.5 0 0 0 6 0z"/><path d="m19 7-3 7a3.5 3.5 0 0 0 6 0z"/>'),
    parto: svg('<path d="M19.5 12.6 12 20l-7.5-7.4A5 5 0 1 1 12 6.1a5 5 0 1 1 7.5 6.5z"/>'),
    tratamiento: svg('<path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3a2.4 2.4 0 0 1-3.4 0l-.6-.6a2.4 2.4 0 0 1 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/>'),
    alimentacion: svg('<path d="M12 22V10"/><path d="M12 10c0-3 2-5.5 5-6.5 0 3-2 5.5-5 6.5z"/><path d="M12 10C12 7 10 4.5 7 3.5c0 3 2 5.5 5 6.5z"/><path d="M12 16c0-3 2-5.5 5-6.5 0 3-2 5.5-5 6.5z"/><path d="M12 16c0-3-2-5.5-5-6.5 0 3 2 5.5 5 6.5z"/>'),
    traslado: svg('<path d="M4 7h15"/><path d="m15 3 4 4-4 4"/><path d="M20 17H5"/><path d="m9 13-4 4 4 4"/>'),
    secado: svg('<path d="M12 3s5.5 5.4 5.5 9a5.5 5.5 0 0 1-11 0C6.5 8.4 12 3 12 3z"/><path d="m4 4 16 16"/>'),
    nota: svg('<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L14 13l-4 1 1-4z"/>'),
    animal: svg('<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>'),
    validar: svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>'),
  };

  const OPERACIONES = {
    PESADA:      { icono: ICONOS.pesaje, titulo: 'Pesaje', detalle: 'Peso e hito de crecimiento' },
    PARTO:       { icono: ICONOS.parto, titulo: 'Parto', detalle: 'Exitoso, aborto o ternero débil' },
    TRATAMIENTO: { icono: ICONOS.tratamiento, titulo: 'Tratamiento', detalle: 'Producto, dosis y carencia' },
    ENTREGA:     { icono: ICONOS.alimentacion, titulo: 'Alimentación', detalle: 'Ración a un corral o a un animal' },
    TRASLADO:    { icono: ICONOS.traslado, titulo: 'Traslado', detalle: 'Mover animales de corral' },
    SECADO:      { icono: ICONOS.secado, titulo: 'Secado', detalle: 'Fin de la lactancia, en lechería' },
    NOTA:        { icono: ICONOS.nota, titulo: 'Nota', detalle: 'Anotar lo que le pasó al animal' },
    ANIMAL:      { icono: ICONOS.animal, titulo: 'Alta de animal', detalle: 'Uno comprado o que faltaba cargar' },
    VALIDACION:  { icono: ICONOS.validar, titulo: 'Validar tratamientos', detalle: 'Firma del médico veterinario autorizado', enCola: 'Validación MVA', guardado: 'Validación guardada' },
  };

  const SEXOS = [['HEMBRA', '♀ Hembra'], ['MACHO', '♂ Macho'], ['MACHO_CASTRADO', 'Castrado']];
  const CATEGORIAS = [['TERNERA', 'Ternera'], ['TERNERO', 'Ternero'], ['VAQUILLA', 'Vaquilla'],
    ['NOVILLO', 'Novillo'], ['VACA', 'Vaca'], ['TORO', 'Toro']];

  /* Lo que más se anota en terreno: se toca y se agrega al texto. */
  const FRASES = ['Mastitis', 'Cojera', 'Diarrea', 'Parto difícil', 'Decaída', 'Herida'];
  const HITOS = [['CONTROL', 'Control'], ['NACIMIENTO', 'Nacimiento'], ['DESTETE', 'Destete'], ['ENCASTE', '1er encaste'], ['SALIDA', 'Salida']];
  const TIPOS_TRATAMIENTO = [['CURATIVO', 'Curativo'], ['PREVENTIVO', 'Preventivo'], ['VACUNACION', 'Vacuna'], ['DESPARASITACION', 'Antiparasitario'], ['VITAMINICO', 'Vitaminas']];
  const VIAS = [['INTRAMUSCULAR', 'Intramuscular'], ['SUBCUTANEA', 'Subcutánea'], ['INTRAVENOSA', 'Endovenosa'], ['ORAL', 'Oral'], ['TOPICA', 'Tópica']];
  const UNIDADES = { MILILITRO: 'ml', DOSIS: 'dosis', LITRO: 'l', KILOGRAMO: 'kg', UNIDAD: 'u' };

  /* ---------- Utilidades ---------- */

  const esc = (valor) => String(valor ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n, decimales = 0) => Number(n).toLocaleString('es-CL', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });

  /** Número escrito a mano: acepta "452,5" y "452.5". */
  const numeroDe = (texto) => {
    let t = String(texto ?? '').trim().replace(/\s/g, '');
    if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
    const valor = Number(t);
    return t !== '' && Number.isFinite(valor) ? valor : NaN;
  };

  // «sv-SE» da el formato AAAA-MM-DD en la hora local del teléfono.
  const hoy = () => new Date().toLocaleDateString('sv-SE');
  const ahora = () => new Date().toLocaleString('sv-SE').replace('T', ' ');
  const sumarDias = (fecha, dias) => {
    const d = new Date(fecha + 'T12:00:00');
    d.setDate(d.getDate() + dias);
    return d.toLocaleDateString('sv-SE');
  };
  const fechaCorta = (f) => (f ? f.slice(0, 10).split('-').reverse().join('/') : '—');
  const fechaHora = (f) => (f ? fechaCorta(f) + ' ' + f.slice(11, 16) : '—');
  const legible = (v) => (v ? v.charAt(0) + v.slice(1).toLowerCase().replace(/_/g, ' ') : '');
  const etiqueta = (lista, valor) => (lista.find(([v]) => v === valor) || [valor, legible(valor)])[1];

  const nuevoUuid = () => (crypto.randomUUID
    ? crypto.randomUUID()
    : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
      (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16)));

  /* ---------- Almacenamiento en el teléfono (IndexedDB) ---------- */

  const BD = (() => {
    let apertura = null;
    const abrir = () => apertura || (apertura = new Promise((ok, falla) => {
      const pedido = indexedDB.open('ganaderia-terreno', 1);
      pedido.onupgradeneeded = () => {
        const bd = pedido.result;
        if (!bd.objectStoreNames.contains('datos')) bd.createObjectStore('datos');
        if (!bd.objectStoreNames.contains('cola')) bd.createObjectStore('cola', { keyPath: 'uuid' });
      };
      pedido.onsuccess = () => ok(pedido.result);
      pedido.onerror = () => falla(pedido.error);
    }));

    const transaccion = async (almacen, modo, trabajo) => {
      const bd = await abrir();
      return new Promise((ok, falla) => {
        const t = bd.transaction(almacen, modo);
        const pedido = trabajo(t.objectStore(almacen));
        t.oncomplete = () => ok(pedido ? pedido.result : undefined);
        t.onerror = () => falla(t.error);
        t.onabort = () => falla(t.error);
      });
    };

    return {
      leer: (clave) => transaccion('datos', 'readonly', (s) => s.get(clave)),
      escribir: (clave, valor) => transaccion('datos', 'readwrite', (s) => s.put(valor, clave)),
      borrar: (clave) => transaccion('datos', 'readwrite', (s) => s.delete(clave)),
      cola: () => transaccion('cola', 'readonly', (s) => s.getAll()),
      encolar: (op) => transaccion('cola', 'readwrite', (s) => s.put(op)),
      quitar: (uuid) => transaccion('cola', 'readwrite', (s) => s.delete(uuid)),
    };
  })();

  /* ---------- Estado ---------- */

  const estado = {
    sesion: null,     // { token, expira_en, usuario, operaciones }
    catalogo: null,   // animales, corrales, raciones y productos
    cola: [],         // registros por enviar o rechazados
    sincronizando: false,
    pantalla: 'cargando',
    ctx: {},          // datos de la pantalla abierta
  };

  const animalPorId = (id) => (estado.catalogo?.animales || []).find((a) => a.id === id);
  const corralPorId = (id) => (estado.catalogo?.corrales || []).find((c) => c.id === id);
  const nombreAnimal = (a) => (a ? a.diio || a.interno || a.eid || '#' + a.id : '—');
  const nombreCorral = (id) => corralPorId(id)?.nombre || 'sin corral';
  const esMia = (op) => !!estado.sesion && op.usuario_id === estado.sesion.usuario.id;
  const porEnviar = () => estado.cola.filter((op) => esMia(op) && op.estado !== 'RECHAZADA');

  /* Validación MVA. Lo que ya está en la cola del teléfono —por enviar o
     rechazado— no se vuelve a ofrecer: si se rechazó, se ve en Envíos, y al
     descartarlo vuelve a aparecer acá. */
  const yaEnCola = (id) => estado.cola.some((op) => esMia(op) && op.tipo === 'VALIDACION' && op.datos.tratamiento_id === id);
  const porValidar = () => (estado.catalogo?.por_validar || []).filter((t) => !yaEnCola(t.id));
  // Aviso local: la decisión final es del servidor, que vuelve a revisar la vigencia al recibir.
  /* Formato del DIIO configurado en el servidor ('/^\d{9}$/'), como RegExp.
     Si no se puede interpretar, se actúa como si no hubiera formato: el
     servidor igual lo revisa al recibir. */
  const formatoDiio = () => {
    const partes = /^\/(.*)\/([a-z]*)$/s.exec(estado.catalogo?.formato_diio || '');
    if (!partes) return null;
    try { return new RegExp(partes[1], partes[2].replace(/[^imsu]/g, '')); } catch (e) { return null; }
  };
  const mvaVigente = () => !!estado.catalogo?.mva && estado.catalogo.mva.vigente_hasta >= hoy();

  /* ---------- Servidor ---------- */

  async function api(ruta, { metodo = 'GET', cuerpo } = {}) {
    const cabeceras = { Accept: 'application/json' };
    if (cuerpo) cabeceras['Content-Type'] = 'application/json';
    if (estado.sesion) {
      cabeceras.Authorization = 'Bearer ' + estado.sesion.token;
      cabeceras['X-Token'] = estado.sesion.token; // por si el hosting descarta Authorization
    }

    let respuesta;
    try {
      respuesta = await fetch(API + '?r=/api/' + ruta, {
        method: metodo,
        headers: cabeceras,
        body: cuerpo ? JSON.stringify(cuerpo) : undefined,
        cache: 'no-store',
      });
    } catch (e) {
      const error = new Error('Sin conexión con el servidor.');
      error.sinRed = true;
      throw error;
    }

    let datos = {};
    try { datos = await respuesta.json(); } catch (e) { /* respuesta vacía o no JSON */ }
    if (!respuesta.ok) {
      const error = new Error(datos.error || 'El servidor respondió ' + respuesta.status + '.');
      error.status = respuesta.status;
      error.datos = datos;
      throw error;
    }
    return datos;
  }

  async function descargarCatalogo() {
    const datos = await api('catalogo');
    estado.catalogo = datos;
    estado.sesion.usuario = datos.usuario || estado.sesion.usuario;
    estado.sesion.operaciones = datos.operaciones || estado.sesion.operaciones;
    await BD.escribir('catalogo', datos);
    await BD.escribir('sesion', estado.sesion);
  }

  /**
   * Envía lo pendiente y baja los datos frescos del campo.
   * Primero se envía y después se descarga: así lo registrado en el teléfono
   * ya está incluido en lo que vuelve.
   */
  async function sincronizar(manual = false) {
    if (estado.sincronizando || !estado.sesion) return;
    if (!navigator.onLine) {
      if (manual) aviso('Sin señal: se envía solo cuando vuelva.', 'error');
      return;
    }

    estado.sincronizando = true;
    refrescarCabecera();
    let enviados = 0;

    try {
      const pendientes = porEnviar().sort((a, b) => a.registrado_en.localeCompare(b.registrado_en));
      for (let i = 0; i < pendientes.length; i += 50) {
        const tanda = pendientes.slice(i, i + 50);
        const respuesta = await api('sincronizar', {
          metodo: 'POST',
          cuerpo: { operaciones: tanda.map(({ uuid, tipo, datos, registrado_en }) => ({ uuid, tipo, datos, registrado_en })) },
        });

        for (const resultado of respuesta.resultados) {
          const op = estado.cola.find((o) => o.uuid === resultado.uuid);
          if (!op) continue;
          if (resultado.estado === 'OK' || resultado.estado === 'DUPLICADA') {
            await BD.quitar(op.uuid);
            estado.cola = estado.cola.filter((o) => o.uuid !== op.uuid);
            enviados += 1;
          } else {
            op.estado = resultado.estado === 'RECHAZADA' ? 'RECHAZADA' : 'ERROR';
            op.mensaje = resultado.mensaje;
            op.requiere_confirmacion = !!resultado.requiere_confirmacion;
            await BD.encolar(op);
          }
        }
      }

      await descargarCatalogo();

      const rechazadas = estado.cola.filter((o) => esMia(o) && o.estado === 'RECHAZADA').length;
      if (rechazadas) aviso(rechazadas + ' registro(s) rechazados: revisalos en Envíos.', 'error');
      else if (enviados) aviso('✔ ' + enviados + ' registro(s) enviados. Datos al día.', 'ok');
      else if (manual) aviso('✔ Datos del campo al día.', 'ok');
    } catch (error) {
      if (error.status === 401) {
        estado.sincronizando = false;
        return sesionVencida(error.message);
      }
      if (manual || !error.sinRed) aviso('No se pudo sincronizar: ' + error.message, 'error');
    } finally {
      estado.sincronizando = false;
      if (estado.sesion) {
        if (['inicio', 'pendientes', 'validar'].includes(estado.pantalla)) dibujar();
        else refrescarCabecera();
      }
    }
  }

  async function sesionVencida(mensaje) {
    estado.sesion = null;
    await BD.borrar('sesion');
    mostrar('ingreso', { error: mensaje || 'La sesión venció. Volvé a ingresar: lo pendiente sigue guardado.' });
  }

  /**
   * Guarda un registro en el teléfono y, si hay señal, lo envía.
   * `alGuardar` actualiza los datos locales (peso, corral, carencia) para que
   * el siguiente registro vea el cambio aunque todavía no se haya enviado.
   */
  async function registrar(tipo, datos, resumen, alGuardar) {
    const op = {
      uuid: nuevoUuid(), tipo, datos, resumen,
      registrado_en: ahora(), estado: 'PENDIENTE', mensaje: null,
      usuario_id: estado.sesion.usuario.id,
    };
    await BD.encolar(op);
    estado.cola.push(op);
    if (alGuardar) alGuardar();
    await BD.escribir('catalogo', estado.catalogo);
    const guardado = OPERACIONES[tipo].guardado || OPERACIONES[tipo].titulo + ' guardado';
    aviso('✔ ' + guardado + (navigator.onLine ? '.' : ' en el teléfono.'), 'ok');
    if (navigator.onLine) setTimeout(() => sincronizar(), 300);
  }

  /* ---------- Reglas locales (las mismas que valida el servidor) ---------- */

  const normalizar = (texto) => String(texto || '').toUpperCase().replace(/\s+/g, '');

  function buscarAnimales(texto, filtro) {
    const q = normalizar(texto);
    if (!q) return [];
    const animales = (estado.catalogo?.animales || []).filter(filtro || (() => true));
    const identificaciones = (a) => [a.diio, a.eid, a.interno].map(normalizar);
    const exactos = animales.filter((a) => identificaciones(a).includes(q));
    if (exactos.length) return exactos;
    return animales.filter((a) => identificaciones(a).some((v) => v && v.includes(q))).slice(0, 8);
  }

  function variacionPeso(animal, peso, fecha) {
    if (!animal.peso || !animal.fecha_peso) return null;
    const dias = Math.round((Date.parse(fecha) - Date.parse(animal.fecha_peso)) / 86400000);
    const diferencia = peso - animal.peso;
    if (dias <= 0) {
      return Math.abs(diferencia) > 15 ? 'Hay otro pesaje de hoy con ' + fmt(Math.abs(diferencia), 1) + ' kg de diferencia.' : null;
    }
    const gdp = diferencia / dias;
    if (diferencia < 0) return 'Perdió ' + fmt(-diferencia, 1) + ' kg en ' + dias + ' día(s) (GDP ' + fmt(gdp, 2) + ' kg/día).';
    const maxima = estado.catalogo.gdp_maxima || 2.5;
    if (gdp > maxima) return 'Ganancia de ' + fmt(gdp, 2) + ' kg/día desde el último pesaje: más de lo posible (' + fmt(maxima, 1) + ').';
    return null;
  }

  /* ---------- Avisos y confirmaciones ---------- */

  let temporizadorAviso = null;
  function aviso(texto, tono = 'ok') {
    avisoEl.textContent = texto;
    avisoEl.className = 'aviso visible ' + tono;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(() => { avisoEl.className = 'aviso'; }, tono === 'error' ? 5000 : 2800);
  }

  function confirmar({ titulo, texto, si, no = 'Cancelar', tono = 'exito', alConfirmar }) {
    modalEl.innerHTML = '<div class="dialogo" role="alertdialog" aria-modal="true" aria-labelledby="modal-titulo">'
      + '<h2 id="modal-titulo">' + esc(titulo) + '</h2><p>' + esc(texto) + '</p>'
      + '<div class="acciones"><button type="button" class="boton" data-modal="no">' + esc(no) + '</button>'
      + '<button type="button" class="boton ' + tono + '" data-modal="si">' + esc(si) + '</button></div></div>';
    modalEl.hidden = false;
    modalEl.querySelector('[data-modal="si"]').focus();
    modalEl.onclick = (evento) => {
      const boton = evento.target.closest('[data-modal]');
      if (!boton && evento.target !== modalEl) return;
      modalEl.hidden = true;
      modalEl.innerHTML = '';
      if (boton && boton.dataset.modal === 'si') alConfirmar();
    };
  }

  /* ---------- Piezas de pantalla ---------- */

  function tituloPantalla() {
    const titulos = {
      identificar: {
        PESADA: 'Pesaje', PARTO: 'Parto', TRATAMIENTO: 'Tratamiento', TRASLADO: 'Traslado',
        ENTREGA: 'Alimentación individual', SECADO: 'Secado', NOTA: 'Nota',
      }[estado.ctx.tipo],
      pesaje: 'Pesaje',
      parto: 'Parto',
      secado: 'Secado',
      nota: 'Nota',
      alta: 'Alta de animal',
      tratamiento: 'Tratamiento',
      'corrales-tratamiento': 'Tratamiento por corral',
      alimentacion: 'Alimentación',
      destino: 'Traslado',
      pendientes: 'Envíos',
      validar: 'Validar tratamientos',
    };
    return titulos[estado.pantalla] || 'Terreno';
  }

  function cabecera() {
    if (!estado.sesion) return '';
    const mias = estado.cola.filter(esMia);
    const rechazadas = mias.filter((o) => o.estado === 'RECHAZADA').length;
    const total = mias.length;
    const enLinea = navigator.onLine;
    const texto = estado.sincronizando ? 'Enviando…' : enLinea ? 'En línea' : 'Sin señal';
    const u = estado.sesion.usuario;

    return '<header class="barra" id="barra"><div class="barra-interior">'
      + (estado.pantalla === 'inicio'
        ? '<span class="marca" aria-hidden="true">' + ICONOS.marca + '</span>'
          + '<div class="quien"><strong>Terreno</strong><span>' + esc(u.organizacion) + '</span></div>'
        : '<button type="button" class="volver" data-accion="inicio" aria-label="Volver al inicio">' + ICONOS.volver + '</button>'
          + '<div class="quien"><strong>' + esc(tituloPantalla()) + '</strong><span>' + esc(u.nombre) + ' · ' + esc(u.rol_nombre) + '</span></div>')
      + '<button type="button" class="red ' + (enLinea ? 'en-linea' : 'sin-senal') + (rechazadas ? ' con-rechazos' : '') + '" data-accion="pendientes"'
      + ' aria-label="' + texto + '. ' + total + ' registro(s) en el teléfono">'
      + '<span class="punto" aria-hidden="true"></span><span>' + texto + '</span>'
      + (total ? '<span class="contador">' + total + '</span>' : '')
      + '</button></div></header>';
  }

  function refrescarCabecera() {
    const barra = document.getElementById('barra');
    if (barra) barra.outerHTML = cabecera();
  }

  const chips = (grupo, opciones, actual, clase = '') => '<div class="chips ' + clase + '" role="group">'
    + opciones.map(([valor, texto]) => {
      const activo = String(valor) === String(actual ?? '');
      return '<button type="button" class="chip' + (activo ? ' activo' : '') + '" data-accion="elegir" data-grupo="' + grupo
        + '" data-valor="' + esc(valor) + '" aria-pressed="' + activo + '">' + texto + '</button>';
    }).join('') + '</div>';

  const baldosa = (grupo, valor, actual, titulo, detalle, deshabilitada = false) => {
    const activo = String(valor) === String(actual ?? '');
    return '<button type="button" class="baldosa' + (activo ? ' activo' : '') + '" data-accion="elegir" data-grupo="' + grupo
      + '" data-valor="' + esc(valor) + '" aria-pressed="' + activo + '"' + (deshabilitada ? ' disabled' : '') + '>'
      + '<strong>' + esc(titulo) + '</strong><span>' + esc(detalle) + '</span></button>';
  };

  function tarjetaAnimal(a) {
    const corral = corralPorId(a.corral_id);
    const enCarencia = a.carencia_hasta && a.carencia_hasta > hoy();
    const extra = [a.raza, a.eid ? 'EID ' + a.eid : '', a.diio && a.interno ? 'Interno ' + a.interno : ''].filter(Boolean);
    return '<div class="animal' + (enCarencia ? ' en-carencia' : '') + '">'
      + '<div class="animal-cabeza"><div class="ident">' + esc(nombreAnimal(a)) + '</div>'
      + '<span class="etiqueta">' + esc(legible(a.categoria)) + '</span></div>'
      + '<dl class="animal-datos">'
      + '<div><dt>Corral</dt><dd>' + esc(corral ? corral.nombre : '—') + '</dd></div>'
      + '<div><dt>Último peso</dt><dd>' + (a.peso ? fmt(a.peso, 1) + ' kg' : '—') + '</dd></div>'
      + '<div><dt>Pesado</dt><dd>' + (a.fecha_peso ? fechaCorta(a.fecha_peso).slice(0, 6) + a.fecha_peso.slice(2, 4) : '—') + '</dd></div>'
      + '</dl>'
      + (extra.length ? '<div class="chico">' + esc(extra.join(' · ')) + '</div>' : '')
      + (enCarencia ? '<div class="alerta roja">En carencia hasta el ' + fechaCorta(a.carencia_hasta) + ': no apto para faena</div>' : '')
      + (a.parto_probable ? '<div class="alerta">Parto probable: ' + fechaCorta(a.parto_probable) + '</div>' : '')
      + (a.secado ? '<div class="alerta">Secada el ' + fechaCorta(a.secado) + '</div>' : '')
      + '</div>';
  }

  function pintarResultados(texto) {
    const contenedor = document.getElementById('resultados');
    if (!contenedor) return;
    const encontrados = buscarAnimales(texto, filtroIdentificacion());
    if (!normalizar(texto)) {
      contenedor.innerHTML = '';
    } else if (!encontrados.length) {
      contenedor.innerHTML = '<p class="vacio">No coincide con ningún animal de los datos del teléfono.</p>';
    } else {
      contenedor.innerHTML = encontrados.map((a) => '<button type="button" class="resultado" data-accion="elegir-animal" data-id="' + a.id + '">'
        + '<strong>' + esc(nombreAnimal(a)) + '</strong>'
        + '<span>' + esc(legible(a.categoria)) + ' · ' + esc(nombreCorral(a.corral_id)) + (a.diio && a.interno ? ' · ' + esc(a.interno) : '') + '</span>'
        + '</button>').join('');
    }
  }

  // Parir y secarse son cosas de hembras: no tiene sentido ofrecer el resto.
  const filtroIdentificacion = () => (['PARTO', 'SECADO'].includes(estado.ctx.tipo) ? (a) => a.sexo === 'HEMBRA' : null);

  /* ---------- Pantallas ---------- */

  const VISTAS = {
    cargando: () => '<main class="pantalla"><p class="nota">Cargando…</p></main>',

    ingreso() {
      const { error, organizaciones, email = '' } = estado.ctx;
      return '<main class="pantalla ingreso">'
        + '<div class="emblema" aria-hidden="true">' + ICONOS.marca + '</div><h1>Terreno</h1>'
        + '<p class="nota">Gestión ganadera en la manga y el corral, con o sin señal.</p>'
        + (!navigator.onLine ? '<div class="alerta roja">Para ingresar se necesita señal. Después se puede trabajar sin ella.</div>' : '')
        + (error ? '<div class="alerta roja">' + esc(error) + '</div>' : '')
        + '<form data-envio="ingreso">'
        + '<label for="email">Correo</label><input id="email" name="email" type="email" class="entrada" value="' + esc(email) + '" required autocomplete="username" autocapitalize="off">'
        + '<label for="password">Contraseña</label><input id="password" name="password" type="password" class="entrada" autocomplete="current-password"' + (organizaciones ? ' placeholder="Ya ingresada"' : ' required') + '>'
        + (organizaciones
          ? '<label for="org">Organización</label><select id="org" name="organizacion_id" class="entrada">'
            + organizaciones.map((o) => '<option value="' + o.id + '">' + esc(o.nombre) + ' — ' + esc(o.rol) + '</option>').join('') + '</select>'
          : '')
        + '<button class="boton principal" type="submit">Entrar</button>'
        + '</form></main>';
    },

    inicio() {
      const operaciones = estado.sesion.operaciones || [];
      const catalogo = estado.catalogo;
      const u = estado.sesion.usuario;
      const tareas = Object.entries(OPERACIONES).filter(([tipo]) => operaciones.includes(tipo));
      const hora = new Date().getHours();
      const saludo = hora < 12 ? 'Buenos días' : hora < 20 ? 'Buenas tardes' : 'Buenas noches';
      const mias = estado.cola.filter(esMia);
      const rechazadas = mias.filter((o) => o.estado === 'RECHAZADA').length;

      return '<main class="pantalla inicio">'
        + '<section class="saludo"><p>' + saludo + ',</p><h1>' + esc(u.nombre.split(' ')[0]) + '</h1>'
        + '<span class="rol">' + esc(u.rol_nombre) + '</span></section>'
        + (!catalogo ? '<div class="alerta roja">Faltan los datos del campo. Conectate a internet una vez para descargarlos.</div>' : '')
        + '<h2>¿Qué vas a registrar?</h2>'
        + '<div class="tareas">' + tareas.map(([tipo, o]) => '<button type="button" class="tarea tarea-' + tipo.toLowerCase() + '" data-accion="empezar" data-tipo="' + tipo + '">'
          + '<span class="icono">' + o.icono + '</span>'
          + '<span class="texto"><span class="titulo">' + o.titulo + '</span><span class="detalle">'
          + (tipo === 'VALIDACION' && catalogo ? porValidar().length + ' pendiente(s) de firma' : o.detalle) + '</span></span>'
          + '<span class="flecha">' + ICONOS.ir + '</span></button>').join('') + '</div>'
        + '<section class="tarjeta-estado">'
        + '<div class="cifras">'
        + '<div><strong>' + (catalogo ? fmt(catalogo.animales.length) : '—') + '</strong><span>animales</span></div>'
        + '<div class="' + (rechazadas ? 'con-alerta' : '') + '"><strong>' + mias.length + '</strong><span>' + (rechazadas ? 'con problemas' : 'por enviar') + '</span></div>'
        + '<div><strong>' + (catalogo ? catalogo.generado_en.slice(11, 16) : '—') + '</strong><span>' + (catalogo ? 'datos del ' + fechaCorta(catalogo.generado_en).slice(0, 5) : 'sin datos') + '</span></div>'
        + '</div>'
        + '<button type="button" class="boton secundario" data-accion="sincronizar"' + (estado.sincronizando ? ' disabled' : '') + '>'
        + ICONOS.sincronizar + (estado.sincronizando ? 'Sincronizando…' : 'Sincronizar ahora') + '</button>'
        + '</section>'
        + '<button type="button" class="boton enlace" data-accion="salir">Cerrar sesión</button>'
        + '</main>';
    },

    identificar() {
      const { tipo, lista = [] } = estado.ctx;
      const pregunta = { PARTO: '¿Qué vaca parió?', TRASLADO: 'Leé los animales a mover' }[tipo] || '¿Qué animal?';
      return '<main class="pantalla">'
        + '<form data-envio="identificar" autocomplete="off">'
        + '<label for="ident">' + pregunta + '</label>'
        + '<input id="ident" name="ident" class="entrada-grande" data-buscar autofocus autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="search" placeholder="DIIO, EID o interno">'
        + '<p class="nota">Con lector de chip (EID) el número entra solo al acercarlo.</p>'
        + '</form>'
        + '<div id="resultados" class="resultados" aria-live="polite"></div>'
        + (tipo === 'TRASLADO'
          ? '<section class="lista-traslado"><h2>Para mover (' + lista.length + ')</h2>'
            + (lista.length
              ? '<ul>' + lista.map((id) => {
                const a = animalPorId(id);
                return '<li><span>' + esc(nombreAnimal(a)) + ' <small>' + esc(nombreCorral(a?.corral_id)) + '</small></span>'
                  + '<button type="button" class="quitar" data-accion="quitar-lista" data-id="' + id + '" aria-label="Quitar ' + esc(nombreAnimal(a)) + '">✕</button></li>';
              }).join('') + '</ul>'
              : '<p class="nota">Todavía no agregaste animales.</p>')
            + '<button type="button" class="boton principal" data-accion="elegir-destino"' + (lista.length ? '' : ' disabled') + '>Elegir corral de destino</button></section>'
          : '')
        + (tipo === 'TRATAMIENTO' ? '<button type="button" class="boton" data-accion="corral-completo">Tratar un corral completo</button>' : '')
        + '</main>';
    },

    pesaje() {
      const { animal, hito = 'CONTROL' } = estado.ctx;
      return '<main class="pantalla">' + tarjetaAnimal(animal)
        + '<form data-envio="pesaje" autocomplete="off">'
        + '<label for="peso">Peso en kilos</label>'
        + '<input id="peso" name="peso" class="entrada-grande numero" inputmode="decimal" autofocus placeholder="0">'
        + '<label>Hito</label>' + chips('hito', HITOS, hito)
        + '<button class="boton principal" type="submit">Guardar pesaje</button>'
        + '</form></main>';
    },

    parto() {
      const { animal, sexo } = estado.ctx;
      return '<main class="pantalla">' + tarjetaAnimal(animal)
        + '<form data-envio="parto" autocomplete="off">'
        /* Enter dentro del formulario envía el primer botón HABILITADO, así que
           uno deshabilitado no alcanza: el navegador salteaba al «parto exitoso».
           Este primero está habilitado y no hace nada más que quedarse quieto. */
        + '<button type="submit" hidden aria-hidden="true" data-sin-efecto></button>'
        + '<fieldset class="ternero"><legend>Ternero</legend>'
        + chips('sexo', [['HEMBRA', '♀ Hembra'], ['MACHO', '♂ Macho']], sexo, 'dos')
        + '<label for="peso-ternero">Peso al nacer (kg)</label>'
        + '<input id="peso-ternero" name="peso" class="entrada-grande numero" inputmode="decimal" placeholder="0">'
        + '</fieldset>'
        + '<div class="resultados-parto">'
        + '<button type="submit" class="boton exito" value="EXITOSO">✔ Parto exitoso</button>'
        + '<button type="submit" class="boton ambar" value="DEBIL">⚠ Ternero débil</button>'
        + '<button type="submit" class="boton peligro" value="ABORTO">✖ Aborto</button>'
        + '</div></form></main>';
    },

    tratamiento() {
      const { animal, corral, producto_id, tipo_tratamiento = 'CURATIVO', via = 'INTRAMUSCULAR', dosis = '' } = estado.ctx;
      return '<main class="pantalla">'
        + (animal ? tarjetaAnimal(animal)
          : '<div class="animal"><div class="ident">' + esc(corral.nombre) + '</div><div class="datos">Corral completo · ' + fmt(corral.ocupacion) + ' cabezas</div></div>')
        + '<form data-envio="tratamiento" autocomplete="off">'
        + '<h2>Producto</h2><div class="baldosas">'
        + estado.catalogo.productos.map((p) => baldosa('producto_id', p.id, producto_id, p.nombre,
          'Carencia ' + p.carencia + ' d · quedan ' + fmt(p.stock) + ' ' + (UNIDADES[p.unidad] || ''), p.stock <= 0)).join('')
        + '</div>'
        + '<label for="dosis">Dosis por animal</label>'
        + '<input id="dosis" name="dosis" class="entrada-grande numero" inputmode="decimal" placeholder="0" value="' + esc(dosis) + '">'
        + '<label>Tipo</label>' + chips('tipo_tratamiento', TIPOS_TRATAMIENTO, tipo_tratamiento)
        + '<label>Vía</label>' + chips('via', VIAS, via)
        + '<label for="diagnostico">Diagnóstico (opcional)</label>'
        + '<input id="diagnostico" name="diagnostico" class="entrada" placeholder="Ej: neumonía">'
        + '<button class="boton principal" type="submit">Guardar tratamiento</button>'
        + '</form></main>';
    },

    secado() {
      const { animal } = estado.ctx;
      return '<main class="pantalla">' + tarjetaAnimal(animal)
        + '<p class="nota">Se registra con la fecha de hoy: ' + fechaCorta(hoy()) + '.</p>'
        + '<form data-envio="secado"><button class="boton principal" type="submit">Registrar secado</button></form>'
        + '</main>';
    },

    nota() {
      const { animal } = estado.ctx;
      return '<main class="pantalla">' + tarjetaAnimal(animal)
        + '<form data-envio="nota" autocomplete="off">'
        + '<label for="texto">¿Qué le pasó?</label>'
        + '<textarea id="texto" name="texto" class="entrada" rows="4" maxlength="500" autofocus placeholder="Ej: mastitis en el cuarto trasero izquierdo"></textarea>'
        + '<div class="chips">' + FRASES.map((frase) => '<button type="button" class="chip" data-accion="frase" data-valor="' + esc(frase) + '">' + frase + '</button>').join('') + '</div>'
        + '<button class="boton principal" type="submit">Guardar nota</button>'
        + '</form></main>';
    },

    alta() {
      const { sexo, categoria, corral_id } = estado.ctx;
      return '<main class="pantalla">'
        + '<form data-envio="alta" autocomplete="off">'
        + '<label for="diio">DIIO o número interno</label>'
        + '<input id="diio" name="diio" class="entrada-grande" autofocus autocapitalize="characters" autocorrect="off" spellcheck="false" placeholder="Ej: A-0143">'
        + '<h2>Sexo</h2>' + chips('sexo', SEXOS, sexo)
        + '<h2>Categoría</h2>' + chips('categoria', CATEGORIAS, categoria)
        + '<label for="peso-alta">Peso de hoy (kg)</label>'
        + '<input id="peso-alta" name="peso" class="entrada-grande numero" inputmode="decimal" placeholder="0">'
        + '<h2>Corral</h2><div class="baldosas">'
        + estado.catalogo.corrales.map((c) => baldosa('corral_id', c.id, corral_id, c.nombre,
          (c.capacidad - c.ocupacion) + ' plazas libres', c.ocupacion >= c.capacidad)).join('')
        + '</div>'
        + '<label for="nacimiento">Fecha de nacimiento (si se sabe)</label>'
        + '<input id="nacimiento" name="nacimiento" type="date" class="entrada" max="' + hoy() + '">'
        + '<button class="boton principal" type="submit">Dar de alta</button>'
        + '</form></main>';
    },

    'corrales-tratamiento'() {
      const corrales = estado.catalogo.corrales.filter((c) => c.ocupacion > 0);
      return '<main class="pantalla"><p class="pregunta">¿Qué corral?</p><div class="baldosas">'
        + corrales.map((c) => '<button type="button" class="baldosa" data-accion="tratar-corral" data-id="' + c.id + '">'
          + '<strong>' + esc(c.nombre) + '</strong><span>' + fmt(c.ocupacion) + ' cabezas</span></button>').join('')
        + '</div></main>';
    },

    alimentacion() {
      const { animal, corral_id, racion_id, unidad = 'KG', base = 'TAL_CUAL', numero_entrega = '1' } = estado.ctx;
      return '<main class="pantalla">'
        + '<form data-envio="alimentacion" autocomplete="off">'
        + (animal
          ? tarjetaAnimal(animal) + '<button type="button" class="boton" data-accion="quitar-animal">Cambiar a un corral</button>'
          : '<h2>Corral</h2><div class="baldosas">'
            + estado.catalogo.corrales.map((c) => baldosa('corral_id', c.id, corral_id, c.nombre, fmt(c.ocupacion) + ' cabezas')).join('')
            + '</div><button type="button" class="boton" data-accion="animal-individual">Entrega a un animal (cría)</button>')
        + '<h2>Ración</h2><div class="baldosas">'
        + estado.catalogo.raciones.map((r) => baldosa('racion_id', r.id, racion_id, r.nombre, fmt(r.materia_seca, 1) + '% materia seca')).join('')
        + '</div>'
        + '<label for="cantidad">Cantidad entregada</label>'
        + '<div class="cantidad"><input id="cantidad" name="cantidad" class="entrada-grande numero" inputmode="decimal" placeholder="0">'
        + chips('unidad', [['KG', 'kg'], ['T', 'ton']], unidad, 'dos') + '</div>'
        + chips('base', [['TAL_CUAL', 'Tal cual'], ['MATERIA_SECA', 'Materia seca']], base, 'dos')
        + '<label>Entrega del día</label>' + chips('numero_entrega', [['1', '1ª'], ['2', '2ª'], ['3', '3ª'], ['4', '4ª']], numero_entrega)
        + '<button class="boton principal" type="submit">Guardar entrega</button>'
        + '</form></main>';
    },

    destino() {
      const animales = estado.ctx.lista.map(animalPorId).filter(Boolean);
      return '<main class="pantalla"><p class="pregunta">¿A qué corral?</p>'
        + '<p class="nota">' + animales.length + ' animal(es): ' + esc(animales.map(nombreAnimal).join(', ')) + '</p>'
        + '<div class="baldosas">' + estado.catalogo.corrales.map((c) => {
          const entran = animales.filter((a) => a.corral_id !== c.id).length;
          const libres = c.capacidad - c.ocupacion;
          const cabe = entran > 0 && entran <= libres;
          const detalle = entran === 0 ? 'ya están acá' : libres <= 0 ? 'completo' : libres < entran ? 'solo entran ' + libres : 'quedan ' + fmt(libres) + ' plazas';
          return '<button type="button" class="baldosa" data-accion="mover" data-id="' + c.id + '"' + (cabe ? '' : ' disabled') + '>'
            + '<strong>' + esc(c.nombre) + '</strong><span>' + detalle + '</span></button>';
        }).join('') + '</div></main>';
    },

    validar() {
      const mva = estado.catalogo.mva;
      const vigente = mvaVigente();
      const lista = porValidar();
      return '<main class="pantalla">'
        + (vigente
          ? '<p class="nota">Validás como MVA N° ' + esc(mva.numero) + ' · RUT ' + esc(mva.rut)
            + '. Registro vigente hasta el ' + fechaCorta(mva.vigente_hasta) + '.</p>'
          : '<div class="alerta roja">' + (mva
            ? 'Tu registro MVA venció el ' + fechaCorta(mva.vigente_hasta) + '. Pedile al administrador que lo actualice.'
            : 'No tenés un registro MVA cargado en esta organización. Lo carga el administrador en la web, en Usuarios.') + '</div>')
        + '<p class="nota">Los tratamientos cargados en el teléfono aparecen acá después de sincronizar. '
        + 'Las validaciones se guardan aunque no haya señal.</p>'
        + (lista.length === 0
          ? '<p class="vacio">No hay tratamientos por validar. ✔</p>'
          : '<ul class="cola validar">' + lista.map((t) => '<li>'
            + '<div class="tipo">' + esc(t.producto) + ' <span>' + fechaCorta(t.fecha) + '</span></div>'
            + '<div class="resumen">' + esc(t.donde || 'Sin corral') + ' · ' + fmt(t.cabezas) + ' cabeza(s) · '
            + fmt(t.dosis, 1) + ' ' + esc(UNIDADES[t.unidad] || '') + ' por animal</div>'
            + '<div class="estado">' + esc(etiqueta(TIPOS_TRATAMIENTO, t.tipo)) + ' · ' + esc(etiqueta(VIAS, t.via)) + ' · carencia ' + t.carencia + ' d'
            + (t.diagnostico ? ' · ' + esc(t.diagnostico) : '') + (t.cargo ? ' · cargó ' + esc(t.cargo) : '') + '</div>'
            + (vigente
              ? '<div class="acciones"><button type="button" class="boton exito" data-accion="validar" data-id="' + t.id + '">Validar</button></div>'
              : '')
            + '</li>').join('') + '</ul>')
        + '</main>';
    },

    pendientes() {
      const mias = estado.cola.filter(esMia).sort((a, b) => a.registrado_en.localeCompare(b.registrado_en));
      const ajenas = estado.cola.length - mias.length;
      return '<main class="pantalla">'
        + '<p class="nota">' + (navigator.onLine
          ? 'Hay señal: lo pendiente se envía solo.'
          : 'Sin señal: todo queda guardado en el teléfono y se envía cuando vuelva.') + '</p>'
        + '<button type="button" class="boton principal" data-accion="sincronizar"' + (estado.sincronizando || !navigator.onLine ? ' disabled' : '') + '>'
        + (estado.sincronizando ? 'Enviando…' : ICONOS.sincronizar + 'Sincronizar ahora') + '</button>'
        + (mias.length === 0
          ? '<p class="vacio">No hay nada pendiente. ✔</p>'
          : '<ul class="cola">' + mias.map((op) => '<li class="' + (op.estado === 'RECHAZADA' ? 'rechazada' : '') + '">'
            + '<div class="tipo">' + (OPERACIONES[op.tipo].enCola || OPERACIONES[op.tipo].titulo) + ' <span>' + fechaHora(op.registrado_en) + '</span></div>'
            + '<div class="resumen">' + esc(op.resumen) + '</div>'
            + (op.estado === 'RECHAZADA'
              ? '<div class="motivo">✖ ' + esc(op.mensaje) + '</div><div class="acciones">'
                + '<button type="button" class="boton ' + (op.requiere_confirmacion ? 'exito' : '') + '" data-accion="reintentar" data-uuid="' + op.uuid + '">'
                + (op.requiere_confirmacion ? 'Es correcto: enviar' : 'Reintentar') + '</button>'
                + '<button type="button" class="boton peligro" data-accion="descartar" data-uuid="' + op.uuid + '">Descartar</button></div>'
              : '<div class="estado">' + (op.estado === 'ERROR' ? 'Falló el envío: se reintenta solo' : 'Por enviar') + '</div>')
            + '</li>').join('') + '</ul>')
        + (ajenas ? '<p class="nota">Hay ' + ajenas + ' registro(s) de otra persona en este teléfono: se envían cuando esa persona ingrese.</p>' : '')
        + '<p class="nota">Datos del campo actualizados el ' + (estado.catalogo ? fechaHora(estado.catalogo.generado_en) : '—') + '.</p>'
        + '</main>';
    },
  };

  function mostrar(pantalla, ctx = {}) {
    estado.pantalla = pantalla;
    estado.ctx = ctx;
    dibujar();
  }

  function dibujar() {
    app.innerHTML = cabecera() + (VISTAS[estado.pantalla] || VISTAS.inicio)();
    window.scrollTo(0, 0);
    const foco = app.querySelector('[autofocus]');
    if (foco) foco.focus({ preventScroll: true });
  }

  /* ---------- Flujo de cada tarea ---------- */

  function elegirAnimal(id) {
    const animal = animalPorId(id);
    if (!animal) return;
    const { tipo } = estado.ctx;

    if (tipo === 'TRASLADO') {
      const lista = estado.ctx.lista || [];
      if (lista.includes(id)) aviso(nombreAnimal(animal) + ' ya está en la lista.', 'error');
      return mostrar('identificar', { tipo, lista: lista.includes(id) ? lista : [...lista, id] });
    }
    if (tipo === 'ENTREGA') return mostrar('alimentacion', { ...(estado.ctx.previo || {}), animal, corral_id: null });
    if (tipo === 'TRATAMIENTO') return mostrar('tratamiento', { ...(estado.ctx.previo || {}), animal });
    if (tipo === 'SECADO') return mostrar('secado', { animal });
    if (tipo === 'NOTA') return mostrar('nota', { animal });
    mostrar(tipo === 'PARTO' ? 'parto' : 'pesaje', { animal });
  }

  async function guardarParto(animal, resultado, extra) {
    const detalle = resultado === 'ABORTO'
      ? 'Aborto'
      : (extra.ternero_debil ? 'Ternero débil' : 'Parto exitoso') + ' · ' + (extra.sexo === 'MACHO' ? 'macho' : 'hembra') + ' ' + fmt(extra.peso_nacimiento, 1) + ' kg';
    await registrar('PARTO', { vaca_id: animal.id, fecha: hoy(), resultado, ...extra }, nombreAnimal(animal) + ' · ' + detalle, () => {
      animal.parto_probable = null;
    });
    mostrar('identificar', { tipo: 'PARTO' });
  }

  async function guardarTraslado(destinoId) {
    const destino = corralPorId(destinoId);
    const moviendo = estado.ctx.lista.map(animalPorId).filter((a) => a && a.corral_id !== destino.id);
    await registrar(
      'TRASLADO',
      { destino_id: destino.id, fecha: hoy(), identificaciones: moviendo.map((a) => a.diio || a.interno || a.eid) },
      (moviendo.length === 1 ? nombreAnimal(moviendo[0]) : moviendo.length + ' animales') + ' → ' + destino.nombre,
      () => moviendo.forEach((a) => {
        const origen = corralPorId(a.corral_id);
        if (origen) origen.ocupacion -= 1;
        a.corral_id = destino.id;
        destino.ocupacion += 1;
      }),
    );
    mostrar('inicio');
  }

  function cerrarSesion() {
    const pendientes = porEnviar().length;
    confirmar({
      titulo: 'Cerrar sesión',
      texto: pendientes
        ? 'Hay ' + pendientes + ' registro(s) sin enviar. Quedan guardados en el teléfono y se envían cuando vuelvas a ingresar.'
        : 'Para volver a usar la app vas a tener que ingresar con tu correo y contraseña.',
      si: 'Cerrar sesión',
      tono: 'peligro',
      alConfirmar: async () => {
        if (navigator.onLine) api('sesion/cerrar', { metodo: 'POST' }).catch(() => {});
        estado.sesion = null;
        estado.catalogo = null;
        await BD.borrar('sesion');
        await BD.borrar('catalogo');
        mostrar('ingreso', {});
      },
    });
  }

  const ACCIONES = {
    inicio: () => mostrar('inicio'),
    pendientes: () => mostrar('pendientes'),
    sincronizar: () => sincronizar(true),
    salir: () => cerrarSesion(),

    empezar: (el) => {
      if (!estado.catalogo) return aviso('Faltan los datos del campo: conectate y sincronizá.', 'error');
      const tipo = el.dataset.tipo;
      if (tipo === 'ENTREGA') return mostrar('alimentacion', {});
      if (tipo === 'ANIMAL') return mostrar('alta', {});
      if (tipo === 'VALIDACION') return mostrar('validar', {});
      mostrar('identificar', { tipo, lista: [] });
    },

    // Opción de un grupo (hito, sexo, producto…): se marca sin redibujar, para no
    // borrar lo que ya se escribió en la pantalla.
    elegir: (el) => {
      const grupo = el.dataset.grupo;
      estado.ctx[grupo] = el.dataset.valor;
      app.querySelectorAll('[data-grupo="' + grupo + '"]').forEach((boton) => {
        const activo = boton.dataset.valor === el.dataset.valor;
        boton.classList.toggle('activo', activo);
        boton.setAttribute('aria-pressed', String(activo));
      });
    },

    'elegir-animal': (el) => elegirAnimal(Number(el.dataset.id)),

    // Frase frecuente: se suma a lo que ya haya escrito, sin borrarlo.
    frase: (el) => {
      const texto = document.getElementById('texto');
      texto.value = texto.value.trim() ? texto.value.trim() + '. ' + el.dataset.valor : el.dataset.valor;
      texto.focus();
    },
    'quitar-lista': (el) => mostrar('identificar', { ...estado.ctx, lista: estado.ctx.lista.filter((id) => id !== Number(el.dataset.id)) }),
    'elegir-destino': () => mostrar('destino', { lista: estado.ctx.lista }),
    mover: (el) => guardarTraslado(Number(el.dataset.id)),
    'corral-completo': () => mostrar('corrales-tratamiento', { previo: estado.ctx.previo }),
    'tratar-corral': (el) => mostrar('tratamiento', { ...(estado.ctx.previo || {}), corral: corralPorId(Number(el.dataset.id)) }),
    'animal-individual': () => mostrar('identificar', { tipo: 'ENTREGA', previo: { ...estado.ctx } }),
    'quitar-animal': () => mostrar('alimentacion', { ...estado.ctx, animal: null }),

    validar: (el) => {
      const t = porValidar().find((x) => x.id === Number(el.dataset.id));
      if (!t) return;
      const mva = estado.catalogo.mva;
      confirmar({
        titulo: 'Validar tratamiento',
        texto: t.producto + ' en ' + (t.donde || 'sin corral') + ', ' + fmt(t.cabezas) + ' cabeza(s), aplicado el ' + fechaCorta(t.fecha)
          + '. Queda firmado con tu registro MVA N° ' + mva.numero + '.',
        si: 'Validar',
        tono: 'exito',
        alConfirmar: () => registrar(
          'VALIDACION',
          { tratamiento_id: t.id },
          t.producto + ' · ' + (t.donde || 'sin corral') + ' · ' + fechaCorta(t.fecha),
        ).then(() => dibujar()),
      });
    },

    reintentar: async (el) => {
      const op = estado.cola.find((o) => o.uuid === el.dataset.uuid);
      if (!op) return;
      if (op.requiere_confirmacion) op.datos = { ...op.datos, confirmar: true };
      op.estado = 'PENDIENTE';
      op.mensaje = null;
      op.requiere_confirmacion = false;
      await BD.encolar(op);
      dibujar();
      sincronizar(true);
    },

    descartar: (el) => {
      const op = estado.cola.find((o) => o.uuid === el.dataset.uuid);
      if (!op) return;
      confirmar({
        titulo: 'Descartar registro',
        texto: '«' + op.resumen + '» se borra del teléfono y no se envía.',
        si: 'Descartar',
        tono: 'peligro',
        alConfirmar: async () => {
          await BD.quitar(op.uuid);
          estado.cola = estado.cola.filter((o) => o.uuid !== op.uuid);
          dibujar();
        },
      });
    },
  };

  const ENVIOS = {
    async ingreso(form) {
      const boton = form.querySelector('button[type="submit"]');
      const email = form.email.value.trim();
      const password = form.password.value || estado.ctx.password || '';
      const cuerpo = { email, password };
      if (form.organizacion_id) cuerpo.organizacion_id = Number(form.organizacion_id.value);

      boton.disabled = true;
      boton.textContent = 'Ingresando…';
      try {
        const r = await api('sesion', { metodo: 'POST', cuerpo });
        estado.sesion = { token: r.token, expira_en: r.expira_en, usuario: r.usuario, operaciones: r.operaciones };
        await BD.escribir('sesion', estado.sesion);
        await descargarCatalogo();
        mostrar('inicio');
        sincronizar();
      } catch (error) {
        if (error.status === 409) {
          return mostrar('ingreso', { email, password, organizaciones: error.datos.organizaciones, error: error.message });
        }
        mostrar('ingreso', { email, error: error.message });
      }
    },

    identificar(form) {
      const encontrados = buscarAnimales(form.ident.value, filtroIdentificacion());
      if (encontrados.length === 1) return elegirAnimal(encontrados[0].id);
      if (!encontrados.length) {
        aviso('No está en los datos del teléfono. Si es un animal nuevo, sincronizá.', 'error');
        form.ident.select();
        return;
      }
      aviso('Hay varios parecidos: tocá el correcto.', 'error');
    },

    pesaje(form) {
      const peso = numeroDe(form.peso.value);
      if (!(peso >= 10 && peso <= 1500)) return aviso('El peso va entre 10 y 1.500 kg.', 'error');
      const { animal, hito = 'CONTROL' } = estado.ctx;
      const fecha = hoy();

      const guardar = async (confirmado) => {
        await registrar(
          'PESADA',
          { animal_id: animal.id, fecha, peso_kg: peso, motivo: hito, confirmar: confirmado },
          nombreAnimal(animal) + ' · ' + fmt(peso, 1) + ' kg · ' + etiqueta(HITOS, hito),
          () => {
            if (!animal.fecha_peso || fecha >= animal.fecha_peso) {
              animal.peso = peso;
              animal.fecha_peso = fecha;
            }
          },
        );
        mostrar('identificar', { tipo: 'PESADA' });
      };

      const alerta = variacionPeso(animal, peso, fecha);
      if (alerta) {
        return confirmar({
          titulo: '⚠ Peso fuera de lo normal',
          texto: alerta + ' Revisá la balanza y la caravana.',
          si: 'El peso es correcto',
          no: 'Corregir',
          tono: 'ambar',
          alConfirmar: () => guardar(true),
        });
      }
      guardar(false);
    },

    parto(form, boton) {
      const eleccion = boton && boton.value;
      const { animal, sexo } = estado.ctx;
      if (!eleccion) return;

      if (eleccion === 'ABORTO') {
        return confirmar({
          titulo: 'Registrar aborto',
          texto: '¿Confirmás el aborto de ' + nombreAnimal(animal) + '? Queda en su historial y cuenta para la alerta de descarte.',
          si: 'Sí, fue aborto',
          tono: 'peligro',
          alConfirmar: () => guardarParto(animal, 'ABORTO', {}),
        });
      }

      const peso = numeroDe(form.peso.value);
      if (!sexo) return aviso('Marcá si el ternero es hembra o macho.', 'error');
      if (!(peso >= 10 && peso <= 90)) return aviso('El peso al nacer va entre 10 y 90 kg.', 'error');
      guardarParto(animal, 'VIVO', { sexo, peso_nacimiento: peso, ternero_debil: eleccion === 'DEBIL' });
    },

    secado(form) {
      const { animal } = estado.ctx;
      const fecha = hoy();
      registrar('SECADO', { animal_id: animal.id, fecha }, nombreAnimal(animal) + ' · secado ' + fechaCorta(fecha), () => {
        animal.secado = fecha;
      }).then(() => mostrar('identificar', { tipo: 'SECADO' }));
    },

    nota(form) {
      const { animal } = estado.ctx;
      const texto = form.texto.value.trim();
      if (texto === '') return aviso('Escribí qué le pasó al animal.', 'error');
      registrar('NOTA', { animal_id: animal.id, fecha: hoy(), texto }, nombreAnimal(animal) + ' · ' + texto)
        .then(() => mostrar('identificar', { tipo: 'NOTA' }));
    },

    alta(form) {
      const { sexo, categoria, corral_id } = estado.ctx;
      const identificacion = form.diio.value.trim().toUpperCase();
      const peso = numeroDe(form.peso.value);
      if (identificacion === '') return aviso('Falta el DIIO o el número interno.', 'error');
      if (!sexo) return aviso('Marcá el sexo.', 'error');
      if (!categoria) return aviso('Marcá la categoría.', 'error');
      if (!(peso >= 10 && peso <= 1500)) return aviso('El peso va entre 10 y 1.500 kg.', 'error');

      // Si el sistema tiene formato de DIIO y esto no lo cumple, es un número
      // interno del predio. Sin formato configurado, se toma como DIIO.
      const formato = formatoDiio();
      const esDiio = !formato || formato.test(identificacion.replace(/\s+/g, ''));
      const datos = {
        [esDiio ? 'caravana_visual' : 'numero_interno']: identificacion,
        sexo,
        categoria,
        etapa: ['TERNERA', 'TERNERO'].includes(categoria) ? 'CRIA' : 'RECRIA',
        origen: 'COMPRADO',
        fecha_ingreso: hoy(),
        peso_ingreso_kg: peso,
      };
      if (corral_id) datos.corral_id = Number(corral_id);
      if (form.nacimiento.value) datos.fecha_nacimiento = form.nacimiento.value;

      registrar('ANIMAL', datos, identificacion + ' · ' + legible(categoria) + ' · ' + fmt(peso, 1) + ' kg')
        .then(() => mostrar('inicio'));
    },

    tratamiento(form) {
      const { animal, corral, tipo_tratamiento = 'CURATIVO', via = 'INTRAMUSCULAR' } = estado.ctx;
      const producto = estado.catalogo.productos.find((p) => String(p.id) === String(estado.ctx.producto_id));
      const dosis = numeroDe(form.dosis.value);
      if (!producto) return aviso('Elegí el producto.', 'error');
      if (!(dosis > 0)) return aviso('Falta la dosis.', 'error');

      const tratados = animal ? [animal] : estado.catalogo.animales.filter((a) => a.corral_id === corral.id);
      const total = dosis * tratados.length;
      if (total > producto.stock) return aviso('No alcanza: hacen falta ' + fmt(total, 1) + ' y quedan ' + fmt(producto.stock, 1) + '.', 'error');

      const fecha = hoy();
      const datos = { insumo_id: producto.id, fecha, dosis, tipo: tipo_tratamiento, via, diagnostico: form.diagnostico.value.trim() };
      if (animal) datos.animal_id = animal.id;
      else datos.corral_id = corral.id;

      registrar(
        'TRATAMIENTO',
        datos,
        (animal ? nombreAnimal(animal) : corral.nombre) + ' · ' + producto.nombre + ' ' + fmt(dosis, 1) + ' ' + (UNIDADES[producto.unidad] || ''),
        () => {
          producto.stock -= total;
          if (producto.carencia > 0) {
            const hasta = sumarDias(fecha, producto.carencia);
            tratados.forEach((a) => { if (!a.carencia_hasta || a.carencia_hasta < hasta) a.carencia_hasta = hasta; });
          }
        },
      ).then(() => mostrar('identificar', {
        tipo: 'TRATAMIENTO',
        // El siguiente animal suele recibir lo mismo: se recuerda producto, dosis y vía.
        previo: { producto_id: producto.id, dosis: String(dosis), tipo_tratamiento, via },
      }));
    },

    alimentacion(form) {
      const { animal, unidad = 'KG', base = 'TAL_CUAL', numero_entrega = '1' } = estado.ctx;
      const corral = animal ? null : corralPorId(Number(estado.ctx.corral_id));
      const racion = estado.catalogo.raciones.find((r) => String(r.id) === String(estado.ctx.racion_id));
      const cantidad = numeroDe(form.cantidad.value);
      if (!animal && !corral) return aviso('Elegí el corral.', 'error');
      if (!racion) return aviso('Elegí la ración.', 'error');
      if (!(cantidad > 0)) return aviso('Falta la cantidad.', 'error');

      const datos = { racion_id: racion.id, fecha: hoy(), cantidad, unidad, base, numero_entrega: Number(numero_entrega) };
      if (animal) datos.animal_id = animal.id;
      else datos.corral_id = corral.id;

      const texto = fmt(cantidad, unidad === 'T' ? 2 : 0) + (unidad === 'T' ? ' t' : ' kg') + (base === 'MATERIA_SECA' ? ' MS' : '');
      registrar('ENTREGA', datos, (animal ? nombreAnimal(animal) : corral.nombre) + ' · ' + texto + ' · ' + racion.nombre)
        // El tractor sigue con el corral siguiente: se mantiene la ración y la unidad.
        .then(() => mostrar('alimentacion', { racion_id: racion.id, unidad, base, numero_entrega }));
    },
  };

  /* ---------- Eventos ---------- */

  app.addEventListener('click', (evento) => {
    const el = evento.target.closest('[data-accion]');
    if (!el || el.disabled) return;
    const accion = ACCIONES[el.dataset.accion];
    if (accion) {
      evento.preventDefault();
      accion(el, evento);
    }
  });

  app.addEventListener('submit', (evento) => {
    evento.preventDefault();
    const envio = ENVIOS[evento.target.dataset.envio];
    if (envio) envio(evento.target, evento.submitter);
  });

  app.addEventListener('input', (evento) => {
    if (evento.target.matches('[data-buscar]')) pintarResultados(evento.target.value);
  });

  // Los lectores de chip (EID) escriben el número y mandan Enter. Se envía
  // explícitamente, sin depender de cómo cada navegador trata ese Enter.
  app.addEventListener('keydown', (evento) => {
    if (evento.key === 'Enter' && evento.target.matches('[data-buscar]')) {
      evento.preventDefault();
      evento.target.form.requestSubmit();
    }
  });

  window.addEventListener('online', () => { refrescarCabecera(); sincronizar(); });
  window.addEventListener('offline', () => refrescarCabecera());
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && navigator.onLine && estado.sesion) sincronizar();
  });
  setInterval(() => { if (navigator.onLine && porEnviar().length) sincronizar(); }, 60000);

  /* ---------- Arranque ---------- */

  async function iniciar() {
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

    try {
      estado.sesion = (await BD.leer('sesion')) || null;
      estado.catalogo = (await BD.leer('catalogo')) || null;
      estado.cola = (await BD.cola()) || [];
    } catch (error) {
      app.innerHTML = '<main class="pantalla"><div class="alerta roja">Este navegador no deja guardar datos en el teléfono '
        + '(¿modo incógnito?). La app de terreno lo necesita para trabajar sin señal.</div></main>';
      return;
    }

    if (!estado.sesion) return mostrar('ingreso', {});
    mostrar('inicio');
    sincronizar();
  }

  iniciar();
})();
