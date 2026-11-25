/* ============================================================
   Lector de DIIO sin tipeo — web y app de terreno

   Agrega, junto a cada campo donde se identifica un animal, dos botones:

   · Cámara: lee el código de barras o QR de la caravana con el detector del
     navegador (BarcodeDetector). Si el navegador además trae detector de
     texto (TextDetector), lee el número impreso (OCR) y pide confirmarlo,
     porque un OCR se puede equivocar en un dígito.
   · Bastón RFID: se conecta por Bluetooth (Web Bluetooth) a bastones que
     transmiten por un puerto serie BLE (UART de Nordic, HM-10 o Microchip,
     que es lo que usan la mayoría). Cada lectura llega sola al campo activo.

   Los bastones que se emparejan como teclado («modo HID») no necesitan nada:
   escriben el número en el campo que tenga el foco, como si fuera tipeado.

   El número de un chip ISO 11784/11785 (FDX-B, HDX) son 15 dígitos: 3 del
   país o fabricante y 12 del animal (982 000123456789). Se deja sin espacios,
   que es como el sistema busca el EID.

   Requisitos del navegador: Chrome o Edge en Android o computador, y la
   página en HTTPS (o localhost). En iPhone, Safari no tiene Web Bluetooth ni
   detector de códigos: ahí sirve el bastón en modo teclado.
   ============================================================ */

(function () {
  'use strict';

  /* Campos que se mejoran: los marcados a mano y los de identificación conocidos. */
  const SELECTOR = [
    '[data-lector]',
    'input[name="identificacion"]',
    'textarea[name="identificaciones"]',
    'input[name="identificaciones"]',
    'input[name="caravana_visual"]',
    'input[name="eid"]',
    'input[data-buscar]',
    'input#diio',
  ].join(',');

  /* Servicios de puerto serie por BLE más comunes en bastones lectores. */
  const SERVICIOS_SERIE = [
    '6e400001-b5a3-f393-e0a9-e50e24dcca9e', // Nordic UART
    '0000ffe0-0000-1000-8000-00805f9b34fb', // HM-10 / CC254x
    '49535343-fe7d-4ae5-8fa9-9fafd205e455', // Microchip ISSC
    '0000fff0-0000-1000-8000-00805f9b34fb', // serie genérico
  ];

  const hayBluetooth = !!(navigator.bluetooth && window.isSecureContext);
  const hayCamara = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext
    && ('BarcodeDetector' in window || 'TextDetector' in window));

  let campoActivo = null;
  let bluetooth = null; // { dispositivo, nombre }

  /* ---------- Interpretar lo leído ---------- */

  /** Saca el número de animal de lo que mande el bastón o la cámara. */
  function interpretar(texto) {
    const limpio = String(texto || '').replace(/[\u0000-\u001f]/g, ' ').trim();
    if (!limpio) return '';
    // Chip ISO: 15 dígitos, a veces con separador entre país y número.
    const iso = limpio.match(/(\d{3})[\s_.-]?(\d{12})(?!\d)/);
    if (iso) return iso[1] + iso[2];
    // Algunos bastones anteponen el tipo de chip: «FDX-B 982...» o «HDX:...».
    const sinPrefijo = limpio.replace(/^(FDX-?B|FDX|HDX|EID|RFID|TAG)[\s:=-]*/i, '');
    // Lo demás: el bloque alfanumérico más largo que tenga dígitos.
    const bloques = sinPrefijo.toUpperCase().match(/[A-Z0-9][A-Z0-9-]{2,}/g) || [];
    const conDigitos = bloques.filter((b) => /\d{3,}/.test(b)).sort((a, b) => b.length - a.length);
    return conDigitos[0] || '';
  }

  /** Deja el número en el campo: en una lista se agrega; en un campo simple, reemplaza. */
  function entregar(campo, numero) {
    if (!campo || !numero) return;
    if (campo.tagName === 'TEXTAREA') {
      const ya = campo.value.split(/[\s,;]+/).filter(Boolean);
      if (ya.includes(numero)) {
        avisar(numero + ' ya estaba en la lista.');
        return;
      }
      campo.value = (campo.value.trim() ? campo.value.trim() + '\n' : '') + numero;
    } else {
      campo.value = numero;
    }
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    campo.dispatchEvent(new Event('change', { bubbles: true }));
    campo.focus();
    sonar();
    avisar('Leído: ' + numero);
    // En el buscador de la app de terreno, leer equivale a apretar Enter: busca
    // el animal y sigue, sin tocar la pantalla (útil en la manga con guantes).
    if (campo.matches('[data-buscar]') && campo.form && campo.form.requestSubmit) {
      campo.form.requestSubmit();
    }
  }

  /* ---------- Avisos: sonido, vibración y un cartel breve ---------- */

  function sonar() {
    try {
      if (navigator.vibrate) navigator.vibrate(60);
      const Contexto = window.AudioContext || window.webkitAudioContext;
      if (!Contexto) return;
      const ctx = new Contexto();
      const osc = ctx.createOscillator();
      osc.frequency.value = 1800;
      osc.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.08);
      osc.onended = () => ctx.close();
    } catch (e) { /* sin sonido no pasa nada */ }
  }

  let cartel = null;
  let temporizador = null;
  function avisar(texto, error) {
    if (!cartel) {
      cartel = document.createElement('div');
      cartel.className = 'lector-cartel';
      cartel.setAttribute('role', 'status');
      cartel.setAttribute('aria-live', 'polite');
      document.body.appendChild(cartel);
    }
    cartel.textContent = texto;
    cartel.classList.toggle('error', !!error);
    cartel.hidden = false;
    clearTimeout(temporizador);
    temporizador = setTimeout(() => { cartel.hidden = true; }, 3500);
  }

  /* ---------- Bastón RFID por Bluetooth ---------- */

  async function conectarBaston(campo) {
    campoActivo = campo;
    if (bluetooth && bluetooth.dispositivo.gatt.connected) {
      avisar('Bastón ' + bluetooth.nombre + ' conectado: leé la caravana.');
      return;
    }
    let dispositivo;
    try {
      dispositivo = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICIOS_SERIE });
    } catch (e) {
      if (e && e.name !== 'NotFoundError') avisar('No se pudo abrir Bluetooth: ' + e.message, true);
      return; // NotFoundError = la persona cerró el selector
    }
    try {
      avisar('Conectando con ' + (dispositivo.name || 'el bastón') + '…');
      const servidor = await dispositivo.gatt.connect();
      let escuchando = 0;
      for (const uuid of SERVICIOS_SERIE) {
        let servicio;
        try { servicio = await servidor.getPrimaryService(uuid); } catch (e) { continue; }
        for (const caracteristica of await servicio.getCharacteristics()) {
          if (!caracteristica.properties.notify && !caracteristica.properties.indicate) continue;
          await caracteristica.startNotifications();
          caracteristica.addEventListener('characteristicvaluechanged', recibirBluetooth);
          escuchando++;
        }
      }
      if (escuchando === 0) {
        dispositivo.gatt.disconnect();
        avisar('Ese equipo no transmite lecturas por un puerto serie conocido. Si el bastón tiene «modo teclado» (HID), usalo así: escribe solo en el campo.', true);
        return;
      }
      bluetooth = { dispositivo: dispositivo, nombre: dispositivo.name || 'RFID' };
      dispositivo.addEventListener('gattserverdisconnected', () => {
        bluetooth = null;
        actualizarBotones();
        avisar('Se desconectó el bastón.', true);
      });
      actualizarBotones();
      avisar('Bastón ' + bluetooth.nombre + ' conectado: leé la caravana.');
    } catch (e) {
      avisar('No se pudo conectar el bastón: ' + e.message, true);
    }
  }

  /* El bastón puede mandar el número partido en varios paquetes: se junta hasta
     un fin de línea o hasta que deja de llegar por un momento. */
  let bufferBt = '';
  let esperaBt = null;
  const decodificador = new TextDecoder();
  function recibirBluetooth(evento) {
    bufferBt += decodificador.decode(evento.target.value);
    clearTimeout(esperaBt);
    if (/[\r\n]/.test(bufferBt)) {
      procesarBuffer();
    } else {
      esperaBt = setTimeout(procesarBuffer, 200);
    }
  }
  function procesarBuffer() {
    const lineas = bufferBt.split(/[\r\n]+/);
    bufferBt = '';
    for (const linea of lineas) {
      const numero = interpretar(linea);
      if (numero) entregar(campoActivo || document.activeElement, numero);
    }
  }

  /* ---------- Cámara: código de barras y OCR ---------- */

  async function abrirCamara(campo) {
    campoActivo = campo;
    const capa = document.createElement('div');
    capa.className = 'lector-capa';
    capa.innerHTML = '<div class="lector-caja" role="dialog" aria-modal="true" aria-label="Leer caravana con la cámara">'
      + '<video playsinline muted></video>'
      + '<p class="lector-estado">Apuntá al código o al número de la caravana…</p>'
      + '<div class="lector-acciones"><button type="button" class="lector-usar" hidden>Usar</button>'
      + '<button type="button" class="lector-cerrar">Cancelar</button></div></div>';
    document.body.appendChild(capa);
    const video = capa.querySelector('video');
    const estado = capa.querySelector('.lector-estado');
    const usar = capa.querySelector('.lector-usar');
    let flujo = null;
    let activo = true;
    let candidato = '';

    const cerrar = () => {
      activo = false;
      if (flujo) flujo.getTracks().forEach((t) => t.stop());
      capa.remove();
      document.removeEventListener('keydown', alEscape);
      if (campo) campo.focus();
    };
    const alEscape = (e) => { if (e.key === 'Escape') cerrar(); };
    document.addEventListener('keydown', alEscape);
    capa.querySelector('.lector-cerrar').addEventListener('click', cerrar);
    usar.addEventListener('click', () => { entregar(campo, candidato); cerrar(); });

    try {
      flujo = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    } catch (e) {
      estado.textContent = 'No se pudo usar la cámara: ' + (e.name === 'NotAllowedError' ? 'falta el permiso.' : e.message);
      return;
    }
    video.srcObject = flujo;
    await video.play().catch(() => {});

    let codigos = null;
    if ('BarcodeDetector' in window) {
      try {
        const formatos = await window.BarcodeDetector.getSupportedFormats();
        codigos = new window.BarcodeDetector({ formats: formatos });
      } catch (e) { codigos = null; }
    }
    let textos = null;
    if ('TextDetector' in window) {
      try { textos = new window.TextDetector(); } catch (e) { textos = null; }
    }

    const mirar = async () => {
      if (!activo) return;
      if (video.readyState >= 2) {
        try {
          // 1) Código de barras o QR: es exacto, se usa directo.
          if (codigos) {
            const hallados = await codigos.detect(video);
            const numero = hallados.length ? interpretar(hallados[0].rawValue) : '';
            if (numero) {
              entregar(campo, numero);
              cerrar();
              return;
            }
          }
          // 2) Número impreso (OCR): se muestra y se confirma.
          if (textos) {
            const bloques = await textos.detect(video);
            const numero = interpretar(bloques.map((b) => b.rawValue).join(' '));
            if (numero && numero !== candidato) {
              candidato = numero;
              estado.textContent = 'Leí «' + numero + '». ¿Es correcto?';
              usar.hidden = false;
            }
          }
        } catch (e) { /* un cuadro que falla no corta la lectura */ }
      }
      setTimeout(mirar, 250);
    };
    mirar();
  }

  /* ---------- Botones junto a cada campo ---------- */

  const botones = [];

  function actualizarBotones() {
    for (const b of botones) {
      b.classList.toggle('conectado', !!bluetooth);
      b.title = bluetooth ? 'Bastón ' + bluetooth.nombre + ' conectado' : 'Conectar bastón RFID por Bluetooth';
    }
  }

  function mejorar(campo) {
    if (campo.dataset.lectorListo || campo.type === 'hidden' || campo.dataset.lector === 'no') return;
    campo.dataset.lectorListo = '1';
    campo.addEventListener('focus', () => { campoActivo = campo; });
    if (!hayBluetooth && !hayCamara) return;

    const grupo = document.createElement('div');
    grupo.className = 'lector-grupo';
    if (hayCamara) {
      const camara = document.createElement('button');
      camara.type = 'button';
      camara.className = 'lector-boton';
      camara.textContent = 'Cámara';
      camara.title = 'Leer la caravana con la cámara';
      camara.addEventListener('click', () => abrirCamara(campo));
      grupo.appendChild(camara);
    }
    if (hayBluetooth) {
      const baston = document.createElement('button');
      baston.type = 'button';
      baston.className = 'lector-boton lector-baston';
      baston.textContent = 'Bastón RFID';
      baston.addEventListener('click', () => conectarBaston(campo));
      botones.push(baston);
      grupo.appendChild(baston);
    }
    campo.insertAdjacentElement('afterend', grupo);
    actualizarBotones();
  }

  function recorrer(raiz) {
    if (raiz.matches && raiz.matches(SELECTOR)) mejorar(raiz);
    if (raiz.querySelectorAll) raiz.querySelectorAll(SELECTOR).forEach(mejorar);
  }

  function estilos() {
    const css = document.createElement('style');
    css.textContent = ''
      + '.lector-grupo{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}'
      + '.lector-boton{font:inherit;font-size:13px;padding:6px 10px;border-radius:8px;border:1px solid #b9c7bd;background:#f3f7f4;color:#183a26;cursor:pointer}'
      + '.lector-boton:hover{background:#e4efe7}'
      + '.lector-baston.conectado{background:#15703a;color:#fff;border-color:#15703a}'
      + '.lector-capa{position:fixed;inset:0;background:rgba(0,0,0,.78);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px}'
      + '.lector-caja{background:#fff;border-radius:14px;padding:12px;max-width:520px;width:100%}'
      + '.lector-caja video{width:100%;border-radius:10px;background:#000;max-height:60vh;object-fit:cover}'
      + '.lector-estado{margin:10px 2px;color:#1c2b20}'
      + '.lector-acciones{display:flex;gap:8px;justify-content:flex-end}'
      + '.lector-acciones button{font:inherit;padding:10px 16px;border-radius:8px;border:1px solid #b9c7bd;background:#f3f7f4;cursor:pointer}'
      + '.lector-usar{background:#15703a!important;color:#fff;border-color:#15703a!important}'
      + '.lector-cartel{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#183a26;color:#fff;padding:10px 16px;border-radius:10px;z-index:10000;max-width:90vw;box-shadow:0 6px 20px rgba(0,0,0,.25)}'
      + '.lector-cartel.error{background:#8a1c14}';
    document.head.appendChild(css);
  }

  function iniciar() {
    estilos();
    recorrer(document);
    // La app de terreno dibuja sus pantallas con JavaScript: se mejoran al aparecer.
    new MutationObserver((cambios) => {
      for (const cambio of cambios) {
        cambio.addedNodes.forEach((nodo) => { if (nodo.nodeType === 1) recorrer(nodo); });
      }
    }).observe(document.body, { childList: true, subtree: true });
  }

  // Para las pruebas: la interpretación es lo único que no depende del hardware.
  window.lectorDiio = { interpretar: interpretar };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();
